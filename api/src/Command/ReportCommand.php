<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Report\Csv\CsvWriter;
use App\Module\Report\Execution\ReportEngine;
use App\Module\Report\Execution\Scope;
use App\Module\Report\Period\DateRange;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator/developer tool: runs one catalog operation for a representative and prints or saves the CSV.
 * Example: bin/console app:report sales_report '{"period":{"type":"previous_month"}}' --rep=PH-01 --reference-date=2026-11-15.
 */
#[AsCommand(name: 'app:report', description: 'Run a catalog report (synthetic data) and output the CSV')]
final class ReportCommand
{
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly CsvWriter $csv,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Operation id from the integration profile')]
        string $operation,
        #[Argument(description: 'Parameters as JSON')]
        string $parameters,
        #[Option(description: 'Representative code (trusted scope)')]
        string $rep = 'PH-01',
        #[Option(description: 'Reference date YYYY-MM-DD (default: today, Europe/Warsaw)')]
        ?string $referenceDate = null,
        #[Option(description: 'Write the CSV to this file instead of stdout')]
        ?string $output = null,
    ): int {
        $params = json_decode($parameters, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($params)) {
            $io->error('Parameters must be a JSON object.');

            return Command::INVALID;
        }
        $reference = null === $referenceDate ? new \DateTimeImmutable('today', DateRange::zone()) : DateRange::date($referenceDate);
        $report = $this->engine->run($operation, $params, new Scope($rep), $reference);
        $content = $this->csv->write($report);
        if (null === $output) {
            $io->write($content);
        } else {
            file_put_contents($output, $content);
            $io->success(\sprintf('%s · %s · %s → %s', $report->fileName, $report->periodLabel, $report->scopeLabel, $output));
        }

        return Command::SUCCESS;
    }
}
