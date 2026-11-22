<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Command\ReportCommand;
use App\Module\Report\Business\RestBusinessApi;
use App\Module\Report\Csv\CsvWriter;
use App\Module\Report\Execution\DiscountCalculator;
use App\Module\Report\Execution\ReportEngine;
use App\Module\Report\Period\PeriodResolver;
use App\Module\Report\Profile\IntegrationProfile;
use App\Tests\Double\FixtureBusinessHttp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $engine = new ReportEngine(
            IntegrationProfile::fromFile(\dirname(__DIR__, 3).'/config/integration/demo-autoparts.v1.yaml'),
            new RestBusinessApi((new FixtureBusinessHttp())->client(), 'http://business.test', 'test-token'),
            new PeriodResolver(),
            new DiscountCalculator(),
            new MockClock('2026-11-15 10:42:00', 'Europe/Warsaw'),
        );
        $application = new Application();
        $application->addCommand(new ReportCommand($engine, new CsvWriter()));
        $this->tester = new CommandTester($application->find('app:report'));
    }

    public function testPrintsTheCsv(): void
    {
        $status = $this->tester->execute(['operation' => 'sales_report', 'parameters' => '{"period":{"type":"previous_month"}}', '--reference-date' => '2026-11-15']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('suma_calkowita;;;;;;;;;;53107,40', $this->tester->getDisplay());
    }

    public function testWritesToAFile(): void
    {
        $file = sys_get_temp_dir().'/tabularius-report-test.csv';
        $status = $this->tester->execute(['operation' => 'parts_catalog', 'parameters' => '{"manufacturer":"BOSCH"}', '--output' => $file]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringStartsWith("\u{FEFF}typ_wiersza;producent", (string) file_get_contents($file));
        unlink($file);
    }

    public function testRejectsNonObjectParameters(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['operation' => 'parts_catalog', 'parameters' => '"BOSCH"']));
    }
}
