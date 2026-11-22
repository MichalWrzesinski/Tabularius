<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// The test database (business_test) gets the current schema and the seed for the fixed reference date
// used by the known-answer tests. Both run as business_owner.
foreach ([
    ['bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction', '--allow-no-migration', '--quiet'],
    ['bin/console', 'app:seed', '--env=test', '--reference-date=2026-11-15', '--quiet'],
] as $command) {
    (new Process($command, dirname(__DIR__)))->mustRun();
}
