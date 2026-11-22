<?php

declare(strict_types=1);

namespace App\Module\Ticket\Job;

/**
 * Execute one plan version of a ticket. Idempotent per (ticket, version): a duplicate or stale job is recorded and
 * discarded (TKT-VER).
 */
final readonly class ExecutePlan
{
    public function __construct(public string $ticketId, public int $version)
    {
    }
}
