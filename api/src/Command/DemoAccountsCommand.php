<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Identity\Account;
use App\Module\Identity\AccountProvider;
use App\Module\Identity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates or resets the shared demo accounts shown on the login screens (brief §4.1). Only in demo mode;
 * production must not have public credentials.
 */
#[AsCommand(name: 'app:accounts:demo', description: 'Create or reset the shared demo administrator and representative')]
final class DemoAccountsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountProvider $accounts,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
        #[Autowire(env: 'DEMO_SHARED_PASSWORD')]
        private readonly string $password,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('demo' !== $this->mode || '' === $this->password) {
            $io->error('Shared demo accounts exist only in demo mode (APP_MODE=demo, DEMO_SHARED_PASSWORD set).');

            return Command::FAILURE;
        }
        foreach ([
            ['admin@example.com', 'Administrator', Role::Admin, null],
            ['handlowiec@example.com', 'Handlowiec demonstracyjny', Role::Rep, 'PH-01'],
        ] as [$email, $name, $role, $rep]) {
            $account = $this->accounts->findByEmail($email);
            if (null === $account) {
                $account = new Account($email, $name, $role, $rep, 'pending', $this->clock->now(), shared: true);
                $this->em->persist($account);
            }
            $account->changePassword($this->hasher->hashPassword($account, $this->password), $this->clock->now());
            $account->unblock();
        }
        $this->em->flush();
        $io->success('Shared demo accounts are ready.');

        return Command::SUCCESS;
    }
}
