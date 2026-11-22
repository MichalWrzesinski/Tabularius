<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * PdoSessionHandler on its own PostgreSQL connection (same database and role as the app, table `sessions`).
 * A separate connection plus advisory locking keeps session locks out of Doctrine's transactions
 * (sharing the PDO made "There is already an active transaction" on login + account writes).
 */
final class SessionHandlerFactory
{
    public static function create(Connection $connection): PdoSessionHandler
    {
        $p = $connection->getParams();
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'] ?? 'localhost', $p['port'] ?? 5432, $p['dbname'] ?? '');

        return new PdoSessionHandler($dsn, [
            'db_username' => $p['user'] ?? '',
            'db_password' => $p['password'] ?? '',
            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
        ]);
    }
}
