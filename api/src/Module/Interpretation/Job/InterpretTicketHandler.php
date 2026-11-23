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
 * Received mail ticket (or a reply in its thread) → next plan version → route (tickets.md):
 * - out of catalog (payroll, deleting data…) → `rejected`;
 * - blocking issues (missing period, ambiguous customer, several operations…) or a correctable period refusal →
 *   `needs_clarification` with a templated question (TKT-CLAR, at most 3 rounds, then `needs_intervention`);
 *   manual tickets and codes the requester cannot answer go to the administrator (`needs_intervention`);
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
        private readonly \App\Module\Interpretation\Guard\RequestScreen $screen,
        private readonly \App\Module\Interpretation\Guard\PlanGuard $guard,
        private readonly \App\Module\Audit\AuditLog $audit,
        private readonly \App\Module\Interpretation\Clarification $clarification,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode = 'demo',
    ) {
    }

    public function __invoke(InterpretTicket $job): void
    {
        $claim = $this->tickets->connection()->transactional(function () use ($job): ?array {
            $ticket = $this->tickets->lock($job->ticketId);
            $fresh = null !== $ticket && TicketStatus::Received === $ticket['status'] && 0 === $job->afterVersion;
            // A reply moved the ticket to `interpreting` at ingestion; a crash may leave a first job there too.
            $resumable = null !== $ticket && TicketStatus::Interpreting === $ticket['status'];
            if (null === $ticket || (!$fresh && !$resumable) || $job->afterVersion !== $ticket['plan_version'] || null === $ticket['owner_account_id']) {
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
            $this->finish($job, null, TicketStatus::NeedsIntervention, 'interpretation_unavailable', 'System danych niedostępny podczas interpretacji; parametry może ustawić administrator');

            return;
        }
        if (null === $text) {
            $this->finish($job, null, TicketStatus::NeedsIntervention, 'interpretation_unavailable', 'Brak tekstu wiadomości do interpretacji');

            return;
        }

        // Business-scope guard, layer 1 (ADR 0005): an instruction outside the concept never reaches the interpreter.
        $category = $this->screen->screen($text);
        if (null !== $category) {
            $this->audit->record('system', 'security.request_refused', 'ticket:'.$job->ticketId, ['category' => $category]);
            $this->finish($job, new InterpretedPlan('out_of_catalog', null, [], ['out_of_catalog']), TicketStatus::Rejected, 'outside_business_scope', \sprintf('Prośba poza zakresem usługi (%s); nic nie wykonano, interpreter nie był użyty', $category));

            return;
        }

        try {
            $plan = $this->interpreter->interpret($text, $context);
        } catch (\App\Module\Interpretation\OpenAi\AiUnavailableException $e) {
            // CP-005 / AC-6: the ticket is kept for manual handling, never reported as success.
            $this->logger->warning('Interpretation unavailable', ['ticket' => $job->ticketId, 'reason' => $e->reason, 'error' => $e->getMessage()]);
            $this->finish($job, null, TicketStatus::NeedsIntervention, $e->reason, 'ai_limit' === $e->reason
                ? 'Limit interpretacji AI wyczerpany; parametry może ustawić administrator'
                : 'Błąd dostawcy AI; zgłoszenie zachowane, parametry może ustawić administrator');

            return;
        }
        // Layer 2: whatever the interpreter returned, only closed typed values in the owner's scope may be routed.
        $violations = $this->guard->violations($plan, $context);
        if (['customer_out_of_scope'] === $violations) {
            // A request for someone else's customer is an ordinary refusal (tickets.md), not a manipulated output.
            $this->audit->record('system', 'security.scope_refused', 'ticket:'.$job->ticketId, ['interpreter' => $this->interpreter->version()]);
            $this->finish($job, new InterpretedPlan($plan->intent, $plan->operation, $plan->parameters, [...$plan->issues, 'out_of_scope']), TicketStatus::Rejected, 'out_of_scope', 'Klient spoza zakresu pracownika; nic nie ujawniono');

            return;
        }
        if ([] !== $violations) {
            $this->audit->record('system', 'security.plan_rejected', 'ticket:'.$job->ticketId, ['violations' => implode(',', $violations), 'interpreter' => $this->interpreter->version()]);
            $this->finish($job, new InterpretedPlan('unclear', null, [], ['unknown_operation']), TicketStatus::NeedsIntervention, 'model_output_rejected', 'Wynik interpretacji odrzucony przez kontrolę zakresu ('.implode(', ', $violations).'); nic nie wykonano');

            return;
        }
        if ('out_of_catalog' === $plan->intent) {
            $this->finish($job, $plan, TicketStatus::Rejected, 'out_of_catalog', 'Prośba spoza katalogu operacji; nic nie wykonano');

            return;
        }
        if ([] !== $plan->blockingIssues() || null === $plan->operation) {
            $this->clarify($job, $plan, $plan->blockingIssues(), $claim['source'], $context);

            return;
        }
        try {
            $this->service->check($plan->operation, $plan->parameters, new Scope($claim['rep']), $claim['reference']);
        } catch (Refusal $refusal) {
            $checked = new InterpretedPlan($plan->intent, $plan->operation, $plan->parameters, [...$plan->issues, $refusal->reason]);
            'out_of_scope' === $refusal->reason
                ? $this->finish($job, $checked, TicketStatus::Rejected, 'out_of_scope', 'Klient spoza zakresu pracownika; nic nie ujawniono')
                : $this->clarify($job, $checked, [$refusal->reason], $claim['source'], $context);

            return;
        }
        $confirm = 'mail' === $claim['source'] && 'demo' !== $this->mode;
        $this->finish(
            $job,
            $plan,
            $confirm ? TicketStatus::AwaitingConfirmation : TicketStatus::Queued,
            null,
            $confirm ? 'Czeka na potwierdzenie pracownika po zalogowaniu' : \sprintf('Plan v%d w kolejce (jedno wykonanie na wersję)', $job->afterVersion + 1),
        );
    }

    /**
     * Asks the requester by e-mail when they can answer (mail ticket, answerable codes, rounds left); otherwise the
     * administrator decides.
     *
     * @param list<string> $codes
     */
    private function clarify(InterpretTicket $job, InterpretedPlan $plan, array $codes, string $source, InterpretationContext $context): void
    {
        $answerable = 'mail' === $source && [] !== $codes && [] === array_filter($codes, static fn (string $c): bool => !\App\Module\Interpretation\Clarification::answerable($c));
        if (!$answerable) {
            $this->finish($job, $plan, TicketStatus::NeedsIntervention, 'clarification_needed', 'Prośba wymaga uzupełnienia; potrzebna decyzja administratora');

            return;
        }
        $rounds = $this->rounds($job->ticketId);
        if ($rounds >= \App\Module\Interpretation\Clarification::MAX_ROUNDS) {
            $this->finish($job, $plan, TicketStatus::NeedsIntervention, 'clarification_exhausted', \sprintf('Po %d pytaniach prośba nadal wymaga uzupełnienia; potrzebna decyzja administratora', $rounds));

            return;
        }
        $this->finish($job, $plan, TicketStatus::NeedsClarification, 'clarification_needed', \sprintf('Pytanie %d z %d wysłane do pracownika (%s)', $rounds + 1, \App\Module\Interpretation\Clarification::MAX_ROUNDS, implode(', ', $codes)), $this->clarification->questions($codes, $context));
    }

    /**
     * @param list<string>|null $questions a clarification to send (with `needs_clarification`)
     */
    private function finish(InterpretTicket $job, ?InterpretedPlan $plan, TicketStatus $status, ?string $reason, string $text, ?array $questions = null): void
    {
        $ticketId = $job->ticketId;
        $version = $job->afterVersion + 1;
        $this->tickets->connection()->transactional(function () use ($ticketId, $version, $job, $plan, $status, $reason, $text, $questions): void {
            $ticket = $this->tickets->lock($ticketId);
            if (null === $ticket || TicketStatus::Interpreting !== $ticket['status'] || $job->afterVersion !== $ticket['plan_version']) {
                return; // changed meanwhile (e.g. rejected by the administrator)
            }
            $now = $this->clock->now();
            if (null !== $plan) {
                $this->tickets->addPlan($ticketId, $version, 'system', $this->interpreter->version(), $now, $plan->operation, $plan->parameters, $plan->issues);
                $this->tickets->event($ticketId, 'system', 'Interpretacja', \sprintf('Plan v%d (%s)%s', $version, $this->interpreter->version(), [] === $plan->issues ? '' : '; do wyjaśnienia: '.implode(', ', $plan->issues)), $now);
                if (\in_array('recipient_change_requested', $plan->issues, true)) {
                    $this->tickets->event($ticketId, 'system', 'Interpretacja', 'Prośba o zmianę odbiorcy zignorowana; wynik trafia zwykłą drogą', $now);
                }
            }
            $this->service->move($ticketId, TicketStatus::Interpreting, $status, $now);
            if (null !== $reason) {
                $this->tickets->setStatus($ticketId, $status, $reason, $now);
            }
            $this->tickets->event($ticketId, 'system', 'System', $text, $now);
            $current = null === $plan ? $job->afterVersion : $version;
            if (TicketStatus::Queued === $status) {
                $this->bus->dispatch(new ExecutePlan($ticketId, $current));
            } elseif (TicketStatus::NeedsClarification === $status && null !== $questions) {
                $round = 1 + $this->rounds($ticketId);
                $this->tickets->connection()->executeStatement('UPDATE ticket SET clarification_rounds = ? WHERE id = ?', [$round, $ticketId]);
                $this->outbox->enqueue($ticketId, 'clarification', $ticketId.':clarify:'.$current, ['questions' => $questions, 'round' => $round]);
            } elseif (TicketStatus::AwaitingConfirmation === $status) {
                $this->outbox->enqueue($ticketId, 'confirmation_request', $ticketId.':confirm:'.$current);
            } elseif (TicketStatus::Rejected === $status) {
                $this->outbox->enqueue($ticketId, 'refused', $ticketId.':refused', ['reason' => $reason]);
            }
        });
    }

    private function rounds(string $ticketId): int
    {
        $rounds = $this->tickets->connection()->fetchOne('SELECT clarification_rounds FROM ticket WHERE id = ?', [$ticketId]);

        return \is_int($rounds) || \is_string($rounds) ? (int) $rounds : 0;
    }

    /**
     * Subject and new part of the request, followed by the new parts of the replies in its thread (quoted history
     * already stripped at ingestion). The whole text goes through the guard again, replies included.
     */
    private function text(string $ticketId): ?string
    {
        $rows = $this->tickets->connection()->fetchAllAssociative("SELECT subject, text_body, outcome FROM mail_message WHERE ticket_id = ? AND outcome <> 'late_reply' ORDER BY received_at, id", [$ticketId]);
        if ([] === $rows) {
            return null;
        }
        $first = array_shift($rows);
        $subject = \is_string($first['subject']) ? (string) preg_replace('/^\s*((re|odp|fwd|pd|fw)\s*:\s*)+/i', '', $first['subject']) : '';
        $parts = [trim($subject."\n".(\is_string($first['text_body']) ? $first['text_body'] : ''))];
        foreach ($rows as $reply) {
            if (\is_string($reply['text_body']) && '' !== trim($reply['text_body'])) {
                $parts[] = 'Uzupełnienie: '.trim($reply['text_body']);
            }
        }
        $text = trim(implode("\n", $parts));

        return '' === $text ? null : $text;
    }
}
