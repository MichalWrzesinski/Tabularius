<?php

declare(strict_types=1);

namespace App\Module\Interpretation\OpenAi;

/**
 * The model could not interpret: provider failure (`ai_provider_error`) or exhausted limit (`ai_limit`). The ticket
 * is kept for manual handling; it is never reported as success (CP-005, AC-6).
 */
final class AiUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
