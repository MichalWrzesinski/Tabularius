<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A numeric CSV cell formatted by code (decimal comma, no grouping). Numeric cells are not subject to the
 * formula guard, so a negative amount stays a number in the spreadsheet (RPT-OUT-4).
 */
final readonly class NumberCell
{
    private function __construct(public string $text)
    {
    }

    public static function money(BigDecimal $value): self
    {
        return new self(str_replace('.', ',', (string) $value->toScale(2, RoundingMode::Unnecessary)));
    }

    public static function quantity(BigDecimal $value): self
    {
        return new self(str_replace('.', ',', (string) $value->strippedOfTrailingZeros()));
    }

    public static function integer(int $value): self
    {
        return new self((string) $value);
    }
}
