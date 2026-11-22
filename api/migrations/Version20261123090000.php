<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LLM usage per call (TASK-011): model, prompt version, tokens, cost and outcome, for the daily spend guard and the
 * evaluation report. No message text is stored here.
 */
final class Version20261123090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LLM usage log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_usage (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                at timestamptz NOT NULL,
                ticket_id uuid,
                model text NOT NULL,
                prompt_version text NOT NULL,
                input_tokens integer NOT NULL DEFAULT 0,
                output_tokens integer NOT NULL DEFAULT 0,
                cost_usd numeric(12, 6) NOT NULL DEFAULT 0,
                latency_ms integer NOT NULL DEFAULT 0,
                outcome text NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX ai_usage_at_idx ON ai_usage (at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_usage');
    }
}
