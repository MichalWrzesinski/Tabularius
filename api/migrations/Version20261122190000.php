<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-007: synthetic participant alias of a mail ticket (demo mode, ID-DEMO-3); the only person reference that
 * shared demo views show.
 */
final class Version20261122190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participant alias on tickets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket ADD COLUMN participant_alias text');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket DROP COLUMN participant_alias');
    }
}
