<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Failure scenarios (TASK-014): an outgoing message returned by the mail server is `bounced` (MAIL-2).
 */
final class Version20261123180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outbox status bounced';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox DROP CONSTRAINT outbox_status_check');
        $this->addSql("ALTER TABLE outbox ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending', 'sending', 'accepted', 'unknown', 'failed', 'bounced'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE outbox SET status = 'accepted' WHERE status = 'bounced'");
        $this->addSql('ALTER TABLE outbox DROP CONSTRAINT outbox_status_check');
        $this->addSql("ALTER TABLE outbox ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending', 'sending', 'accepted', 'unknown', 'failed'))");
    }
}
