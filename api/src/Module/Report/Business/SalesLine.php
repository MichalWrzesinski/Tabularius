<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

use Brick\Math\BigDecimal;

final readonly class SalesLine
{
    public function __construct(
        public string $documentNumber,
        public string $documentKind,
        public string $inclusionDate,
        public string $customerCode,
        public string $customerName,
        public string $partNumber,
        public string $manufacturerName,
        public string $category,
        public BigDecimal $quantity,
        public BigDecimal $netValue,
    ) {
    }
}
