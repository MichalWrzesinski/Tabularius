<?php

declare(strict_types=1);

namespace App\Module\Mail;

use App\Module\Mail\Imap\ImapException;
use Psr\Log\LoggerInterface;

/**
 * One polling round: messages after the last ingested UID, each ingested in its own transaction. A failure leaves
 * the rest for the next round (nothing is acknowledged in the mailbox; the UID high-water mark is the state).
 */
final class MailPoller
{
    public const int BATCH = 50;

    public function __construct(
        private readonly MailboxSource $mailbox,
        private readonly MailStore $store,
        private readonly Ingestor $ingestor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int> outcome => count
     */
    public function poll(): array
    {
        if (!$this->mailbox->configured()) {
            return [];
        }
        $counts = [];
        try {
            foreach ($this->mailbox->fetch(fn (int $uidValidity): int => $this->store->lastUid($this->mailbox->name(), $uidValidity), self::BATCH) as $message) {
                $outcome = $this->ingestor->ingest($message);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
        } catch (ImapException $e) {
            $this->logger->warning('Mailbox polling failed', ['error' => $e->getMessage()]);
        }

        return $counts;
    }
}
