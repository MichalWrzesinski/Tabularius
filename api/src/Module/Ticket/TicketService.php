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
        });
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    private function check(string $operation, array $parameters, Scope $scope, \DateTimeImmutable $reference): void
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
