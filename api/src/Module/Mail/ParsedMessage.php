<?php

declare(strict_types=1);

namespace App\Module\Mail;

/**
 * What ingestion keeps from a message: identifying headers for deduplication and replies, the header block for the
 * trusted operator, and the extracted text. Attachments are counted, never kept.
 */
final readonly class ParsedMessage
{
    public function __construct(
        public ?string $messageId,
        public ?string $sender,
        public string $subject,
        public string $headerBlock,
        public string $text,
        public int $attachmentCount,
        public bool $automatic,
        /** @var list<string> Message-IDs from In-Reply-To and References (without brackets), for correlation only */
        public array $replyTo = [],
        /** @var list<string> For a delivery-status report (bounce) only: Message-IDs it names, to mark our outgoing message bounced */
        public array $returned = [],
    ) {
    }
}
