<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Module\Report\Business\DiscountPolicy;
use App\Module\Report\Business\DiscountTier;
use App\Module\Report\Execution\DiscountCalculator;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RPT-DISC-2/3 boundary table (docs/product/areas/reports.md), expected values written by hand.
 */
final class DiscountCalculatorTest extends TestCase
{
    private static function policy(string $validFrom = '2026-01-01', string $version = '2026.1'): DiscountPolicy
    {
        return new DiscountPolicy('DEMO-RABAT', $version, $validFrom, [
            new DiscountTier(BigDecimal::of('0.00'), BigDecimal::of('0.00')),
            new DiscountTier(BigDecimal::of('10000.00'), BigDecimal::of('0.03')),
            new DiscountTier(BigDecimal::of('50000.00'), BigDecimal::of('0.05')),
            new DiscountTier(BigDecimal::of('100000.00'), BigDecimal::of('0.07')),
        ], true);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function boundaries(): iterable
    {
        yield 'just below tier 2' => ['9999.99', '0', '0.00'];
        yield 'tier 2 lower bound inclusive' => ['10000.00', '0.03', '300.00'];
        yield '1 499.9997 rounds to 1 500.00' => ['49999.99', '0.03', '1500.00'];
        yield 'tier 3 lower bound' => ['50000.00', '0.05', '2500.00'];
        yield 'half up (half even would give 2 500.00)' => ['50000.10', '0.05', '2500.01'];
        yield '4 999.9995 rounds to 5 000.00' => ['99999.99', '0.05', '5000.00'];
        yield 'tier 4' => ['100000.00', '0.07', '7000.00'];
        yield 'adjustments exceed sales' => ['-120.00', '0', '0.00'];
        yield 'zero' => ['0.00', '0', '0.00'];
        yield '12 345.67 × 3 %' => ['12345.67', '0.03', '370.37'];
    }

    #[DataProvider('boundaries')]
    public function testBoundaries(string $basis, string $rate, string $discount): void
    {
        $result = (new DiscountCalculator())->simulate(self::policy(), BigDecimal::of($basis), true);

        self::assertTrue($result['rate']->isEqualTo($rate), (string) $result['rate']);
        self::assertSame($discount, (string) $result['discount']);
        self::assertSame((string) BigDecimal::of($basis)->minus($discount), (string) $result['net']);
    }

    public function testInactiveCustomerGetsNoDiscount(): void
    {
        $result = (new DiscountCalculator())->simulate(self::policy(), BigDecimal::of('150000.00'), false);

        self::assertSame('0.00', (string) $result['discount']);
        self::assertSame('klient nieaktywny', $result['reason']);
        self::assertNull($result['tier']);
    }

    public function testApplicablePolicyIsTheLatestValidOnTheReferenceDate(): void
    {
        $calculator = new DiscountCalculator();
        $policies = [self::policy('2026-01-01', '2026.1'), self::policy('2026-12-01', '2026.2')];

        self::assertSame('2026.1', $calculator->applicable($policies, new \DateTimeImmutable('2026-11-15'))?->version);
        self::assertSame('2026.2', $calculator->applicable($policies, new \DateTimeImmutable('2026-12-15'))?->version);
        self::assertSame('2026.1', $calculator->applicable($policies, new \DateTimeImmutable('2026-12-15'), '2026.1')?->version);
        self::assertNull($calculator->applicable($policies, new \DateTimeImmutable('2026-11-15'), '2026.2'), 'not yet valid');
        self::assertNull($calculator->applicable($policies, new \DateTimeImmutable('2025-06-01')));
    }
}
