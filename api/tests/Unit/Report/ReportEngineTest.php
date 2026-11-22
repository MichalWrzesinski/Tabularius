<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Module\Report\Business\OutOfScopeException;
use App\Module\Report\Business\RestBusinessApi;
use App\Module\Report\Csv\CsvWriter;
use App\Module\Report\Execution\DiscountCalculator;
use App\Module\Report\Execution\InvalidParametersException;
use App\Module\Report\Execution\ReportEngine;
use App\Module\Report\Execution\ReportResult;
use App\Module\Report\Execution\Scope;
use App\Module\Report\Period\DateRange;
use App\Module\Report\Period\PeriodException;
use App\Module\Report\Period\PeriodResolver;
use App\Module\Report\Profile\IntegrationProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * AC-1: reports for known queries equal results written here by hand (business/README.md known answers),
 * through the real adapter against captured business API responses for reference date 2026-11-15.
 */
final class ReportEngineTest extends TestCase
{
    private FixtureBusinessHttp $http;
    private ReportEngine $engine;

    protected function setUp(): void
    {
        $this->http = new FixtureBusinessHttp();
        $this->engine = new ReportEngine(
            IntegrationProfile::fromFile(\dirname(__DIR__, 3).'/config/integration/demo-autoparts.v1.yaml'),
            new RestBusinessApi($this->http->client(), 'http://business.test', 'test-token'),
            new PeriodResolver(),
            new DiscountCalculator(),
            new MockClock('2026-11-15 10:42:00', 'Europe/Warsaw'),
        );
    }

    public function testOctoberSalesReportForAllAssignedCustomers(): void
    {
        $report = $this->report('sales_report', ['period' => ['type' => 'previous_month']]);

        self::assertSame('sprzedaz_2026-10-01_2026-10-31.csv', $report->fileName);
        self::assertSame('01–31.10.2026', $report->periodLabel);
        self::assertSame('Przypisani klienci (7)', $report->scopeLabel);
        self::assertFalse($report->empty);
        self::assertSame(
            "\u{FEFF}typ_wiersza;data;dokument;rodzaj;klient_kod;klient;nr_czesci;producent;kategoria;ilosc;netto_pln\r\n".
            "meta;Operacja;Raport sprzedaży;;;;;;;;\r\n".
            "meta;Zakres;Przypisani klienci (7);;;;;;;;\r\n".
            "meta;Okres;2026-10-01 – 2026-10-31 (Europe/Warsaw);;;;;;;;\r\n".
            "meta;Waluta;PLN, netto po korektach, bez dokumentów anulowanych;;;;;;;;\r\n".
            "meta;Wersje;profil demo-autoparts v1 · dane 2026.11-1;;;;;;;;\r\n".
            "meta;Wygenerowano;2026-11-15 10:42;;;;;;;;\r\n".
            "pozycja;2026-10-02;FV/2026/10/0001;Faktura;K-001;Alfa Motors Sp. z o.o.;BO-01074;Bosch;Oświetlenie;4;519,60\r\n".
            "pozycja;2026-10-02;FV/2026/10/0001;Faktura;K-001;Alfa Motors Sp. z o.o.;MA-01111;Mann-Filter;Wycieraczki;10;234,50\r\n".
            "pozycja;2026-10-03;FV/2026/10/0005;Faktura;K-004;Moto-Lux Gdańsk;VA-01222;Valeo;Zapłon;50;30000,00\r\n".
            "pozycja;2026-10-07;FV/2026/10/0004;Faktura;K-003;'+48 Auto Części;BO-01111;Bosch;Zawieszenie;8;319,20\r\n".
            "pozycja;2026-10-14;FV/2026/10/0002;Faktura;K-002;Alfa-Tech Hurtownia;TR-01148;TRW;Łożyska;6;894,00\r\n".
            "pozycja;2026-10-21;FV/2026/10/0007;Faktura;K-005;Serwis Bielany;SK-01296;SKF;Wycieraczki;1;1289,00\r\n".
            "pozycja;2026-10-23;KOR/2026/10/0001;Korekta;K-002;Alfa-Tech Hurtownia;TR-01148;TRW;Łożyska;-1;-149,00\r\n".
            "pozycja;2026-10-28;FV/2026/10/0006;Faktura;K-004;Moto-Lux Gdańsk;VA-01259;Valeo;Oświetlenie;20;20000,10\r\n".
            // Per-customer totals (independently: 754.10, 745.00 = 894 − 149, 319.20, 50 000.10, 1 289.00), zero rows keep the scope visible.
            "suma_klienta;;;;K-003;'+48 Auto Części;;;;;319,20\r\n".
            "suma_klienta;;;;K-001;Alfa Motors Sp. z o.o.;;;;;754,10\r\n".
            "suma_klienta;;;;K-002;Alfa-Tech Hurtownia;;;;;745,00\r\n".
            "suma_klienta;;;;K-007;Auto-Kombi Radom;;;;;0,00\r\n".
            "suma_klienta;;;;K-004;Moto-Lux Gdańsk;;;;;50000,10\r\n".
            "suma_klienta;;;;K-005;Serwis Bielany;;;;;1289,00\r\n".
            "suma_klienta;;;;K-006;Warsztat Pod Lipami;;;;;0,00\r\n".
            "suma_calkowita;;;;;;;;;;53107,40\r\n",
            (new CsvWriter())->write($report),
        );
    }

