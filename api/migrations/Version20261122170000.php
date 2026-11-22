<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Incoming mail (MAIL-1, MAIL-2, MAIL-5): one row per accepted mailbox message, deduplicated by mailbox UID and by a
 * Message-ID hash (a copy with another UID is recorded as `duplicate`, without contents, so polling moves on). Attachments are never stored; contents (sender, subject, header block, text) are purged after the
 * retention period, the pseudonymous sender hash stays for limits. Mail tickets may have no owner until identity
 * (TASK-007) assigns one.
 */
final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incoming mail messages; mail tickets without an owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ALTER COLUMN owner_account_id DROP NOT NULL');
        $this->addSql("ALTER TABLE ticket ADD CONSTRAINT ticket_owner_or_mail CHECK (owner_account_id IS NOT NULL OR source = 'mail')");

        $this->addSql(<<<'SQL'
            CREATE TABLE mail_message (
                id uuid PRIMARY KEY,
                mailbox text NOT NULL,
                uid_validity bigint,
                uid bigint,
                message_id_hash text,
                received_at timestamptz NOT NULL,
                sender_hash text,
                sender text,
                subject text,
                header_block text,
                text_body text,
                size_bytes integer NOT NULL,
                attachment_count integer NOT NULL DEFAULT 0,
                outcome text NOT NULL CHECK (outcome IN ('ticketed', 'duplicate', 'auto_reply', 'rate_limited', 'too_large', 'text_too_long', 'no_text', 'unparseable')),
                ticket_id uuid REFERENCES ticket (id),
                created_at timestamptz NOT NULL,
                purged_at timestamptz,
                CHECK ((uid IS NULL) = (uid_validity IS NULL))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX mail_message_uid_uq ON mail_message (mailbox, uid_validity, uid) WHERE uid IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX mail_message_message_id_uq ON mail_message (message_id_hash) WHERE message_id_hash IS NOT NULL');
        $this->addSql('CREATE INDEX mail_message_sender_idx ON mail_message (sender_hash, received_at)');
        $this->addSql('CREATE INDEX mail_message_received_idx ON mail_message (received_at)');
        $this->addSql('CREATE INDEX mail_message_ticket_idx ON mail_message (ticket_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mail_message');
        $this->addSql('ALTER TABLE ticket DROP CONSTRAINT ticket_owner_or_mail');
        $this->addSql('ALTER TABLE ticket ALTER COLUMN owner_account_id SET NOT NULL');
    }
}
