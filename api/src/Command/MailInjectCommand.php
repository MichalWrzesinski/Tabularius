<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Mail\IncomingMessage;
use App\Module\Mail\Ingestor;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Local mailbox stand-in: ingests one .eml file as if it had arrived now (development, tests, E2E). Refused when a
 * real mailbox is configured, so it cannot bypass the mailbox on staging.
 */
#[AsCommand(name: 'app:mail:inject', description: 'Ingest an .eml file as an incoming message (local stack only)')]
final class MailInjectCommand
{
    public function __construct(
        private readonly Ingestor $ingestor,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'MAILBOX_DSN')]
        private readonly string $mailbox,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Path to an RFC 5322 message (.eml)')]
        string $file,
        #[Option(description: 'Receive time (ISO 8601), default now')]
        ?string $receivedAt = null,
    ): int {
        if ('' !== trim($this->mailbox)) {
            $io->error('A real mailbox is configured (MAILBOX_DSN); messages come only from it.');

            return Command::FAILURE;
        }
        $raw = is_file($file) ? file_get_contents($file) : false;
        if (false === $raw) {
            $io->error('Cannot read the file.');

            return Command::INVALID;
        }
        $at = null === $receivedAt ? $this->clock->now() : new \DateTimeImmutable($receivedAt);
        $outcome = $this->ingestor->ingest(new IncomingMessage('local', null, null, $raw, \strlen($raw), $at));
        $io->success('Outcome: '.$outcome);

        return Command::SUCCESS;
    }
}
