<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Module\Report\Period\DateRange;
use App\Module\Report\Period\PeriodException;
use App\Module\Report\Period\PeriodExpression;
use App\Module\Report\Period\PeriodResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DATE-* rules and the examples table in docs/product/areas/reports.md (reference date 2026-11-15, a Sunday).
 */
final class PeriodResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function valid(): iterable
    {
        yield 'yesterday' => [['type' => 'yesterday'], '2026-11-15', '2026-11-14', '2026-11-14'];
        yield 'last 7 days' => [['type' => 'last_n_days', 'n' => 7], '2026-11-15', '2026-11-08', '2026-11-14'];
        yield 'previous week (Mon–Sun before the current week)' => [['type' => 'previous_week'], '2026-11-15', '2026-11-02', '2026-11-08'];
        yield 'previous week on a Monday' => [['type' => 'previous_week'], '2026-11-16', '2026-11-09', '2026-11-15'];
        yield 'last month' => [['type' => 'previous_month'], '2026-11-15', '2026-10-01', '2026-10-31'];
        yield 'last month in January' => [['type' => 'previous_month'], '2026-01-10', '2025-12-01', '2025-12-31'];
        yield 'last month from 31 March (February, leap-safe)' => [['type' => 'previous_month'], '2028-03-31', '2028-02-01', '2028-02-29'];
        yield 'this month to date' => [['type' => 'current_month_to_date'], '2026-11-15', '2026-11-01', '2026-11-14'];
        yield 'October without year' => [['type' => 'month', 'month' => 10], '2026-11-15', '2026-10-01', '2026-10-31'];
        yield 'November without year = last completed November' => [['type' => 'month', 'month' => 11], '2026-11-15', '2025-11-01', '2025-11-30'];
        yield 'December without year in November' => [['type' => 'month', 'month' => 12], '2026-11-15', '2025-12-01', '2025-12-31'];
        yield 'month with year' => [['type' => 'month', 'month' => 2, 'year' => 2024], '2026-11-15', '2024-02-01', '2024-02-29'];
        yield 'Q3 without year' => [['type' => 'quarter', 'quarter' => 3], '2026-11-15', '2026-07-01', '2026-09-30'];
        yield 'Q4 without year (current quarter → previous year)' => [['type' => 'quarter', 'quarter' => 4], '2026-11-15', '2025-10-01', '2025-12-31'];
        yield 'year 2025' => [['type' => 'year', 'year' => 2025], '2026-11-15', '2025-01-01', '2025-12-31'];
        yield 'last year' => [['type' => 'previous_year'], '2026-11-15', '2025-01-01', '2025-12-31'];
        yield 'year to date' => [['type' => 'current_year_to_date'], '2026-11-15', '2026-01-01', '2026-11-14'];
        yield 'explicit inclusive' => [['type' => 'explicit', 'start' => '2026-10-01', 'end' => '2026-10-31'], '2026-11-15', '2026-10-01', '2026-10-31'];
        yield 'explicit single day yesterday' => [['type' => 'explicit', 'start' => '2026-11-14', 'end' => '2026-11-14'], '2026-11-15', '2026-11-14', '2026-11-14'];
    }

    /**
     * @param array<string, mixed> $expression
     */
    #[DataProvider('valid')]
    public function testResolves(array $expression, string $reference, string $start, string $end): void
    {
        $range = (new PeriodResolver())->resolve(PeriodExpression::fromArray($expression), DateRange::date($reference));

        self::assertSame([$start, $end], [$range->start->format('Y-m-d'), $range->end->format('Y-m-d')]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, ?string}>
     */
    public static function invalid(): iterable
    {
        yield 'explicit including today → correction, suggest 1–14' => [['type' => 'explicit', 'start' => '2026-11-01', 'end' => '2026-11-15'], '2026-11-15', 'period_includes_today', '2026-11-01..2026-11-14'];
        yield 'explicit in the future' => [['type' => 'explicit', 'start' => '2026-11-20', 'end' => '2026-11-25'], '2026-11-15', 'period_includes_today', null];
        yield 'this month on the 1st' => [['type' => 'current_month_to_date'], '2026-11-01', 'no_completed_period', null];
        yield 'this year on 1 January' => [['type' => 'current_year_to_date'], '2027-01-01', 'no_completed_period', null];
        yield 'current year as a whole' => [['type' => 'year', 'year' => 2026], '2026-11-15', 'period_includes_today', '2026-01-01..2026-11-14'];
        yield 'month of a future year' => [['type' => 'month', 'month' => 3, 'year' => 2027], '2026-11-15', 'period_includes_today', null];
        yield 'start after end' => [['type' => 'explicit', 'start' => '2026-10-31', 'end' => '2026-10-01'], '2026-11-15', 'start_after_end', null];
        yield 'longer than 366 days' => [['type' => 'explicit', 'start' => '2024-01-01', 'end' => '2025-12-31'], '2026-11-15', 'period_too_long', null];
        yield 'last 400 days' => [['type' => 'last_n_days', 'n' => 400], '2026-11-15', 'period_too_long', null];
        yield 'before 2020' => [['type' => 'year', 'year' => 2019], '2026-11-15', 'period_too_early', null];
        yield 'month 13' => [['type' => 'month', 'month' => 13], '2026-11-15', 'invalid_period', null];
        yield 'quarter 5' => [['type' => 'quarter', 'quarter' => 5], '2026-11-15', 'invalid_period', null];
    }

    /**
     * @param array<string, mixed> $expression
     */
    #[DataProvider('invalid')]
    public function testRejects(array $expression, string $reference, string $reason, ?string $suggestion): void
    {
        try {
            (new PeriodResolver())->resolve(PeriodExpression::fromArray($expression), DateRange::date($reference));
            self::fail('Expected '.$reason);
        } catch (PeriodException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($suggestion, null === $e->suggestion ? null : $e->suggestion->start->format('Y-m-d').'..'.$e->suggestion->end->format('Y-m-d'));
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function malformed(): iterable
    {
        yield 'unknown type' => [['type' => 'next_month']];
        yield 'no type' => [['month' => 10]];
        yield 'extra field' => [['type' => 'previous_month', 'sql' => 'x']];
        yield 'month missing' => [['type' => 'month']];
        yield 'month as string' => [['type' => 'month', 'month' => '10']];
        yield 'n zero' => [['type' => 'last_n_days', 'n' => 0]];
        yield 'bad date' => [['type' => 'explicit', 'start' => '2026-02-30', 'end' => '2026-03-01']];
        yield 'date as int' => [['type' => 'explicit', 'start' => 20261001, 'end' => '2026-10-31']];
        yield 'end missing' => [['type' => 'explicit', 'start' => '2026-10-01']];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('malformed')]
    public function testRejectsMalformedExpressions(array $data): void
    {
        $this->expectException(PeriodException::class);
        PeriodExpression::fromArray($data);
    }

    public function testReferenceInstantIsReadInWarsawTime(): void
    {
        // 23:30 UTC on 14 Nov is already 15 Nov in Warsaw, so "yesterday" is 14 Nov.
        $range = (new PeriodResolver())->resolve(PeriodExpression::fromArray(['type' => 'yesterday']), new \DateTimeImmutable('2026-11-14T23:30:00Z'));
        self::assertSame('2026-11-14', $range->start->format('Y-m-d'));
    }

    public function testDateRangeLabelsAndInstantsAcrossDst(): void
    {
        $october = DateRange::of('2026-10-01', '2026-10-31');
        self::assertSame('01–31.10.2026', $october->label());
        self::assertSame(31, $october->days());
        [$from, $to] = $october->instants();
        self::assertSame('2026-09-30T22:00:00+00:00', $from->format(\DATE_ATOM)); // CEST
        self::assertSame('2026-10-31T23:00:00+00:00', $to->format(\DATE_ATOM));   // CET after the DST change
        self::assertSame('28.10–03.11.2026', DateRange::of('2026-10-28', '2026-11-03')->label());
        self::assertSame('15.12.2025–14.01.2026', DateRange::of('2025-12-15', '2026-01-14')->label());
    }

    public function testDateRangeRejectsInvertedOrInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::of('2026-10-31', '2026-10-01');
    }

    public function testDateRejectsImpossibleDates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::date('2026-02-30');
    }
}
