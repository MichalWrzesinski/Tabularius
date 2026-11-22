<?php

declare(strict_types=1);

namespace App\Module\Interpretation\OpenAi;

/**
 * Usage of one evaluation run; `spentToday()` is the run's total, so the interpreter's budget check caps the run.
 */
final class MemoryUsage implements Usage
{
    /** @var list<array{input: int, output: int, cost: float, latencyMs: int, outcome: string}> */
    public array $calls = [];

    public function spentToday(): float
    {
        return array_sum(array_column($this->calls, 'cost'));
    }

    public function record(?string $ticketId, string $model, string $prompt, int $input, int $output, float $cost, int $latencyMs, string $outcome): void
    {
        $this->calls[] = ['input' => $input, 'output' => $output, 'cost' => $cost, 'latencyMs' => $latencyMs, 'outcome' => $outcome];
    }
}
