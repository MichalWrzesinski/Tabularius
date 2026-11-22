<?php

declare(strict_types=1);

namespace App\Module\Interpretation\Job;

use App\Module\Interpretation\InterpretationContext;
use App\Module\Interpretation\InterpretedPlan;
use App\Module\Interpretation\Interpreter;
use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Business\DataSourceException;
use App\Module\Report\Execution\Scope;
use App\Module\Report\Period\DateRange;
use App\Module\Report\Profile\IntegrationProfile;
use App\Module\Shared\Refusal;
use App\Module\Ticket\Job\ExecutePlan;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStatus;
use App\Module\Ticket\TicketStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Received mail ticket → plan v1 → route (tickets.md):
 * - out of catalog (payroll, deleting data…) → `rejected`;
 * - blocking issues (missing period, ambiguous customer, several operations…) → `needs_intervention`
 *   (`clarification_needed`; clarification mail comes in increment 5);
 * - a complete plan is validated in the owner's scope like any other: a customer outside it → `rejected`
 *   (`out_of_scope`), other refusals → `needs_intervention`; a valid plan waits for the owner's confirmation in
 *   production (ID-2) or is queued in demo (ID-DEMO-1).
 * The interpreter sees only the message text and trusted context; its output is data, validated like any input.
 */
#[AsMessageHandler]
final class InterpretTicketHandler
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketService $service,
        private readonly Interpreter $interpreter,
        private readonly BusinessApi $business,
        private readonly IntegrationProfile $profile,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly \App\Module\Notification\Outbox $outbox,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode = 'demo',
    ) {
    }

    public function __invoke(InterpretTicket $job): void
    {
        $claim = $this->tickets->connection()->transactional(function () use ($job): ?array {
            $ticket = $this->tickets->lock($job->ticketId);
            $resumable = null !== $ticket && TicketStatus::Interpreting === $ticket['status'] && 0 === $ticket['plan_version'];
            if (null === $ticket || (TicketStatus::Received !== $ticket['status'] && !$resumable) || null === $ticket['owner_account_id']) {
                return null; // stale or duplicate job
            }
            $rep = $this->tickets->connection()->fetchOne("SELECT rep_code FROM account WHERE id = ? AND status = 'active' AND role = 'rep'", [$ticket['owner_account_id']]);
            if (!\is_string($rep)) {
                return null; // the executor and identity rules handle inactive owners
            }
            if (TicketStatus::Received === $ticket['status']) {
                $this->tickets->setStatus($job->ticketId, TicketStatus::Interpreting, null, $this->clock->now());
            }

            return ['rep' => $rep, 'reference' => DateRange::date($ticket['reference_date']), 'source' => $ticket['source']];
        });
        if (null === $claim) {
            return;
        }

        $text = $this->text($job->ticketId);
        try {
            $context = new InterpretationContext($this->business->customersOf($claim['rep']), $this->business->manufacturers(), $this->profile->categories, $claim['reference']);
        } catch (DataSourceException $e) {
            $this->logger->warning('Interpretation context unavailable', ['ticket' => $job->ticketId, 'error' => $e->getMessage()]);
            $this->finish($job->ticketId, null, TicketStatus::NeedsIntervention, 'interpretation_unavailable', 'System danych niedostępny podczas interpretacji; parametry może ustawić administrator');

            return;
        }
        if (null === $text) {
            $this->finish($job->ticketId, null, TicketStatus::NeedsIntervention, 'interpretation_unavailable', 'Brak tekstu wiadomości do interpretacji');

            return;
        }

        $plan = $this->interpreter->interpret($text, $context);
        if ('out_of_catalog' === $plan->intent) {
            $this->finish($job->ticketId, $plan, TicketStatus::Rejected, 'out_of_catalog', 'Prośba spoza katalogu operacji; nic nie wykonano');

            return;
        }
        if ([] !== $plan->blockingIssues() || null === $plan->operation) {
            $this->finish($job->ticketId, $plan, TicketStatus::NeedsIntervention, 'clarification_needed', 'Prośba wymaga uzupełnienia; potrzebna decyzja administratora');

            return;
        }
        try {
            $this->service->check($plan->operation, $plan->parameters, new Scope($claim['rep']), $claim['reference']);
        } catch (Refusal $refusal) {
            $checked = new InterpretedPlan($plan->intent, $plan->operation, $plan->parameters, [...$plan->issues, $refusal->reason]);
            'out_of_scope' === $refusal->reason
                ? $this->finish($job->ticketId, $checked, TicketStatus::Rejected, 'out_of_scope', 'Klient spoza zakresu pracownika; nic nie ujawniono')
                : $this->finish($job->ticketId, $checked, TicketStatus::NeedsIntervention, 'clarification_needed', 'Parametry wymagają poprawy; potrzebna decyzja administratora');

            return;
        }
        $confirm = 'mail' === $claim['source'] && 'demo' !== $this->mode;
        $this->finish(
            $job->ticketId,
            $plan,
            $confirm ? TicketStatus::AwaitingConfirmation : TicketStatus::Queued,
            null,
            $confirm ? 'Czeka na potwierdzenie pracownika po zalogowaniu' : 'Plan v1 w kolejce (jedno wykonanie na wersję)',
        );
    }

    private function finish(string $ticketId, ?InterpretedPlan $plan, TicketStatus $status, ?string $reason, string $text): void
    {
        $this->tickets->connection()->transactional(function () use ($ticketId, $plan, $status, $reason, $text): void {
            $ticket = $this->tickets->lock($ticketId);
            if (null === $ticket || TicketStatus::Interpreting !== $ticket['status'] || 0 !== $ticket['plan_version']) {
                return; // changed meanwhile (e.g. rejected by the administrator)
            }
            $now = $this->clock->now();
            if (null !== $plan) {
                $this->tickets->addPlan($ticketId, 1, 'system', $this->interpreter->version(), $now, $plan->operation, $plan->parameters, $plan->issues);
                $this->tickets->event($ticketId, 'system', 'Interpretacja', \sprintf('Plan v1 (%s)%s', $this->interpreter->version(), [] === $plan->issues ? '' : '; do wyjaśnienia: '.implode(', ', $plan->issues)), $now);
                if (\in_array('recipient_change_requested', $plan->issues, true)) {
                    $this->tickets->event($ticketId, 'system', 'Interpretacja', 'Prośba o zmianę odbiorcy zignorowana; wynik trafia zwykłą drogą', $now);
                }
            }
            $this->service->move($ticketId, TicketStatus::Interpreting, $status, $now);
            if (null !== $reason) {
                $this->tickets->setStatus($ticketId, $status, $reason, $now);
            }
            $this->tickets->event($ticketId, 'system', 'System', $text, $now);
            if (TicketStatus::Queued === $status) {
                $this->bus->dispatch(new ExecutePlan($ticketId, 1));
            } elseif (TicketStatus::AwaitingConfirmation === $status) {
                $this->outbox->enqueue($ticketId, 'confirmation_request', $ticketId.':confirm:1');
            } elseif (TicketStatus::Rejected === $status) {
                $this->outbox->enqueue($ticketId, 'refused', $ticketId.':refused', ['reason' => $reason]);
            }
        });
    }

    /** Subject and the new part of the body (quoted history already stripped at ingestion). */
    private function text(string $ticketId): ?string
    {
        $row = $this->tickets->connection()->fetchAssociative('SELECT subject, text_body FROM mail_message WHERE ticket_id = ? ORDER BY received_at LIMIT 1', [$ticketId]);
        if (false === $row) {
            return null;
        }
        $subject = \is_string($row['subject']) ? (string) preg_replace('/^\s*((re|odp|fwd|pd|fw)\s*:\s*)+/i', '', $row['subject']) : '';
        $text = trim($subject."\n".(\is_string($row['text_body']) ? $row['text_body'] : ''));

        return '' === $text ? null : $text;
    }
}
