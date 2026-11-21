<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * The API runtime role business_reader must not change the schema or data, and sees nothing that is not granted
 * explicitly (ADR 0002). Runs against business_test.
 */
final class DatabaseRolesTest extends TestCase
{
    private Connection $owner;
    private Connection $reader;

    protected function setUp(): void
    {
        $this->owner = self::connect('BUSINESS_OWNER_TEST_URL');
        $this->reader = self::connect('BUSINESS_READER_TEST_URL');
        $this->owner->executeStatement('DROP TABLE IF EXISTS role_probe');
        $this->owner->executeStatement('CREATE TABLE role_probe (id int PRIMARY KEY)');
        $this->owner->executeStatement('INSERT INTO role_probe VALUES (1)');
    }

    protected function tearDown(): void
    {
        $this->owner->executeStatement('DROP TABLE IF EXISTS role_probe');
        $this->owner->close();
        $this->reader->close();
    }

    public function testReaderCannotCreateTables(): void
    {
        $this->expectException(DriverException::class);
        $this->reader->executeStatement('CREATE TABLE reader_probe (id int)');
    }

    public function testReaderCannotWriteOrReadUngrantedTables(): void
    {
        foreach (['INSERT INTO role_probe VALUES (2)', 'DELETE FROM role_probe', 'SELECT * FROM role_probe', 'DROP TABLE role_probe'] as $sql) {
            try {
                $this->reader->executeQuery($sql);
                self::fail('business_reader was allowed to run: '.$sql);
            } catch (DriverException $e) {
                self::assertSame('42501', $e->getSQLState(), $sql); // insufficient_privilege
            }
        }
    }

    private static function connect(string $env): Connection
    {
        $url = $_SERVER[$env] ?? $_ENV[$env] ?? getenv($env);
        self::assertIsString($url, $env.' must be set for this test');

        return DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
    }
}
