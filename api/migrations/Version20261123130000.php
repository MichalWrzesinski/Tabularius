<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clarification and replies (TASK-013): the `clarification` template, questions sent per ticket (at most 3 rounds) and incoming messages that
 * joined an existing ticket (`reply`) or arrived when the ticket was not waiting for one (`late_reply`).
 */
final class Version20261123130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clarification rounds, reply outcomes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD clarification_rounds integer NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE outbox DROP CONSTRAINT outbox_template_check');
        $this->addSql("ALTER TABLE outbox ADD CONSTRAINT outbox_template_check CHECK (template IN ('received', 'confirmation_request', 'report_ready', 'refused', 'clarification'))");
        $this->addSql('ALTER TABLE mail_message DROP CONSTRAINT mail_message_outcome_check');
        $this->addSql("ALTER TABLE mail_message ADD CONSTRAINT mail_message_outcome_check CHECK (outcome IN ('ticketed', 'duplicate', 'auto_reply', 'rate_limited', 'too_large', 'text_too_long', 'no_text', 'unparseable', 'before_start', 'reply', 'late_reply'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM outbox WHERE template = 'clarification'");
        $this->addSql('ALTER TABLE outbox DROP CONSTRAINT outbox_template_check');
        $this->addSql("ALTER TABLE outbox ADD CONSTRAINT outbox_template_check CHECK (template IN ('received', 'confirmation_request', 'report_ready', 'refused'))");
        $this->addSql("DELETE FROM mail_message WHERE outcome IN ('reply', 'late_reply')");
        $this->addSql('ALTER TABLE mail_message DROP CONSTRAINT mail_message_outcome_check');
        $this->addSql("ALTER TABLE mail_message ADD CONSTRAINT mail_message_outcome_check CHECK (outcome IN ('ticketed', 'duplicate', 'auto_reply', 'rate_limited', 'too_large', 'text_too_long', 'no_text', 'unparseable', 'before_start'))");
        $this->addSql('ALTER TABLE ticket DROP clarification_rounds');
    }
}
