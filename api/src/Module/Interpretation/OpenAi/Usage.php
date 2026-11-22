<?php

declare(strict_types=1);

namespace App\Module\Interpretation\OpenAi;

/**
 * Where LLM usage is counted: the database in the application (daily spend guard), memory in an evaluation run
 * (per-run cost cap). Never message text.
 */
interface Usage
{
    public function spentToday(): float;

    public function record(?string $ticketId, string $model, string $prompt, int $input, int $output, float $cost, int $latencyMs, string $outcome): void;
}
