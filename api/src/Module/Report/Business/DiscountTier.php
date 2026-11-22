<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

use Brick\Math\BigDecimal;

final readonly class DiscountTier
{
    public function __construct(public BigDecimal $from, public BigDecimal $rate)
    {
    }
}
