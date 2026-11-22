<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

/**
 * A generated report before serialization. `periodLabel` and `scopeLabel` are shown in the UI and notifications;
 * `customers` drives the access re-check on download.
 */
final readonly class ReportResult
{
    /**
     * @param list<string> $header
     * @param list<Row>    $rows
     * @param list<string> $customers codes of every customer whose data the file can contain (download re-check, ACC-FILE-1)
     */
    public function __construct(
        public string $operation,
        public string $fileName,
        public array $header,
        public array $rows,
        public string $periodLabel,
        public string $scopeLabel,
        public bool $empty,
        public array $customers,
    ) {
    }
}
