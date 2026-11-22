<?php

declare(strict_types=1);

namespace App\Module\Mail;

use App\Module\Ticket\TicketStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Persistence of incoming mail (mail_message).
 */
final class MailStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function knownUid(string $mailbox, int $uidValidity, int $uid): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM mail_message WHERE mailbox = ? AND uid_validity = ? AND uid = ?', [$mailbox, $uidValidity, $uid], [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]);
    }

    public function knownMessageId(string $hash): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM mail_message WHERE message_id_hash = ?', [$hash]);
    }

    /** Highest stored UID of the mailbox in this UIDVALIDITY epoch: polling continues after it. */
    public function lastUid(string $mailbox, int $uidValidity): int
    {
        $uid = $this->db->fetchOne('SELECT max(uid) FROM mail_message WHERE mailbox = ? AND uid_validity = ?', [$mailbox, $uidValidity], [ParameterType::STRING, ParameterType::INTEGER]);

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /** Accepted (ticketed) messages since a moment, for one sender or (null) for everyone. */
    public function ticketedSince(?string $senderHash, \DateTimeImmutable $since): int
    {
        $sql = "SELECT count(*) FROM mail_message WHERE outcome NOT IN ('duplicate', 'auto_reply', 'rate_limited') AND received_at >= ?";
        $params = [TicketStore::ts($since)];
        if (null !== $senderHash) {
            $sql .= ' AND sender_hash = ?';
            $params[] = $senderHash;
        }
        $count = $this->db->fetchOne($sql, $params);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array{id: string, mailbox: string, uid_validity: ?int, uid: ?int, message_id_hash: ?string, message_id: ?string, received_at: string, sender_hash: ?string, sender: ?string, subject: ?string, header_block: ?string, text_body: ?string, size_bytes: int, attachment_count: int, outcome: string, ticket_id: ?string, created_at: string} $row
     */
    public function insert(array $row): void
    {
        $this->db->insert('mail_message', $row, ['uid_validity' => ParameterType::INTEGER, 'uid' => ParameterType::INTEGER, 'size_bytes' => ParameterType::INTEGER, 'attachment_count' => ParameterType::INTEGER]);
    }

    /** Retention (ID-DEMO-7): contents of messages received before `$before` are deleted; metadata stays. */
    public function purgeContents(\DateTimeImmutable $before, \DateTimeImmutable $now): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE mail_message SET sender = NULL, subject = NULL, header_block = NULL, text_body = NULL, message_id = NULL, purged_at = ? WHERE purged_at IS NULL AND received_at < ?',
            [TicketStore::ts($now), TicketStore::ts($before)],
        );
    }
}
