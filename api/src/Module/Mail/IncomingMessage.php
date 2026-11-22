<?php

declare(strict_types=1);

namespace App\Module\Mail;

/**
 * One message as the mailbox delivers it. `receivedAt` is the mailbox's receive time (IMAP INTERNALDATE), never the
 * sender-controlled Date header (DATE-1). An oversized message arrives with its headers only (`headersOnly`).
 */
final readonly class IncomingMessage
{
    public function __construct(
        public string $mailbox,
        public ?int $uidValidity,
        public ?int $uid,
        public string $raw,
        public int $size,
        public \DateTimeImmutable $receivedAt,
        public bool $headersOnly = false,
    ) {
    }
}
