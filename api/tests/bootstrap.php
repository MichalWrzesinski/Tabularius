<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// tabularius_test gets the current schema, migrated as tabularius_migrator (the app role cannot run DDL).
$migrations = $_SERVER['MIGRATIONS_DATABASE_URL'] ?? getenv('MIGRATIONS_DATABASE_URL');
if (is_string($migrations) && '' !== $migrations) {
    (new Process(
        ['bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction', '--allow-no-migration', '--quiet'],
        dirname(__DIR__),
        ['DATABASE_URL' => $migrations],
    ))->mustRun();
}
