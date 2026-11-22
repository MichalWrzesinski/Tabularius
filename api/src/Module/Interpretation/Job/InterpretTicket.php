<?php

declare(strict_types=1);

namespace App\Module\Interpretation\Job;

/**
 * Interpret the request of a received mail ticket into plan v1 (TASK-008). Idempotent: only a ticket still in
 * `received` (or `interpreting` without a plan, after a crash) is interpreted.
 */
final readonly class InterpretTicket
{
    public function __construct(public string $ticketId)
    {
    }
}
