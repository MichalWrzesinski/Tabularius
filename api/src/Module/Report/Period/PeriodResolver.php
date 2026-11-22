<?php

declare(strict_types=1);

namespace App\Module\Report\Period;

/**
 * Resolves a period expression against the reference date (the Warsaw date the mailbox received the message)
 * into an inclusive range of completed days (DATE-1…DATE-4). Invalid periods raise PeriodException with a code
 * for a backend question; nothing is shortened silently.
 */
final class PeriodResolver
{
    public const int MAX_DAYS = 366;
    public const string EARLIEST = '2020-01-01';

    public function resolve(PeriodExpression $expression, \DateTimeImmutable $reference): DateRange
    {
        $today = \DateTimeImmutable::createFromFormat('!Y-m-d', $reference->setTimezone(DateRange::zone())->format('Y-m-d'), DateRange::zone());
        \assert($today instanceof \DateTimeImmutable);
        $yesterday = $today->modify('-1 day');
        $thisMonth = $today->modify('first day of this month');

        $range = match ($expression->type) {
            'yesterday' => new DateRange($yesterday, $yesterday),
            'last_n_days' => $this->lastDays((int) $expression->int('n'), $yesterday),
            'previous_week' => $this->previousWeek($today),
            'previous_month' => new DateRange($thisMonth->modify('-1 month'), $thisMonth->modify('-1 day')),
            'current_month_to_date' => $this->toDate($thisMonth, $yesterday),
            'month' => $this->month((int) $expression->int('month'), $expression->int('year'), $today),
            'quarter' => $this->quarter((int) $expression->int('quarter'), $expression->int('year'), $today),
            'year' => $this->year((int) $expression->int('year'), $today),
            'previous_year' => $this->year((int) $today->format('Y') - 1, $today),
            'current_year_to_date' => $this->toDate($today->setDate((int) $today->format('Y'), 1, 1), $yesterday),
            'explicit' => $this->explicit($expression->string('start'), $expression->string('end')),
            default => throw new PeriodException('invalid_period'),
        };

        return $this->validate($range, $yesterday);
    }

    private function lastDays(int $n, \DateTimeImmutable $yesterday): DateRange
    {
        if ($n > self::MAX_DAYS) {
            throw new PeriodException('period_too_long');
        }

        return new DateRange($yesterday->modify(\sprintf('-%d days', $n - 1)), $yesterday);
    }

    private function previousWeek(\DateTimeImmutable $today): DateRange
    {
        $monday = $today->modify('-'.((int) $today->format('N') - 1).' days');

        return new DateRange($monday->modify('-7 days'), $monday->modify('-1 day'));
    }

    private function toDate(\DateTimeImmutable $start, \DateTimeImmutable $yesterday): DateRange
    {
        if ($yesterday < $start) {
            throw new PeriodException('no_completed_period');
        }

        return new DateRange($start, $yesterday);
    }

    /**
     * A month without a year means its most recent completed occurrence (DATE: „listopad” on 15 Nov 2026 → Nov 2025).
     */
    private function month(int $month, ?int $year, \DateTimeImmutable $today): DateRange
    {
        if ($month > 12) {
            throw new PeriodException('invalid_period');
        }
        if (null === $year) {
            $year = (int) $today->format('Y');
            if ($month >= (int) $today->format('n')) {
                --$year;
            }
        }
        $start = $today->setDate($year, $month, 1);

        return new DateRange($start, $start->modify('last day of this month'));
    }

    private function quarter(int $quarter, ?int $year, \DateTimeImmutable $today): DateRange
    {
        if ($quarter > 4) {
            throw new PeriodException('invalid_period');
        }
        if (null === $year) {
            $year = (int) $today->format('Y');
            $current = intdiv((int) $today->format('n') - 1, 3) + 1;
            if ($quarter >= $current) {
                --$year;
            }
        }
        $start = $today->setDate($year, ($quarter - 1) * 3 + 1, 1);

        return new DateRange($start, $start->modify('+2 months')->modify('last day of this month'));
    }

    private function year(int $year, \DateTimeImmutable $today): DateRange
    {
        return new DateRange($today->setDate($year, 1, 1), $today->setDate($year, 12, 31));
    }

    private function explicit(string $start, string $end): DateRange
    {
        $from = DateRange::date($start);
        $to = DateRange::date($end);
        if ($from > $to) {
            throw new PeriodException('start_after_end');
        }

        return new DateRange($from, $to);
    }

    private function validate(DateRange $range, \DateTimeImmutable $yesterday): DateRange
    {
        if ($range->end > $yesterday) {
            // DATE-3: includes today or the future → correction; suggest the completed part, if any.
            throw new PeriodException('period_includes_today', $range->start <= $yesterday ? new DateRange($range->start, $yesterday) : null);
        }
        if ($range->start < DateRange::date(self::EARLIEST)) {
            throw new PeriodException('period_too_early');
        }
        if ($range->days() > self::MAX_DAYS) {
            throw new PeriodException('period_too_long');
        }

        return $range;
    }
}
