<?php

declare(strict_types=1);

namespace App\Module\Notification\Job;

final readonly class SendNotification
{
    public function __construct(public string $outboxId)
    {
    }
}
