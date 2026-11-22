<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Identity\AccountProvider;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo mode only: queues three manual tickets of the shared demo representative (one per catalog operation) so the
 * dashboard and „Moje raporty” have real results on a fresh environment. Skipped when tickets already exist.
 * The worker executes them like any other ticket.
 */
#[AsCommand(name: 'app:tickets:demo', description: 'Queue sample manual tickets for the shared demo representative')]
final class DemoTicketsCommand
{
    public function __construct(
        private readonly AccountProvider $accounts,
        private readonly TicketService $tickets,
        private readonly TicketStore $store,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $admin = $this->accounts->findByEmail('admin@example.com');
        $rep = $this->accounts->findByEmail('handlowiec@example.com');
        if ('demo' !== $this->mode || null === $admin || null === $rep) {
            $io->error('Sample tickets need demo mode and the shared demo accounts (bin/console app:accounts:demo).');

            return Command::FAILURE;
        }
        if ([] !== $this->store->list(limit: 1)) {
            $io->note('Tickets already exist; nothing queued.');

            return Command::SUCCESS;
        }
        $owner = $rep->id()->toRfc4122();
        $this->tickets->createManual($admin, $owner, 'sales_report', ['period' => ['type' => 'previous_month'], 'customers' => 'all_assigned']);
        $this->tickets->createManual($admin, $owner, 'parts_catalog', ['manufacturer' => 'BOSCH']);
        $this->tickets->createManual($admin, $owner, 'customer_discount_simulation', ['customer' => 'K-004', 'period' => ['type' => 'previous_month']]);
        $io->success('Queued 3 sample tickets; the worker generates their files.');

        return Command::SUCCESS;
    }
}
