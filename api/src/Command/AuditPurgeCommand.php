<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Audit\AuditLog;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention of the pseudonymous audit log (ID-DEMO-7, owner decision 2026-11-21): events older than 90 days are
 * deleted. Runs daily in the `purge` service with the other retention commands.
 */
#[AsCommand(name: 'app:audit:purge', description: 'Delete audit events older than 90 days')]
final class AuditPurgeCommand
{
    public const int DAYS = 90;

    public function __construct(private readonly AuditLog $audit, private readonly ClockInterface $clock)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->audit->purge($this->clock->now()->modify(\sprintf('-%d days', self::DAYS)));
        $io->success(\sprintf('Deleted %d audit event(s) older than %d days.', $count, self::DAYS));

        return Command::SUCCESS;
    }
}
