<?php

declare(strict_types=1);

namespace App\Module\Interpretation\Guard;

use App\Module\Interpretation\InterpretationContext;
use App\Module\Interpretation\InterpretedPlan;
use App\Module\Report\Period\PeriodException;
use App\Module\Report\Period\PeriodExpression;
use App\Module\Report\Profile\IntegrationProfile;

/**
 * Second layer of the business-scope guard (ADR 0005): whatever an interpreter returns — rules today, an LLM that may
 * have been manipulated tomorrow — only a plan made of closed, typed values within the owner's scope may be routed.
 * Anything else is a violation and nothing runs. Values are never repaired or partially accepted.
 */
final class PlanGuard
{
    private const int MAX_CUSTOMERS = 100;
    private const string SAFE_STRING = '/^[\pL\pN _.:+\-]{1,64}$/u';

    public function __construct(private readonly IntegrationProfile $profile)
    {
    }

    /**
     * @return list<string> violation codes (empty: the plan may be routed)
     */
    public function violations(InterpretedPlan $plan, InterpretationContext $context): array
    {
        $v = [];
        if (!\in_array($plan->intent, InterpretedPlan::INTENTS, true)) {
            $v[] = 'unknown_intent';
        }
        foreach ($plan->issues as $issue) {
            if (!\in_array($issue, InterpretedPlan::ISSUES, true)) {
                $v[] = 'unknown_issue';
                break;
            }
        }
        if ('operation' !== $plan->intent) {
            if (null !== $plan->operation || [] !== $plan->parameters) {
                $v[] = 'parameters_without_operation';
            }

            return array_values(array_unique($v));
        }
        $operation = null === $plan->operation ? null : ($this->profile->operations[$plan->operation] ?? null);
        if (null === $operation) {
            return [...$v, 'operation_outside_catalog'];
        }

        $scope = array_map(static fn ($c): string => $c->code, $context->customers);
        $manufacturers = array_map(static fn ($m): string => $m->code, $context->manufacturers);
        foreach ($plan->parameters as $name => $value) {
            if (!\array_key_exists($name, $operation->parameters)) {
                $v[] = 'unexpected_parameter';
                continue;
            }
            $v = [...$v, ...match ($name) {
                'period' => self::period($value),
                'customers' => self::customers($value, $scope),
                'customer' => self::customerCodes([$value], $scope),
                'manufacturer' => \is_string($value) && \in_array($value, $manufacturers, true) ? [] : ['unknown_manufacturer_value'],
                'category' => \is_string($value) && \in_array($value, $this->profile->categories, true) ? [] : ['unknown_category_value'],
                'policy' => \is_string($value) && 1 === preg_match('/^[0-9A-Za-z.-]{1,20}$/', $value) ? [] : ['invalid_policy_value'],
                default => ['unexpected_parameter'],
            }];
        }
        if (!self::safe($plan->parameters, 0)) {
            $v[] = 'unsafe_value';
        }

        return array_values(array_unique($v));
    }

    /**
     * @return list<string>
     */
    private static function period(mixed $value): array
    {
        if (!\is_array($value)) {
            return ['invalid_period_value'];
        }
        try {
            PeriodExpression::fromArray($value);
        } catch (PeriodException|\InvalidArgumentException) {
            return ['invalid_period_value'];
        }

        return [];
    }

    /**
     * @param list<string> $scope
     *
     * @return list<string>
     */
    private static function customers(mixed $value, array $scope): array
    {
        if ('all_assigned' === $value) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value) || [] === $value || \count($value) > self::MAX_CUSTOMERS) {
            return ['invalid_customers_value'];
        }

        return self::customerCodes($value, $scope);
    }

    /**
     * @param array<array-key, mixed> $codes
     * @param list<string>            $scope
     *
     * @return list<string>
     */
    private static function customerCodes(array $codes, array $scope): array
    {
        foreach ($codes as $code) {
            if (!\is_string($code) || 1 !== preg_match('/^K-\d{3}$/', $code)) {
                return ['invalid_customer_value'];
            }
            if (!\in_array($code, $scope, true)) {
                return ['customer_out_of_scope'];
            }
        }

        return [];
    }

    /** Defense in depth: only short, plain strings and integers; at most a period object or a list of codes. */
    private static function safe(mixed $value, int $depth): bool
    {
        return match (true) {
            \is_string($value) => 1 === preg_match(self::SAFE_STRING, $value),
            \is_int($value) => $value >= 0 && $value <= 100000,
            \is_array($value) => $depth < 2 && \count($value) <= self::MAX_CUSTOMERS && array_reduce($value, static fn (bool $ok, mixed $item): bool => $ok && self::safe($item, $depth + 1), true)
                && [] === array_filter(array_keys($value), static fn (int|string $k): bool => \is_string($k) && 1 !== preg_match('/^[a-z_]{1,20}$/', $k)),
            default => false,
        };
    }
}
