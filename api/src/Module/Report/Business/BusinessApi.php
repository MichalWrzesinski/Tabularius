<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

use App\Module\Report\Period\DateRange;

/**
 * Port to the organization's business system (ADR 0002). Implementations validate every response at the boundary
 * and translate transport/provider failures into DataSourceException. Scope (the representative) always comes
 * from trusted context, never from interpreted parameters.
 */
interface BusinessApi
{
    /**
     * @return list<string> representative codes known to the business system
     */
    public function salesReps(): array;

    /**
     * @return list<Customer>
     */
    public function customersOf(string $salesRep): array;

    /**
     * @param list<string> $customerCodes empty = all customers currently assigned to the representative
     *
     * @return list<SalesLine>
     */
    public function salesLines(string $salesRep, DateRange $period, array $customerCodes = [], ?string $manufacturer = null, ?string $category = null): array;

    /**
     * @return list<Manufacturer>
     */
    public function manufacturers(): array;

    /**
     * @return list<Part> active parts with permitted fields only
     */
    public function parts(string $manufacturerCode): array;

    /**
     * @return list<DiscountPolicy>
     */
    public function discountPolicies(): array;

    public function dataVersion(): string;
}
