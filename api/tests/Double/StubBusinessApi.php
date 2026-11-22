<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Business\Customer;
use App\Module\Report\Business\DataSourceException;
use App\Module\Report\Business\RestBusinessApi;
use App\Module\Report\Period\DateRange;

/**
 * Business system for functional tests: the real REST adapter over captured responses (FixtureBusinessHttp, PH-01,
 * reference date 2026-11-15). Tests can reassign customers (`$customers`) or take the system down (`$down`); both are
 * static so they survive kernel reboots between requests and are reset by the test cases.
 */
final class StubBusinessApi implements BusinessApi
{
    /** @var array<string, list<Customer>> representative => customers, overrides the fixtures */
    public static array $customers = [];
    public static bool $down = false;
    /** Runs once inside the next salesLines() call: lets a test interleave a second job with a running one. */
    public static ?\Closure $duringSalesLines = null;

    private readonly RestBusinessApi $fixtures;

    public function __construct()
    {
        $this->fixtures = new RestBusinessApi(new FixtureBusinessHttp()->client(), 'http://business.test', 'test-token');
    }

    public static function reset(): void
    {
        self::$customers = [];
        self::$down = false;
        self::$duringSalesLines = null;
    }

    public function salesReps(): array
    {
        return ['PH-01', 'PH-02', 'PH-03', 'PH-04', 'PH-05'];
    }

    public function customersOf(string $salesRep): array
    {
        $this->up();

        return self::$customers[$salesRep] ?? ('PH-01' === $salesRep ? $this->fixtures->customersOf($salesRep) : []);
    }

    public function salesLines(string $salesRep, DateRange $period, array $customerCodes = [], ?string $manufacturer = null, ?string $category = null): array
    {
        $this->up();
        $during = self::$duringSalesLines;
        self::$duringSalesLines = null;
        if (null !== $during) {
            $during();
        }

        return 'PH-01' === $salesRep ? $this->fixtures->salesLines($salesRep, $period, $customerCodes, $manufacturer, $category) : [];
    }

    public function manufacturers(): array
    {
        $this->up();

        return $this->fixtures->manufacturers();
    }

    public function parts(string $manufacturerCode): array
    {
        $this->up();

        return $this->fixtures->parts($manufacturerCode);
    }

    public function discountPolicies(): array
    {
        $this->up();

        return $this->fixtures->discountPolicies();
    }

    public function dataVersion(): string
    {
        $this->up();

        return $this->fixtures->dataVersion();
    }

    private function up(): void
    {
        if (self::$down) {
            throw new DataSourceException('Business system down (test)');
        }
    }
}
