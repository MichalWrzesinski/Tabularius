<?php

declare(strict_types=1);

namespace App\Module\Identity;

/**
 * Expected refusal in account management; `reason` is a stable code for the API (Problem Details `code`).
 */
final class AccountException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
