<?php

declare(strict_types=1);

namespace App\Module\Identity;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Keeps the session → account index and deletes all sessions of an account when it is blocked, deleted or its
 * password is reset (ADR 0003).
 */
final class SessionRevoker
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function remember(string $sessionId, Account $account): void
    {
        $this->connection->executeStatement(
            'INSERT INTO account_session (session_id, account_id, created_at) VALUES (?, ?, ?) ON CONFLICT (session_id) DO NOTHING',
            [$sessionId, $account->id()->toRfc4122(), $this->clock->now()->format('Y-m-d H:i:sP')],
        );
    }

    public function revokeAll(Account $account, ?string $exceptSessionId = null): int
    {
        return (int) $this->connection->transactional(static function (Connection $c) use ($account, $exceptSessionId): int {
            $id = $account->id()->toRfc4122();
            $except = $exceptSessionId ?? '';
            $c->executeStatement('DELETE FROM sessions WHERE sess_id IN (SELECT session_id FROM account_session WHERE account_id = ? AND session_id <> ?)', [$id, $except]);

            return (int) $c->executeStatement('DELETE FROM account_session WHERE account_id = ? AND session_id <> ?', [$id, $except]);
        });
    }
}
