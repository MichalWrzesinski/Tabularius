<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\SeedCommand;
use App\Db\Value;
use App\Seed\DemoDataSeeder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reproducibility (same reference date → identical data) and date refresh. Leaves the test database seeded
 * for 2026-11-15 again, as the other tests expect.
 */
final class SeedTest extends KernelTestCase
{
    private const string REFERENCE = '2026-11-15';
    /** Pinned content checksum of seed 2026.11-1 for 2026-11-15; change it only together with DATA_VERSION. */
    private const string CHECKSUM = '7a8a22f6af15c43125d5eadd19af04b8';

    private Connection $owner;
    private Connection $reader;
    private DemoDataSeeder $seeder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $owner = $container->get('doctrine.dbal.owner_connection');
        $reader = $container->get('doctrine.dbal.default_connection');
        $seeder = $container->get(DemoDataSeeder::class);
        [$this->owner, $this->reader, $this->seeder] = [$owner, $reader, $seeder];
    }

    protected function tearDown(): void
    {
        $this->seeder->write($this->owner, new \DateTimeImmutable(self::REFERENCE));
        parent::tearDown();
    }

    public function testSameReferenceDateGivesIdenticalData(): void
    {
        $this->seeder->write($this->owner, new \DateTimeImmutable(self::REFERENCE));
        $first = DemoDataSeeder::checksum($this->owner);
        $this->seeder->write($this->owner, new \DateTimeImmutable(self::REFERENCE));

        self::assertSame($first, DemoDataSeeder::checksum($this->owner));
        self::assertSame(self::CHECKSUM, $first, 'Seed content changed: bump DemoDataSeeder::DATA_VERSION and the pinned checksum together.');
    }

    public function testVolumeMatchesThePlan(): void
    {
        self::assertSame(6, Value::int($this->owner->fetchOne('SELECT count(*) FROM manufacturer')));
        self::assertSame(1, Value::int($this->owner->fetchOne('SELECT count(*) FROM manufacturer WHERE NOT active')));
        self::assertSame(200, Value::int($this->owner->fetchOne('SELECT count(*) FROM part')));
        self::assertSame(30, Value::int($this->owner->fetchOne('SELECT count(*) FROM customer')));
        self::assertSame(5, Value::int($this->owner->fetchOne('SELECT count(*) FROM sales_rep')));
        self::assertSame(30, Value::int($this->owner->fetchOne('SELECT count(*) FROM customer_assignment')), 'every customer has exactly one representative');
        self::assertGreaterThan(0, Value::int($this->owner->fetchOne("SELECT count(*) FROM sales_document WHERE status = 'canceled'")));
        self::assertGreaterThan(0, Value::int($this->owner->fetchOne("SELECT count(*) FROM sales_document WHERE kind = 'adjustment'")));
        self::assertGreaterThan(0, Value::int($this->owner->fetchOne("SELECT count(*) FROM sales_document WHERE issue_date > DATE '2026-11-15'")), 'future documents exist');
    }

    public function testRefreshMovesTheHistoryToTheNewReferenceDate(): void
    {
        $this->seeder->write($this->owner, new \DateTimeImmutable('2027-02-10'));

        // P is now January 2027; the known answers move with it.
        self::assertSame('50000.10', $this->total('K-004', '2027-01-01', '2027-01-31'));
        self::assertSame('9999.99', $this->total('K-004', '2026-12-01', '2026-12-31'));
        self::assertSame('2027-02-10', $this->reader->fetchOne('SELECT reference_date FROM v_seed_meta'));
    }

    public function testFirstDayOfMonthKeepsPreviousMonthTotalsExact(): void
    {
        $this->seeder->write($this->owner, new \DateTimeImmutable('2026-12-01'));

        self::assertSame('754.10', $this->total('K-001', '2026-11-01', '2026-11-30'));
    }

    public function testCommandSeedsAndValidatesTheDate(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('app:seed'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--reference-date' => self::REFERENCE]));
        self::assertStringContainsString('reference date 2026-11-15', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame(Command::INVALID, $tester->execute(['--reference-date' => '2026-02-30']));
        self::assertSame(Command::SUCCESS, $tester->execute([]), 'defaults to today');
    }

    public function testCommandRefusesWhereSeedingIsNotAllowed(): void
    {
        $command = new SeedCommand($this->owner, $this->seeder, false);
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('app:seed'));

        self::assertSame(Command::FAILURE, $tester->execute(['--reference-date' => self::REFERENCE]));
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    private function total(string $customer, string $from, string $to): string
    {
        return Value::string($this->reader->fetchOne(
            'SELECT sum(net_value) FROM v_sales_line WHERE customer_code = ? AND inclusion_date BETWEEN ? AND ?',
            [$customer, $from, $to],
        ));
    }
}
