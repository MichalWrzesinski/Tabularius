<?php

declare(strict_types=1);

namespace App\Module\Report\Csv;

use App\Module\Report\Execution\NumberCell;
use App\Module\Report\Execution\ReportResult;

/**
 * CSV for Excel (pl-PL) and LibreOffice Calc (RPT-OUT-2): UTF-8 with BOM, `;`, CRLF, RFC 4180 quoting.
 * Text cells starting with a formula character (incl. full-width variants) get a leading apostrophe (RPT-OUT-4);
 * numeric cells are produced by code and left as numbers.
 */
final class CsvWriter
{
    private const string FORMULA_START = '/^[=+\-@\t\r\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u';

    public function write(ReportResult $report): string
    {
        $lines = [$this->line($report->header)];
        foreach ($report->rows as $row) {
            $lines[] = $this->line(array_map(
                static fn (string|NumberCell $cell): string => $cell instanceof NumberCell ? $cell->text : self::guard($cell),
                $row->cells,
            ));
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    public static function guard(string $text): string
    {
        return 1 === preg_match(self::FORMULA_START, $text) ? "'".$text : $text;
    }

    /**
     * @param list<string> $cells
     */
    private function line(array $cells): string
    {
        return implode(';', array_map(
            static fn (string $c): string => 1 === preg_match('/[";\r\n]/', $c) ? '"'.str_replace('"', '""', $c).'"' : $c,
            $cells,
        ));
    }
}
