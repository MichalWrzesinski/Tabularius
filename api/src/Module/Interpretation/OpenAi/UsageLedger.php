<?php

declare(strict_types=1);

namespace App\Module\Interpretation\OpenAi;

use App\Module\Ticket\TicketStore;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Daily spend guard and usage record of the LLM (ADR 0004 limits): tokens and cost per call, never text.
 */
final class UsageLedger implements Usage
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Spent today (Europe/Warsaw calendar day) in USD. */
    public function spentToday(): float
    {
        $start = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Warsaw'))->setTime(0, 0);
        $spent = $this->db->fetchOne('SELECT coalesce(sum(cost_usd), 0) FROM ai_usage WHERE at >= ?', [TicketStore::ts($start)]);

        return is_numeric($spent) ? (float) $spent : 0.0;
    }

    public function record(?string $ticketId, string $model, string $prompt, int $input, int $output, float $cost, int $latencyMs, string $outcome): void
    {
        $this->db->insert('ai_usage', [
            'at' => TicketStore::ts($this->clock->now()),
            'ticket_id' => $ticketId,
            'model' => $model,
            'prompt_version' => $prompt,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => \sprintf('%.6F', $cost),
            'latency_ms' => $latencyMs,
            'outcome' => $outcome,
        ]);
    }
}
