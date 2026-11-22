<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Period\DateRange;

/**
 * Test double for functional tests that need the business system only for representative codes.
 */
final class StubBusinessApi implements BusinessApi
{
    public function salesReps(): array
    {
        return ['PH-01', 'PH-02', 'PH-03', 'PH-04', 'PH-05'];
    }

    public function customersOf(string $salesRep): array
    {
        return [];
    }

    public function salesLines(string $salesRep, DateRange $period, array $customerCodes = [], ?string $manufacturer = null, ?string $category = null): array
    {
        return [];
    }

    public function manufacturers(): array
    {
        return [];
    }

    public function parts(string $manufacturerCode): array
    {
        return [];
    }

    public function discountPolicies(): array
    {
        return [];
    }

    public function dataVersion(): string
    {
        return 'test';
    }
}
