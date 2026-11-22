<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Mail\MailStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Retention of message contents (ID-DEMO-7): sender, subject, header block and text are deleted after
 * MAIL_RETENTION_DAYS (default 7); metadata and the pseudonymous sender hash stay. Run daily with app:files:purge.
 */
#[AsCommand(name: 'app:mail:purge', description: 'Delete the contents of old incoming messages')]
final class MailPurgeCommand
{
    public function __construct(
        private readonly MailStore $mail,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:MAIL_RETENTION_DAYS')]
        private readonly int $days = 7,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $now = $this->clock->now();
        $count = $this->mail->purgeContents($now->modify(\sprintf('-%d days', $this->days)), $now);
        $io->success(\sprintf('Purged the contents of %d message(s).', $count));

        return Command::SUCCESS;
    }
}
