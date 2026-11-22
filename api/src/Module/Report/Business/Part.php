<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

use Brick\Math\BigDecimal;

final readonly class Part
{
    public function __construct(
        public string $manufacturerName,
        public string $partNumber,
        public string $name,
        public string $category,
        public string $unit,
        public BigDecimal $listPriceNet,
    ) {
    }
}
