<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Module\Report\Csv\CsvWriter;
use App\Module\Report\Execution\NumberCell;
use App\Module\Report\Execution\ReportResult;
use App\Module\Report\Execution\Row;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=HYPERLINK("http://x")', "'=HYPERLINK(\"http://x\")"];
        yield 'plus' => ['+48 Auto Części', "'+48 Auto Części"];
        yield 'minus' => ['-20% Wkład', "'-20% Wkład"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\tx", "'\tx"];
        yield 'carriage return' => ["\rx", "'\rx"];
        yield 'full-width equals' => ['＝1+1', "'＝1+1"];
        yield 'full-width plus' => ['＋1', "'＋1"];
        yield 'plain text' => ['Bosch', 'Bosch'];
        yield 'formula char later' => ['A=B', 'A=B'];
    }

    #[DataProvider('formulas')]
    public function testGuardsFormulaStarts(string $text, string $expected): void
    {
        self::assertSame($expected, CsvWriter::guard($text));
    }

    public function testWritesBomSemicolonsCrlfQuotingAndKeepsNumbersAsNumbers(): void
    {
        $report = new ReportResult('x', 'x.csv', ['a', 'b', 'c'], [
            Row::meta('Zakres', 'Klient; "Alfa"', 3),
            new Row('pozycja', ['pozycja', '-x', NumberCell::money(BigDecimal::of('-149.00'))]),
            new Row('pozycja', ['pozycja', "dwie\nlinie", NumberCell::quantity(BigDecimal::of('4.000'))]),
        ], '', '', false);

        self::assertSame(
            "\u{FEFF}a;b;c\r\nmeta;Zakres;\"Klient; \"\"Alfa\"\"\"\r\npozycja;'-x;-149,00\r\npozycja;\"dwie\nlinie\";4\r\n",
            (new CsvWriter())->write($report),
        );
    }

    public function testNumberCells(): void
    {
        self::assertSame('1289,00', NumberCell::money(BigDecimal::of('1289'))->text);
        self::assertSame('0,5', NumberCell::quantity(BigDecimal::of('0.500'))->text);
        self::assertSame('5', NumberCell::quantity(BigDecimal::of('0.05')->multipliedBy(100))->text);
        self::assertSame('14', NumberCell::integer(14)->text);
    }
}
