<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

use App\Module\Report\Business\DiscountPolicy;
use App\Module\Report\Business\DiscountTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Tiered discount simulation (RPT-DISC-2/3): highest tier whose lower bound (inclusive) the basis reaches;
 * basis ≤ 0 or an inactive customer → 0 %; discount = basis × rate rounded half up to 0.01 once.
 */
final class DiscountCalculator
{
    /**
     * @return array{tier: ?DiscountTier, rate: BigDecimal, discount: BigDecimal, net: BigDecimal, reason: ?string}
     */
    public function simulate(DiscountPolicy $policy, BigDecimal $basis, bool $customerActive): array
    {
        $zero = BigDecimal::of('0.00');
        if (!$customerActive) {
            return ['tier' => null, 'rate' => BigDecimal::zero(), 'discount' => $zero, 'net' => $basis, 'reason' => 'klient nieaktywny'];
        }
        $selected = null;
        if ($basis->isPositive()) {
            foreach ($policy->tiers as $tier) {
                if ($basis->isGreaterThanOrEqualTo($tier->from)) {
                    $selected = $tier;
                }
            }
        }
        $rate = null === $selected ? BigDecimal::zero() : $selected->rate;
        $discount = $basis->isPositive() ? $basis->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp) : $zero;

        return ['tier' => $selected, 'rate' => $rate, 'discount' => $discount, 'net' => $basis->minus($discount), 'reason' => null];
    }

    /**
     * Policy valid on the reference date: the latest `validFrom` not after it, optionally a named version.
     *
     * @param list<DiscountPolicy> $policies
     */
    public function applicable(array $policies, \DateTimeImmutable $reference, ?string $version = null): ?DiscountPolicy
    {
        $date = $reference->format('Y-m-d');
        $candidates = array_filter($policies, static fn (DiscountPolicy $p): bool => $p->validFrom <= $date && (null === $version || $p->version === $version));
        usort($candidates, static fn (DiscountPolicy $a, DiscountPolicy $b): int => strcmp($b->validFrom, $a->validFrom));

        return $candidates[0] ?? null;
    }
}
