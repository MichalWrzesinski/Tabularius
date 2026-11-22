<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Db\Value;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * business_reader (API runtime) must not change schema or data and reads only the granted v_* views (ADR 0002).
 */
final class DatabaseRolesTest extends KernelTestCase
{
    private Connection $reader;

    protected function setUp(): void
    {
        self::bootKernel();
        $reader = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->reader = $reader;
    }

    public function testReaderIsTheBusinessReaderRole(): void
    {
        self::assertSame('business_reader', $this->reader->fetchOne('SELECT current_user'));
    }

    public function testReaderCannotCreateTables(): void
    {
        $this->expectException(DriverException::class);
        $this->reader->executeStatement('CREATE TABLE reader_probe (id int)');
    }

    public function testReaderCannotTouchTables(): void
    {
        foreach ([
            'SELECT * FROM part',
            'SELECT purchase_price_net FROM part',
            'INSERT INTO sales_rep VALUES (99, \'PH-99\', \'x\')',
            'DELETE FROM sales_line',
            'UPDATE customer SET active = false',
            'TRUNCATE seed_meta',
            'DROP VIEW v_sales_line',
        ] as $sql) {
            try {
                $this->reader->executeQuery($sql);
                self::fail('business_reader was allowed to run: '.$sql);
            } catch (DriverException $e) {
                self::assertSame('42501', $e->getSQLState(), $sql); // insufficient_privilege
            }
        }
    }

    public function testReaderCanReadGrantedViews(): void
    {
        foreach (['v_sales_line', 'v_customer', 'v_sales_rep', 'v_manufacturer', 'v_part_catalog', 'v_discount_policy', 'v_seed_meta'] as $view) {
            self::assertGreaterThan(0, Value::int($this->reader->fetchOne("SELECT count(*) FROM $view")), $view);
        }
    }

    public function testCatalogViewHasNoPurchaseDataColumns(): void
    {
        $columns = $this->reader->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'v_part_catalog'");
        self::assertNotContains('purchase_price_net', $columns);
        self::assertNotContains('supplier_id', $columns);
    }
}
