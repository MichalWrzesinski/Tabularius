<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Accounts, server sessions (ADR 0003) with a session→account index for immediate revocation, and the audit log.
 * Runs as tabularius_migrator; tabularius_app gets DML through default privileges (infra/postgres/init).
 */
final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accounts, sessions and audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE account (
                id uuid PRIMARY KEY,
                email text NOT NULL,
                name text NOT NULL,
                role text NOT NULL CHECK (role IN ('admin', 'rep')),
                status text NOT NULL CHECK (status IN ('active', 'blocked', 'deleted')),
                password_hash text,
                rep_code text,
                shared boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL,
                password_changed_at timestamptz NOT NULL,
                last_login_at timestamptz,
                deleted_at timestamptz,
                CHECK ((role = 'rep') = (rep_code IS NOT NULL) OR status = 'deleted'),
                CHECK (status <> 'deleted' OR password_hash IS NULL)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX account_email_uq ON account (lower(email))');
        $this->addSql("CREATE UNIQUE INDEX account_rep_code_uq ON account (rep_code) WHERE status <> 'deleted'");

        // Symfony PdoSessionHandler schema (PostgreSQL).
        $this->addSql('CREATE TABLE sessions (sess_id varchar(128) PRIMARY KEY, sess_data bytea NOT NULL, sess_lifetime integer NOT NULL, sess_time integer NOT NULL)');
        $this->addSql('CREATE INDEX sessions_lifetime_idx ON sessions (sess_lifetime)');
        $this->addSql(<<<'SQL'
            CREATE TABLE account_session (
                session_id varchar(128) PRIMARY KEY,
                account_id uuid NOT NULL REFERENCES account (id),
                created_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX account_session_account_idx ON account_session (account_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_event (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                at timestamptz NOT NULL,
                actor_ref text NOT NULL,
                action text NOT NULL,
                target_ref text NOT NULL,
                details jsonb NOT NULL DEFAULT '{}'
            )
            SQL);
        $this->addSql('CREATE INDEX audit_event_at_idx ON audit_event (at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_event, account_session, sessions, account');
    }
}
