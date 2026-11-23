<?php

declare(strict_types=1);

namespace App\Module\Interpretation\Job;

/**
 * Interpret a mail ticket into the plan version after `$afterVersion` (TASK-008; TASK-013 for replies). Idempotent:
 * only a ticket in `received` (v0), or in `interpreting` still at `$afterVersion` (a reply, or a crash), is
 * interpreted; a job for an older version is stale and does nothing.
 */
final readonly class InterpretTicket
{
    public function __construct(public string $ticketId, public int $afterVersion = 0)
    {
    }
}
