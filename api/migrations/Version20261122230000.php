<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages received before the configured start (MAIL_INGEST_SINCE) are recorded without contents as
 * `before_start`, so connecting an existing mailbox never turns its older mail into tickets or replies.
 */
final class Version20261122230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mail outcome before_start';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_message DROP CONSTRAINT mail_message_outcome_check');
        $this->addSql("ALTER TABLE mail_message ADD CONSTRAINT mail_message_outcome_check CHECK (outcome IN ('ticketed', 'duplicate', 'auto_reply', 'rate_limited', 'too_large', 'text_too_long', 'no_text', 'unparseable', 'before_start'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mail_message WHERE outcome = 'before_start'");
        $this->addSql('ALTER TABLE mail_message DROP CONSTRAINT mail_message_outcome_check');
        $this->addSql("ALTER TABLE mail_message ADD CONSTRAINT mail_message_outcome_check CHECK (outcome IN ('ticketed', 'duplicate', 'auto_reply', 'rate_limited', 'too_large', 'text_too_long', 'no_text', 'unparseable'))");
    }
}
