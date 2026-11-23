<?php

declare(strict_types=1);

namespace App\Module\Ticket\Job;

use App\Module\Interpretation\Job\InterpretTicket;
use App\Module\Notification\Job\SendNotification;
use App\Module\Ticket\TicketStatus;
use App\Module\Ticket\TicketStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * A job whose retries are exhausted (it goes to the `failed` transport) must not leave a ticket or a notification
 * claiming that work is still going on (brief §10, TASK-014):
 * - interpretation or execution: the ticket moves to `needs_intervention` / `processing_failed`, but only while it is
 *   still processing the job's version (a stale job's failure touches nothing);
 * - notification: `pending` → `failed`, `sending` → `unknown` (the message may have left; never resent automatically).
 */
final class JobFailureSubscriber
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }
        $job = $event->getEnvelope()->getMessage();
        $error = $event->getThrowable();
        $this->logger->error('Job failed after its retries', ['job' => $job::class, 'exception_class' => $error::class]);
        match (true) {
            $job instanceof ExecutePlan => $this->ticket($job->ticketId, $job->version, [TicketStatus::Queued, TicketStatus::Running], 'Wykonanie planu v%d nie powiodło się po ponowieniach; potrzebna decyzja administratora'),
            $job instanceof InterpretTicket => $this->ticket($job->ticketId, $job->afterVersion, [TicketStatus::Received, TicketStatus::Interpreting], 'Interpretacja (po planie v%d) nie powiodła się po ponowieniach; potrzebna decyzja administratora'),
            $job instanceof SendNotification => $this->notification($job->outboxId),
            default => null,
        };
    }

    /**
     * @param list<TicketStatus> $states
     */
    private function ticket(string $ticketId, int $version, array $states, string $event): void
    {
        $this->tickets->connection()->transactional(function () use ($ticketId, $version, $states, $event): void {
            $ticket = $this->tickets->lock($ticketId);
            if (null === $ticket || $ticket['plan_version'] !== $version || !\in_array($ticket['status'], $states, true)) {
                return;
            }
            $now = $this->clock->now();
            $this->tickets->setStatus($ticketId, TicketStatus::NeedsIntervention, 'processing_failed', $now);
            $this->tickets->event($ticketId, 'system', 'System', \sprintf($event, $version), $now);
        });
    }

    private function notification(string $outboxId): void
    {
        $row = $this->tickets->connection()->fetchAssociative(
            "UPDATE outbox SET status = CASE status WHEN 'pending' THEN 'failed' ELSE 'unknown' END, error = 'job_failed' WHERE id = ? AND status IN ('pending', 'sending') RETURNING ticket_id, template, status",
            [$outboxId],
        );
        if (false === $row || !\is_string($row['ticket_id']) || !\is_string($row['template'])) {
            return;
        }
        $this->tickets->event($row['ticket_id'], 'system', 'Poczta', \sprintf(
            'failed' === $row['status'] ? 'Powiadomienie %s: wysyłka nie powiodła się po ponowieniach' : 'Powiadomienie %s: wysyłka przerwana, wynik nieznany (bez ponawiania)',
            $row['template'],
        ), $this->clock->now());
    }
}
