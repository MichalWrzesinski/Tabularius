<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

/**
 * Plan parameters that do not fit the operation's schema; `reason` selects the question template.
 */
final class InvalidParametersException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
