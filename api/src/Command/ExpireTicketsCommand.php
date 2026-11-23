<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Ticket\TicketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Requests waiting for confirmation longer than 7 days are rejected (tickets.md). Run daily with the purge jobs.
 */
#[AsCommand(name: 'app:tickets:expire', description: 'Reject requests not confirmed within 7 days; hand unanswered questions and stalled tickets to the administrator')]
final class ExpireTicketsCommand
{
    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(\sprintf('Expired %d unconfirmed request(s), %d unanswered question(s), %d stalled ticket(s).', $this->tickets->expireUnconfirmed(), $this->tickets->expireClarifications(), $this->tickets->expireStalled()));

        return Command::SUCCESS;
    }
}
