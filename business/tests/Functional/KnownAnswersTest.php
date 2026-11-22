<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Db\Value;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Hand-placed data of PH-01 for reference date 2026-11-15 (P = October, P2 = September, C = November), read
 * through v_sales_line as business_reader. Expected values are written here independently of the seeder.
 */
final class KnownAnswersTest extends KernelTestCase
{
    private Connection $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $reader = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->reader = $reader;
    }

    public function testOctoberTotalsPerCustomer(): void
    {
        self::assertSame([
            '+48 Auto Części' => '319.20',
            'Alfa Motors Sp. z o.o.' => '754.10',
            'Alfa-Tech Hurtownia' => '745.00',     // 894.00 − 149.00 adjustment issued in October
            'Moto-Lux Gdańsk' => '50000.10',       // discount boundary case
            'Serwis Bielany' => '1289.00',         // canceled 500.00 excluded
        ], $this->totals('2026-10-01', '2026-10-31'));
    }

    public function testSeptemberUsesTheSaleDateNotTheIssueDate(): void
    {
        self::assertSame([
            'Alfa-Tech Hurtownia' => '410.00',     // sale date 30 Sep, issued 1 Oct
            'Moto-Lux Gdańsk' => '9999.99',
        ], $this->totals('2026-09-01', '2026-09-30'));
    }

    public function testCurrentMonthCountsAdjustmentsOnTheirIssueDateAndExcludesFuture(): void
    {
        // 1–14 November: yesterday's 100.00 only; today's invoice, today's −50.00 adjustment and future invoices are out.
        self::assertSame(['Alfa Motors Sp. z o.o.' => '100.00'], $this->totals('2026-11-01', '2026-11-14'));
        self::assertSame([
            'Alfa Motors Sp. z o.o.' => '177.00',
            'Alfa-Tech Hurtownia' => '-50.00',
        ], $this->totals('2026-11-01', '2026-11-15'));
    }

    public function testCustomerWithoutSalesAndInactiveCustomer(): void
    {
        self::assertSame(0, Value::int($this->reader->fetchOne("SELECT count(*) FROM v_sales_line WHERE customer_code = 'K-006'")));
        self::assertFalse((bool) $this->reader->fetchOne("SELECT active FROM v_customer WHERE code = 'K-007'"));
        self::assertSame('1200.00', $this->reader->fetchOne("SELECT sum(net_value) FROM v_sales_line WHERE customer_code = 'K-007'"));
    }

    public function testSimilarNamesBelongToDifferentRepresentatives(): void
    {
        $rows = $this->reader->fetchAllKeyValue("SELECT name, sales_rep_code FROM v_customer WHERE name ILIKE 'alfa%' ORDER BY name");
        self::assertSame(['Alfa Motors Sp. z o.o.' => 'PH-01', 'Alfa-Tech Hurtownia' => 'PH-01', 'Alfatrans Logistyka' => 'PH-02'], $rows);
    }

    /**
     * @return array<string, string>
     */
    private function totals(string $from, string $to): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->reader->fetchAllKeyValue(
            "SELECT customer_name, sum(net_value)::text FROM v_sales_line WHERE sales_rep_code = 'PH-01' AND inclusion_date BETWEEN ? AND ? GROUP BY 1 ORDER BY 1",
            [$from, $to],
        );

        return $rows;
    }
}
