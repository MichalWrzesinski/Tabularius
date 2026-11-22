<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

/**
 * A generated report before serialization. `periodLabel` and `scopeLabel` are shown in the UI and notifications.
 */
final readonly class ReportResult
{
    /**
     * @param list<string> $header
     * @param list<Row>    $rows
     */
    public function __construct(
        public string $operation,
        public string $fileName,
        public array $header,
        public array $rows,
        public string $periodLabel,
        public string $scopeLabel,
        public bool $empty,
    ) {
    }
}
