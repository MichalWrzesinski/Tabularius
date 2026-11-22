<?php

declare(strict_types=1);

namespace App\Module\Interpretation;

use App\Module\Interpretation\OpenAi\OpenAiInterpreter;

/**
 * The interpreter in use: the LLM when its key is configured (OPENAI_API_KEY), otherwise the rule-based baseline
 * (local stack, CI). Both sit behind the same guards (ADR 0005).
 */
final class SelectedInterpreter implements Interpreter
{
    public function __construct(
        private readonly OpenAiInterpreter $openAi,
        private readonly RuleInterpreter $rules,
    ) {
    }

    public function version(): string
    {
        return $this->current()->version();
    }

    public function interpret(string $text, InterpretationContext $context): InterpretedPlan
    {
        return $this->current()->interpret($text, $context);
    }

    private function current(): Interpreter
    {
        return $this->openAi->configured() ? $this->openAi : $this->rules;
    }
}
