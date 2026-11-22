<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Expected client error, rendered as Problem Details by ApiExceptionSubscriber.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $title,
    ) {
        parent::__construct($title);
    }
}
