<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends one test error through the normal path (error log → Sentry, scrubbed), with a fake address and token in it, so
 * the owner can check on staging that the report arrives and carries neither (TASK-015, runbook: Monitoring).
 */
#[AsCommand(name: 'app:monitoring:test', description: 'Log a test error for the error-reporting check')]
final class MonitoringTestCommand
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->logger->error('Monitoring test (TASK-015) for test.address@example.com', [
            'exception' => new \RuntimeException('Monitoring test exception with token='.str_repeat('0123456789abcdef', 2)),
            'email' => 'test.address@example.com',
        ]);
        $io->success('Test error logged. In Sentry it must show neither the address nor the token.');

        return Command::SUCCESS;
    }
}
