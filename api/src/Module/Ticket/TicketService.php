<?php

declare(strict_types=1);

namespace App\Module\Ticket;

use App\Module\Audit\AuditLog;
use App\Module\Identity\Account;
use App\Module\Identity\Role;
use App\Module\Report\Business\DataSourceException;
use App\Module\Report\Business\OutOfScopeException;
use App\Module\Report\Execution\InvalidParametersException;
use App\Module\Report\Execution\ReportEngine;
use App\Module\Report\Execution\Scope;
use App\Module\Report\Period\DateRange;
use App\Module\Report\Period\PeriodException;
use App\Module\Report\Profile\IntegrationProfile;
use App\Module\Shared\Refusal;
use App\Module\Ticket\Job\ExecutePlan;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Administrator actions on tickets. Every change runs in one transaction with its events; the job is written to the
 * Doctrine queue on the same connection, so a ticket is never queued without its job or vice versa.
 */
final class TicketService
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly EntityManagerInterface $em,
        private readonly ReportEngine $engine,
        private readonly IntegrationProfile $profile,
        private readonly MessageBusInterface $bus,
        private readonly AuditLog $audit,
        private readonly ClockInterface $clock,
        private readonly \App\Module\Notification\Outbox $outbox,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'APP_MODE')]
        private readonly string $mode = 'demo',
    ) {
    }

    /**
     * Manual ticket (no mail, no model): the administrator chooses the owner, the operation and its parameters.
     * The plan is validated against the owner's scope before it is queued.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function createManual(Account $admin, string $ownerId, string $operation, array $parameters): string
    {
        $owner = Uuid::isValid($ownerId) ? $this->em->find(Account::class, Uuid::fromString($ownerId)) : null;
        if (!$owner instanceof Account || Role::Rep !== $owner->role() || !$owner->isActive() || null === $owner->repCode()) {
            throw new Refusal('invalid_owner');
        }
        $now = $this->clock->now();
        $reference = $now->setTimezone(DateRange::zone())->setTime(0, 0);
        $this->check($operation, $parameters, new Scope($owner->repCode()), $reference);

        $id = Uuid::v7();
        $this->tickets->connection()->transactional(function () use ($id, $owner, $admin, $operation, $parameters, $reference, $now): void {
            $ticketId = $id->toRfc4122();
            $this->tickets->insert($id, $owner->id()->toRfc4122(), 'manual', TicketStatus::Queued, $reference, $now, self::ref($admin));
            $this->tickets->addPlan($ticketId, 1, 'admin', self::ref($admin), $now, $operation, $parameters);
            $this->tickets->event($ticketId, 'admin', 'Administrator', 'Zgłoszenie utworzone ręcznie w imieniu pracownika', $now);
            $this->tickets->event($ticketId, 'system', 'System', 'Plan v1 w kolejce (jedno wykonanie na wersję)', $now);
            $this->audit->record(self::ref($admin), 'ticket.create', 'ticket:'.$ticketId, ['operation' => $operation]);
            $this->bus->dispatch(new ExecutePlan($ticketId, 1));
        });

        return $id->toRfc4122();
    }

    /**
     * The administrator sets the parameters of a ticket that has an owner but no executable plan yet (a mail ticket
     * waiting for interpretation, or one in intervention). A new plan version is validated in the owner's scope.
     * A mail ticket in production mode then waits for the owner's confirmation in the portal (ID-2); otherwise the
     * plan is queued.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function setPlan(Account $admin, string $ticketId, string $operation, array $parameters): void
    {
        $this->tickets->connection()->transactional(function () use ($admin, $ticketId, $operation, $parameters): void {
            $ticket = $this->tickets->lock($ticketId) ?? throw new Refusal('not_found', 404);
            if (!\in_array($ticket['status'], [TicketStatus::Received, TicketStatus::Interpreting, TicketStatus::NeedsIntervention], true)) {
                throw new Refusal('invalid_status', 409);
            }
            $owner = null === $ticket['owner_account_id'] ? null : $this->em->find(Account::class, Uuid::fromString($ticket['owner_account_id']));
            if (!$owner instanceof Account || !$owner->isActive() || null === $owner->repCode()) {
                throw new Refusal('no_owner', 409); // identity_unverified: only rejection is possible (ID-4)
            }
            $this->check($operation, $parameters, new Scope($owner->repCode()), DateRange::date($ticket['reference_date']));
            $now = $this->clock->now();
            $version = $ticket['plan_version'] + 1;
            $this->tickets->addPlan($ticketId, $version, 'admin', self::ref($admin), $now, $operation, $parameters);
            $this->tickets->event($ticketId, 'admin', 'Administrator', \sprintf('Plan v%d ustawiony ręcznie (zakres właściciela zgłoszenia)', $version), $now);
            $this->audit->record(self::ref($admin), 'ticket.plan', 'ticket:'.$ticketId, ['version' => $version, 'operation' => $operation]);
            if ('mail' === $ticket['source'] && 'demo' !== $this->mode) {
                $this->move($ticketId, $ticket['status'], TicketStatus::AwaitingConfirmation, $now);
                $this->tickets->event($ticketId, 'system', 'System', 'Czeka na potwierdzenie pracownika po zalogowaniu (adres nadawcy nie potwierdza tożsamości)', $now);
                $this->outbox->enqueue($ticketId, 'confirmation_request', $ticketId.':confirm:'.$version);

                return;
            }
            $this->move($ticketId, $ticket['status'], TicketStatus::Queued, $now);
            $this->tickets->event($ticketId, 'system', 'System', \sprintf('Plan v%d w kolejce (jedno wykonanie na wersję)', $version), $now);
            $this->bus->dispatch(new ExecutePlan($ticketId, $version));
        });
    }

    /** The owner confirms the request after logging in (ID-2); only then is it executed. */
    public function confirm(Account $owner, string $ticketId): void
    {
        $this->tickets->connection()->transactional(function () use ($owner, $ticketId): void {
            $ticket = $this->ownedAwaiting($owner, $ticketId);
            $now = $this->clock->now();
            $this->tickets->setStatus($ticketId, TicketStatus::Queued, null, $now);
            $this->tickets->event($ticketId, 'employee', 'Pracownik', 'Prośba potwierdzona po zalogowaniu', $now);
            $this->tickets->event($ticketId, 'system', 'System', \sprintf('Plan v%d w kolejce (jedno wykonanie na wersję)', $ticket['plan_version']), $now);
            $this->audit->record(self::ref($owner), 'ticket.confirm', 'ticket:'.$ticketId);
            $this->bus->dispatch(new ExecutePlan($ticketId, $ticket['plan_version']));
        });
    }

    /** „To nie ja” — the owner cancels; nothing runs. */
    public function cancel(Account $owner, string $ticketId): void
    {
        $this->tickets->connection()->transactional(function () use ($owner, $ticketId): void {
            $this->ownedAwaiting($owner, $ticketId);
            $now = $this->clock->now();
            $this->tickets->setStatus($ticketId, TicketStatus::Rejected, 'cancelled_by_owner', $now);
            $this->tickets->event($ticketId, 'employee', 'Pracownik', 'Prośba anulowana przez pracownika („to nie ja”)', $now);
            $this->audit->record(self::ref($owner), 'ticket.cancel', 'ticket:'.$ticketId);
        });
    }

    /** Questions not answered within the period go to the administrator (needs_clarification → needs_intervention). */
    public function expireClarifications(int $days = 7): int
    {
        $now = $this->clock->now();
        $ids = $this->tickets->connection()->fetchFirstColumn(
            "SELECT id FROM ticket WHERE status = 'needs_clarification' AND updated_at < ?",
            [TicketStore::ts($now->modify(\sprintf('-%d days', $days)))],
        );
        foreach ($ids as $id) {
            $this->tickets->connection()->transactional(function () use ($id, $now, $days): void {
                $ticket = \is_string($id) ? $this->tickets->lock($id) : null;
                if (null === $ticket || TicketStatus::NeedsClarification !== $ticket['status']) {
                    return;
                }
                $this->tickets->setStatus((string) $id, TicketStatus::NeedsIntervention, 'clarification_expired', $now);
                $this->tickets->event((string) $id, 'system', 'System', \sprintf('Brak odpowiedzi na pytanie w ciągu %d dni; potrzebna decyzja administratora', $days), $now);
            });
        }

        return \count($ids);
    }

    /** Requests not confirmed within the period expire (tickets.md: awaiting_confirmation → rejected after 7 days). */
    public function expireUnconfirmed(int $days = 7): int
    {
        $now = $this->clock->now();
        $ids = $this->tickets->connection()->fetchFirstColumn(
            "SELECT id FROM ticket WHERE status = 'awaiting_confirmation' AND updated_at < ?",
            [TicketStore::ts($now->modify(\sprintf('-%d days', $days)))],
        );
        foreach ($ids as $id) {
            $this->tickets->connection()->transactional(function () use ($id, $now): void {
                $ticket = \is_string($id) ? $this->tickets->lock($id) : null;
                if (null === $ticket || TicketStatus::AwaitingConfirmation !== $ticket['status']) {
                    return;
                }
                $this->tickets->setStatus((string) $id, TicketStatus::Rejected, 'confirmation_expired', $now);
                $this->tickets->event((string) $id, 'system', 'System', 'Prośba wygasła bez potwierdzenia', $now);
            });
        }

        return \count($ids);
    }

    /** Re-run the current plan version after a data-source or technical failure (same parameters). */
    public function retry(Account $admin, string $ticketId): void
    {
        $this->tickets->connection()->transactional(function () use ($admin, $ticketId): void {
            $ticket = $this->tickets->lock($ticketId) ?? throw new Refusal('not_found', 404);
            if (TicketStatus::NeedsIntervention !== $ticket['status'] || 0 === $ticket['plan_version']) {
                throw new Refusal('invalid_status', 409);
            }
            $now = $this->clock->now();
            $version = $ticket['plan_version'];
            $this->tickets->setStatus($ticketId, TicketStatus::Queued, null, $now);
            $this->tickets->event($ticketId, 'admin', 'Administrator', \sprintf('Ponowienie wykonania planu v%d (bez zmiany parametrów)', $version), $now);
            $this->audit->record(self::ref($admin), 'ticket.retry', 'ticket:'.$ticketId, ['version' => $version]);
            $this->bus->dispatch(new ExecutePlan($ticketId, $version));
        });
    }

    public function reject(Account $admin, string $ticketId): void
    {
        $this->tickets->connection()->transactional(function () use ($admin, $ticketId): void {
            $ticket = $this->tickets->lock($ticketId) ?? throw new Refusal('not_found', 404);
            if (!$ticket['status']->canBecome(TicketStatus::Rejected)) {
                throw new Refusal('invalid_status', 409);
            }
            $now = $this->clock->now();
            $this->tickets->setStatus($ticketId, TicketStatus::Rejected, 'admin_rejected', $now);
            $this->tickets->event($ticketId, 'admin', 'Administrator', 'Zgłoszenie odrzucone przez administratora', $now);
            $this->audit->record(self::ref($admin), 'ticket.reject', 'ticket:'.$ticketId);
            $this->outbox->enqueue($ticketId, 'refused', $ticketId.':refused', ['reason' => 'admin_rejected']);
        });
    }

    /**
     * @return array{status: TicketStatus, plan_version: int, owner_account_id: ?string, source: string, reference_date: string}
     */
    private function ownedAwaiting(Account $owner, string $ticketId): array
    {
        $ticket = $this->tickets->lock($ticketId);
        if (null === $ticket || $ticket['owner_account_id'] !== $owner->id()->toRfc4122()) {
            throw new Refusal('not_found', 404); // someone else's ticket looks like a missing one
        }
        if (TicketStatus::AwaitingConfirmation !== $ticket['status']) {
            throw new Refusal('invalid_status', 409);
        }

        return $ticket;
    }

    /** Status change along allowed transitions, through `interpreting` when there is no direct one (inside a transaction). */
    public function move(string $ticketId, TicketStatus $from, TicketStatus $to, \DateTimeImmutable $now): void
    {
        if (!$from->canBecome($to)) {
            if (!$from->canBecome(TicketStatus::Interpreting) || !TicketStatus::Interpreting->canBecome($to)) {
                throw new \LogicException(\sprintf('No transition from %s to %s', $from->value, $to->value));
            }
            $this->tickets->setStatus($ticketId, TicketStatus::Interpreting, null, $now);
        }
        $this->tickets->setStatus($ticketId, $to, null, $now);
    }

    /**
     * Validates a plan in an owner's scope; refusals carry the reason code (unknown_operation, period_*, out_of_scope…).
     *
     * @param array<array-key, mixed> $parameters
     *
     * @throws Refusal
     */
    public function check(string $operation, array $parameters, Scope $scope, \DateTimeImmutable $reference): void
    {
        if (!isset($this->profile->operations[$operation])) {
            throw new Refusal('unknown_operation');
        }
        try {
            $this->engine->check($operation, $parameters, $scope, $reference);
        } catch (InvalidParametersException $e) {
            throw new Refusal($e->reason);
        } catch (PeriodException $e) {
            throw new Refusal($e->reason);
        } catch (OutOfScopeException) {
            throw new Refusal('out_of_scope');
        } catch (DataSourceException) {
            throw new Refusal('data_source_unavailable', 503);
        }
    }

    private static function ref(Account $account): string
    {
        return 'account:'.$account->id()->toRfc4122();
    }
}
