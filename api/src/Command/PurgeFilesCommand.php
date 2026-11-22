<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\File\FileStore;
use App\Module\File\ReportFiles;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention (ACC-FILE-1, ID-DEMO-7): deletes the content of expired report files and marks them deleted; the metadata
 * stays for the ticket history. Run daily by the scheduler of the environment.
 */
#[AsCommand(name: 'app:files:purge', description: 'Delete expired report files')]
final class PurgeFilesCommand
{
    public function __construct(
        private readonly ReportFiles $files,
        private readonly FileStore $store,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $now = $this->clock->now();
        $expired = $this->files->expired($now);
        foreach ($expired as $file) {
            $this->store->delete($file['storage_key']);
            $this->files->markDeleted($file['id'], $now);
        }
        $io->success(\sprintf('Deleted %d expired file(s).', \count($expired)));

        return Command::SUCCESS;
    }
}
