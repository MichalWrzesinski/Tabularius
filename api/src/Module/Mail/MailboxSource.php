<?php

declare(strict_types=1);

namespace App\Module\Mail;

/**
 * Where incoming mail comes from (adapter boundary; ADR 0001). `fetch` yields messages with a UID greater than the
 * last one ingested in the current UIDVALIDITY epoch; it never changes the mailbox.
 */
interface MailboxSource
{
    /** Stable identifier of the mailbox (no credentials), used for deduplication. */
    public function name(): string;

    public function configured(): bool;

    /**
     * @param \Closure(int $uidValidity): int $lastUid
     *
     * @return iterable<IncomingMessage>
     */
    public function fetch(\Closure $lastUid, int $limit): iterable;
}
