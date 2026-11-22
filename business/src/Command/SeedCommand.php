<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\DemoDataSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Replaces all business data with the deterministic demo seed, relative to a reference date
 * (default: today in Europe/Warsaw). Refuses unless BUSINESS_ALLOW_SEED=1.
 */
#[AsCommand(name: 'app:seed', description: 'Load (or refresh) the synthetic demo business data')]
final class SeedCommand
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.owner_connection')]
        private readonly Connection $owner,
        private readonly DemoDataSeeder $seeder,
        #[Autowire(env: 'bool:BUSINESS_ALLOW_SEED')]
        private readonly bool $allowSeed,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Reference date YYYY-MM-DD (data is generated around it)')]
        ?string $referenceDate = null,
    ): int {
        if (!$this->allowSeed) {
            $io->error('Seeding is disabled here (BUSINESS_ALLOW_SEED is not 1). It replaces all business data.');

            return Command::FAILURE;
        }
        $zone = new \DateTimeZone('Europe/Warsaw');
        if (null === $referenceDate) {
            $reference = new \DateTimeImmutable('today', $zone);
        } else {
            $reference = \DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate, $zone);
            if (false === $reference || $reference->format('Y-m-d') !== $referenceDate) {
                $io->error('Invalid --reference-date, expected YYYY-MM-DD.');

                return Command::INVALID;
            }
        }

        $this->seeder->write($this->owner, $reference);
        $io->success(\sprintf(
            'Seed %s (random seed %d) loaded for reference date %s. Checksum %s.',
            DemoDataSeeder::DATA_VERSION,
            DemoDataSeeder::RANDOM_SEED,
            $reference->format('Y-m-d'),
            DemoDataSeeder::checksum($this->owner),
        ));

        return Command::SUCCESS;
    }
}
