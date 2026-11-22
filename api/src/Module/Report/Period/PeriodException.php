<?php

declare(strict_types=1);

namespace App\Module\Report\Period;

/**
 * A period that needs a question or correction (DATE-3/4), never silently adjusted. `code` maps to a
 * backend question template; `suggestion` is the nearest valid range, when one exists.
 */
final class PeriodException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?DateRange $suggestion = null,
    ) {
        parent::__construct($reason);
    }
}
