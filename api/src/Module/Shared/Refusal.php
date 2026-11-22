<?php

declare(strict_types=1);

namespace App\Module\Shared;

/**
 * Expected refusal of a request; `reason` is a stable code for the API (Problem Details `code`), `status` its HTTP
 * status. Rendered by Shared\Http\ApiSubscriber.
 */
class Refusal extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
