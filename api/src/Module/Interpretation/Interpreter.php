<?php

declare(strict_types=1);

namespace App\Module\Interpretation;

/**
 * Text of a request → typed plan. Implementations: the rule-based baseline (here) and the LLM one (increment 4).
 */
interface Interpreter
{
    /** Identifier recorded as the plan's author reference (model, prompt or rule-set version). */
    public function version(): string;

    public function interpret(string $text, InterpretationContext $context): InterpretedPlan;
}
