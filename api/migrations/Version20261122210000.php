<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Outbox (MAIL-3, MAIL-4; TASK-009): notifications written in the ticket's transaction and sent afterwards. One row per
 * (ticket, notification key); delivery states never resend after an unknown outcome. The incoming Message-ID is kept
 * (until retention) so notifications reply in the request's thread.
 */
final class Version20261122210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification outbox; incoming Message-ID for threading';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_message ADD COLUMN message_id text');
        $this->addSql(<<<'SQL'
            CREATE TABLE outbox (
                id uuid PRIMARY KEY,
                ticket_id uuid NOT NULL REFERENCES ticket (id),
                dedup_key text NOT NULL UNIQUE,
                template text NOT NULL CHECK (template IN ('received', 'confirmation_request', 'report_ready', 'refused')),
                route text NOT NULL CHECK (route IN ('deliver', 'capture')),
                recipient text,
                message_id text NOT NULL UNIQUE,
                in_reply_to text,
                subject text NOT NULL,
                body_text text,
                body_html text,
                status text NOT NULL CHECK (status IN ('pending', 'sending', 'accepted', 'unknown', 'failed')),
                attempts integer NOT NULL DEFAULT 0,
                error text,
                created_at timestamptz NOT NULL,
                sent_at timestamptz,
                purged_at timestamptz
            )
            SQL);
        $this->addSql('CREATE INDEX outbox_ticket_idx ON outbox (ticket_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox');
        $this->addSql('ALTER TABLE mail_message DROP COLUMN message_id');
    }
}
