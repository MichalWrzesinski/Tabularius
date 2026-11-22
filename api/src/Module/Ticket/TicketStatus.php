<?php

declare(strict_types=1);

namespace App\Module\Ticket;

/**
 * Ticket statuses and allowed transitions (docs/product/areas/tickets.md, TKT-STATUS).
 */
enum TicketStatus: string
{
    case Received = 'received';
    case Interpreting = 'interpreting';
    case NeedsClarification = 'needs_clarification';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case NeedsIntervention = 'needs_intervention';

    public function canBecome(self $next): bool
    {
        return \in_array($next, match ($this) {
            self::Received => [self::Interpreting, self::NeedsIntervention, self::Rejected],
            self::Interpreting => [self::NeedsClarification, self::AwaitingConfirmation, self::Queued, self::Rejected, self::NeedsIntervention],
            self::NeedsClarification => [self::Interpreting, self::NeedsIntervention, self::Rejected],
            self::AwaitingConfirmation => [self::Queued, self::NeedsClarification, self::Rejected],
            self::Queued => [self::Running, self::NeedsIntervention, self::Rejected],
            self::Running => [self::Completed, self::NeedsIntervention],
            self::NeedsIntervention => [self::Interpreting, self::Queued, self::Rejected],
            self::Completed, self::Rejected => [],
        }, true);
    }

    public function isOpen(): bool
    {
        return self::Completed !== $this && self::Rejected !== $this;
    }
}
