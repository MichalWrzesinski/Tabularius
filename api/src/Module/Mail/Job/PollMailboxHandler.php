<?php

declare(strict_types=1);

namespace App\Module\Mail\Job;

use App\Module\Mail\MailPoller;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PollMailboxHandler
{
    public function __construct(private readonly MailPoller $poller)
    {
    }

    public function __invoke(PollMailbox $message): void
    {
        $this->poller->poll();
    }
}
