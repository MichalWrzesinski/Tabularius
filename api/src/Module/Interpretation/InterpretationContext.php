<?php

declare(strict_types=1);

namespace App\Module\Interpretation;

use App\Module\Report\Business\Customer;
use App\Module\Report\Business\Manufacturer;

/**
 * What an interpreter may know: the owner's own customers (scope from trusted context), the manufacturers and
 * categories of the profile, and the reference date (never computed by the interpreter itself).
 */
final readonly class InterpretationContext
{
    /**
     * @param list<Customer>     $customers
     * @param list<Manufacturer> $manufacturers
     * @param list<string>       $categories
     */
    public function __construct(
        public array $customers,
        public array $manufacturers,
        public array $categories,
        public \DateTimeImmutable $reference,
    ) {
    }
}