    public function testEmptyPeriodIsAValidResultWithZeroTotals(): void
    {
        $report = $this->report('sales_report', ['period' => ['type' => 'month', 'month' => 10], 'customers' => ['K-006']]);

        self::assertTrue($report->empty);
        self::assertSame('Warsztat Pod Lipami', $report->scopeLabel);
        $csv = (new CsvWriter())->write($report);
        self::assertStringContainsString("suma_klienta;;;;K-006;Warsztat Pod Lipami;;;;;0,00\r\n", $csv);
        self::assertStringEndsWith("suma_calkowita;;;;;;;;;;0,00\r\n", $csv);
    }

    public function testDiscountSimulationAtTheFivePercentBoundary(): void
    {
        $report = $this->report('customer_discount_simulation', ['customer' => 'K-004', 'period' => ['type' => 'previous_month']]);

        self::assertSame('symulacja_rabatu_k-004_2026-10-01_2026-10-31.csv', $report->fileName);
        self::assertSame(
            "\u{FEFF}typ_wiersza;pozycja;wartosc\r\n".
            "meta;UWAGA;SYMULACJA — oferta niezatwierdzona, nie zmienia cen ani umów\r\n".
            "meta;Operacja;Aktywność klienta i symulacja rabatu\r\n".
            "meta;Zakres;Moto-Lux Gdańsk (K-004)\r\n".
            "meta;Okres;2026-10-01 – 2026-10-31 (Europe/Warsaw)\r\n".
            "meta;Waluta;PLN, netto po korektach, bez dokumentów anulowanych\r\n".
            "meta;Wersje;profil demo-autoparts v1 · dane 2026.11-1\r\n".
            "meta;Wygenerowano;2026-11-15 10:42\r\n".
            "meta;Polityka;DEMO-RABAT 2026.1 (fikcyjna)\r\n".
            "pozycja;Liczba dokumentów;2\r\n".
            "pozycja;Liczba pozycji;2\r\n".
            "pozycja;Sprzedaż netto (faktury);50000,10\r\n".
            "pozycja;Korekty netto;0,00\r\n".
            "pozycja;Ostatnia sprzedaż w okresie;2026-10-28\r\n".
            "pozycja;Podstawa rabatu;50000,10\r\n".
            "pozycja;Próg;od 50000,00\r\n".
            "pozycja;Stawka %;5\r\n".
            "suma;Symulowany rabat;2500,01\r\n".          // 2 500.005 rounded half up
            "suma;Netto po symulowanym rabacie;47500,09\r\n",
            (new CsvWriter())->write($report),
        );
    }

    public function testDiscountBelowTheFirstTier(): void
    {
        $csv = (new CsvWriter())->write($this->report('customer_discount_simulation', ['customer' => 'K-004', 'period' => ['type' => 'month', 'month' => 9]]));

        self::assertStringContainsString("pozycja;Podstawa rabatu;9999,99\r\n", $csv);
        self::assertStringContainsString("pozycja;Stawka %;0\r\n", $csv);
        self::assertStringContainsString("suma;Symulowany rabat;0,00\r\n", $csv);
    }

    public function testInactiveCustomerSimulationIsZeroWithAReason(): void
    {
        $csv = (new CsvWriter())->write($this->report('customer_discount_simulation', ['customer' => 'K-007', 'period' => ['type' => 'explicit', 'start' => '2026-05-01', 'end' => '2026-05-31']]));

        self::assertStringContainsString("pozycja;Podstawa rabatu;1200,00\r\n", $csv);
        self::assertStringContainsString("pozycja;Uwagi;klient nieaktywny\r\n", $csv);
        self::assertStringContainsString("suma;Symulowany rabat;0,00\r\n", $csv);
    }

