<?php

declare(strict_types=1);

namespace App\Module\Interpretation;

/**
 * The typed plan an interpreter returns (tickets.md, TKT-PLAN). Customer and manufacturer values are references to
 * resolve within the owner's scope; nothing here carries a recipient, a scope, SQL or a path. Valid structure is not
 * proof of correct intent: the backend validates and authorizes it independently.
 */
final readonly class InterpretedPlan
{
    /** Issues that do not block execution; they are recorded and answered by the normal route. */
    public const array NON_BLOCKING = ['recipient_change_requested'];

    public const array INTENTS = ['operation', 'multiple_operations', 'out_of_catalog', 'unclear'];

    /** Closed set of issue codes an interpreter may return (they select backend templates; never free text). */
    public const array ISSUES = [
        'missing_period', 'ambiguous_period', 'missing_customer', 'ambiguous_customer', 'unknown_customer',
        'missing_manufacturer', 'ambiguous_manufacturer', 'unknown_manufacturer', 'ambiguous_category',
        'unknown_operation', 'multiple_operations', 'out_of_catalog', 'recipient_change_requested',
    ];

    /**
     * Any string is accepted here on purpose: an LLM may return anything, and PlanGuard decides (INTENTS).
     *
     * @param array<string, mixed> $parameters
     * @param list<string>         $issues
     */
    public function __construct(
        public string $intent,
        public ?string $operation,
        public array $parameters,
        public array $issues,
    ) {
    }

    /**
     * @return list<string>
     */
    public function blockingIssues(): array
    {
        return array_values(array_diff($this->issues, self::NON_BLOCKING));
    }
}
