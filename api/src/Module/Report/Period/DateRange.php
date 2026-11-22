<?php

declare(strict_types=1);

namespace App\Module\Report\Period;

/**
 * Inclusive calendar-date range in Europe/Warsaw (DATE-2). Instants are derived as [start 00:00, end+1 00:00).
 */
final readonly class DateRange
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
        if ($start > $end) {
            throw new \InvalidArgumentException('Range start is after its end');
        }
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Warsaw');
    }

    public static function of(string $start, string $end): self
    {
        return new self(self::date($start), self::date($end));
    }

    public static function date(string $ymd): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, self::zone());
        if (false === $date || $date->format('Y-m-d') !== $ymd) {
            throw new \InvalidArgumentException('Invalid date: '.$ymd);
        }

        return $date;
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    /** „01–31.10.2026”, „28.10–03.11.2026”, „15.12.2025–14.01.2026” (DATE-5, mockup format). */
    public function label(): string
    {
        if ($this->start->format('Y-m') === $this->end->format('Y-m')) {
            return $this->start->format('d').'–'.$this->end->format('d.m.Y');
        }
        if ($this->start->format('Y') === $this->end->format('Y')) {
            return $this->start->format('d.m').'–'.$this->end->format('d.m.Y');
        }

        return $this->start->format('d.m.Y').'–'.$this->end->format('d.m.Y');
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} UTC instants, end exclusive
     */
    public function instants(): array
    {
        $utc = new \DateTimeZone('UTC');

        return [$this->start->setTimezone($utc), $this->end->modify('+1 day')->setTimezone($utc)];
    }
}
