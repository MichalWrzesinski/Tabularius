<?php

declare(strict_types=1);

namespace App\Module\Report\Period;

/**
 * Closed set of period expressions the interpreter may return (docs/product/areas/reports.md#reporting-periods).
 * The model never computes dates; the backend resolves an expression against the reference date.
 */
final readonly class PeriodExpression
{
    public const array TYPES = [
        'yesterday' => [],
        'last_n_days' => ['n'],
        'previous_week' => [],
        'previous_month' => [],
        'current_month_to_date' => [],
        'month' => ['month', 'year'],
        'quarter' => ['quarter', 'year'],
        'year' => ['year'],
        'previous_year' => [],
        'current_year_to_date' => [],
        'explicit' => ['start', 'end'],
    ];

    /**
     * @param array<string, int|string> $values
     */
    private function __construct(public string $type, public array $values)
    {
    }

    /**
     * Strict parsing: unknown types, unknown or extra fields and wrong value types are rejected.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (!\is_string($type) || !\array_key_exists($type, self::TYPES)) {
            throw new PeriodException('invalid_period');
        }
        $allowed = self::TYPES[$type];
        $values = [];
        foreach ($data as $key => $value) {
            if ('type' === $key) {
                continue;
            }
            if (!\in_array($key, $allowed, true)) {
                throw new PeriodException('invalid_period');
            }
            $values[$key] = match ($key) {
                'start', 'end' => self::dateString($value),
                default => self::positiveInt($value),
            };
        }
        $required = match ($type) {
            'last_n_days' => ['n'],
            'month' => ['month'],
            'quarter' => ['quarter'],
            'year' => ['year'],
            'explicit' => ['start', 'end'],
            default => [],
        };
        foreach ($required as $key) {
            if (!\array_key_exists($key, $values)) {
                throw new PeriodException('invalid_period');
            }
        }

        return new self($type, $values);
    }

    public function int(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!\is_string($value)) {
            throw new PeriodException('invalid_period');
        }

        return $value;
    }

    private static function positiveInt(mixed $value): int
    {
        if (!\is_int($value) || $value < 1 || $value > 9999) {
            throw new PeriodException('invalid_period');
        }

        return $value;
    }

    private static function dateString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new PeriodException('invalid_period');
        }
        try {
            DateRange::date($value);
        } catch (\InvalidArgumentException) {
            throw new PeriodException('invalid_period');
        }

        return $value;
    }
}
