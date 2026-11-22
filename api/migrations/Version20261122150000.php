<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tickets with versioned plans and events, report file metadata, and the Messenger queue (TKT-*, ACC-FILE-1).
 * Runs as tabularius_migrator; tabularius_app gets DML through default privileges (infra/postgres/init).
 * The unique (ticket_id, plan_version) on report_file is the idempotency guarantee of TKT-VER: one file per version.
 */
final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tickets, plans, events, report files and the job queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket (
                id uuid PRIMARY KEY,
                number bigint GENERATED ALWAYS AS IDENTITY (START WITH 1001) UNIQUE,
                owner_account_id uuid NOT NULL REFERENCES account (id),
                source text NOT NULL CHECK (source IN ('manual', 'mail')),
                status text NOT NULL CHECK (status IN ('received', 'interpreting', 'needs_clarification', 'awaiting_confirmation',
                    'queued', 'running', 'completed', 'rejected', 'needs_intervention')),
                reason text,
                plan_version integer NOT NULL DEFAULT 0,
                reference_date date NOT NULL,
                received_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                created_by text NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX ticket_owner_idx ON ticket (owner_account_id, received_at DESC)');
        $this->addSql('CREATE INDEX ticket_received_idx ON ticket (received_at DESC)');

        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_plan (
                ticket_id uuid NOT NULL REFERENCES ticket (id),
                version integer NOT NULL CHECK (version > 0),
                author text NOT NULL CHECK (author IN ('system', 'model', 'employee', 'admin')),
                author_ref text NOT NULL,
                created_at timestamptz NOT NULL,
                operation text,
                parameters jsonb NOT NULL,
                issues jsonb NOT NULL DEFAULT '[]',
                PRIMARY KEY (ticket_id, version)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                ticket_id uuid NOT NULL REFERENCES ticket (id),
                at timestamptz NOT NULL,
                actor text NOT NULL CHECK (actor IN ('system', 'model', 'employee', 'admin')),
                actor_name text NOT NULL,
                text text NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX ticket_event_ticket_idx ON ticket_event (ticket_id, id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE report_file (
                id uuid PRIMARY KEY,
                ticket_id uuid NOT NULL REFERENCES ticket (id),
                plan_version integer NOT NULL,
                owner_account_id uuid NOT NULL REFERENCES account (id),
                rep_code text NOT NULL,
                customer_codes jsonb NOT NULL,
                operation text NOT NULL,
                name text NOT NULL,
                period_label text NOT NULL,
                scope_label text NOT NULL,
                row_count integer NOT NULL,
                empty boolean NOT NULL,
                size_bytes integer NOT NULL,
                sha256 text NOT NULL,
                storage_key text NOT NULL UNIQUE,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                deleted_at timestamptz,
                UNIQUE (ticket_id, plan_version)
            )
            SQL);
        $this->addSql('CREATE INDEX report_file_owner_idx ON report_file (owner_account_id, created_at DESC)');
        $this->addSql('CREATE INDEX report_file_expires_idx ON report_file (expires_at) WHERE deleted_at IS NULL');

        // Symfony Messenger Doctrine transport schema (auto_setup=0: the app role has no DDL).
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id bigserial PRIMARY KEY,
                body text NOT NULL,
                headers text NOT NULL,
                queue_name varchar(190) NOT NULL,
                created_at timestamp(0) without time zone NOT NULL,
                available_at timestamp(0) without time zone NOT NULL,
                delivered_at timestamp(0) without time zone DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX messenger_messages_queue_idx ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages, report_file, ticket_event, ticket_plan, ticket');
    }
}