    public function testCatalogContainsActivePartsWithPermittedFieldsOnly(): void
    {
        $report = $this->report('parts_catalog', ['manufacturer' => 'BOSCH']);
        $csv = (new CsvWriter())->write($report);

        self::assertSame('katalog_bosch_2026-11-15.csv', $report->fileName);
        self::assertSame('Bosch', $report->scopeLabel);
        self::assertSame(36, substr_count($csv, "\r\npozycja;"));
        self::assertStringStartsWith("\u{FEFF}typ_wiersza;producent;nr_czesci;nazwa;kategoria;jm;cena_katalogowa_netto_pln\r\n", $csv);
        self::assertStringContainsString(";'-20% Wkład filtra powietrza (wyprzedaż);", $csv);
        self::assertStringContainsString(";'@Serwis zestaw klocków hamulcowych;", $csv);
        self::assertStringNotContainsStringIgnoringCase('purchase', $csv);
    }

    public function testCustomerOutsideTheScopeIsRefusedBeforeReadingSales(): void
    {
        try {
            $this->report('sales_report', ['period' => ['type' => 'previous_month'], 'customers' => ['K-001', 'K-008']]);
            self::fail('Expected a scope refusal');
        } catch (OutOfScopeException) {
            self::assertSame([], array_filter($this->http->requests, static fn (string $u): bool => str_contains($u, '/v1/sales-lines')));
        }
    }

    public function testDiscountForACustomerOutsideTheScopeIsRefused(): void
    {
        $this->expectException(OutOfScopeException::class);
        $this->report('customer_discount_simulation', ['customer' => 'K-008', 'period' => ['type' => 'previous_month']]);
    }

    public function testPeriodIncludingTodayNeedsCorrection(): void
    {
        try {
            $this->report('sales_report', ['period' => ['type' => 'explicit', 'start' => '2026-11-01', 'end' => '2026-11-15']]);
            self::fail('Expected a correction');
        } catch (PeriodException $e) {
            self::assertSame('period_includes_today', $e->reason);
            self::assertSame('01–14.11.2026', $e->suggestion?->label());
            self::assertSame([], $this->http->requests, 'no data read for an invalid period');
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'extra field (e.g. recipient)' => ['sales_report', ['period' => ['type' => 'previous_month'], 'recipient' => 'x@evil.test'], 'unexpected_parameter'];
        yield 'sql in parameters' => ['sales_report', ['period' => ['type' => 'previous_month'], 'sql' => 'DROP TABLE'], 'unexpected_parameter'];
        yield 'missing period' => ['sales_report', ['customers' => 'all_assigned'], 'missing_period'];
        yield 'customers not a list' => ['sales_report', ['period' => ['type' => 'previous_month'], 'customers' => 'K-001'], 'invalid_customers'];
        yield 'customer code shape' => ['sales_report', ['period' => ['type' => 'previous_month'], 'customers' => ["K-001' OR 1=1"]], 'invalid_customers'];
        yield 'empty customer list' => ['sales_report', ['period' => ['type' => 'previous_month'], 'customers' => []], 'invalid_customers'];
        yield 'unknown category' => ['sales_report', ['period' => ['type' => 'previous_month'], 'category' => 'Paliwo'], 'unknown_category'];
        yield 'unknown manufacturer' => ['parts_catalog', ['manufacturer' => 'NOPE'], 'unknown_manufacturer'];
        yield 'missing manufacturer' => ['parts_catalog', [], 'missing_manufacturer'];
        yield 'manufacturer shape' => ['parts_catalog', ['manufacturer' => 'bosch; DROP'], 'invalid_manufacturer'];
        yield 'missing customer' => ['customer_discount_simulation', ['period' => ['type' => 'previous_month']], 'missing_customer'];
        yield 'unknown policy version' => ['customer_discount_simulation', ['customer' => 'K-004', 'period' => ['type' => 'previous_month'], 'policy' => '1999.1'], 'unknown_policy'];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('invalidParameters')]
    public function testRejectsInvalidParameters(string $operation, array $parameters, string $reason): void
    {
        try {
            $this->report($operation, $parameters);
            self::fail('Expected '.$reason);
        } catch (InvalidParametersException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testOperationOutsideTheCatalogIsRejectedBeforeAnyDataAccess(): void
    {
        try {
            $this->report('payroll_report', []);
            self::fail('Expected a refusal');
        } catch (\OutOfBoundsException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testManufacturerAndCategoryFiltersAreShownInMetadata(): void
    {
        $csv = (new CsvWriter())->write($this->report('sales_report', ['period' => ['type' => 'month', 'month' => 10], 'customers' => ['K-006'], 'category' => 'Hamulce']));

        self::assertStringContainsString('meta;Kategoria;Hamulce;', $csv);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function report(string $operation, array $parameters): ReportResult
    {
        return $this->engine->run($operation, $parameters, new Scope('PH-01'), DateRange::date('2026-11-15'));
    }
}
