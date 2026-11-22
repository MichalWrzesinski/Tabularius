<?php

declare(strict_types=1);

namespace App\Module\Mail\Job;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Polls the mailbox every MAIL_POLL_SECONDS in the worker (`messenger:consume async scheduler_mail`). The lock keeps
 * one poller at a time even with several workers; missed runs collapse into one.
 */
#[AsSchedule('mail')]
final class MailSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly LockFactory $locks,
        #[Autowire(env: 'int:MAIL_POLL_SECONDS')]
        private readonly int $seconds = 60,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::every(\sprintf('%d seconds', max(15, $this->seconds)), new PollMailbox()))
            ->lock($this->locks->createLock('mail-poll', 300))
            ->processOnlyLastMissedRun(true);
    }
}
