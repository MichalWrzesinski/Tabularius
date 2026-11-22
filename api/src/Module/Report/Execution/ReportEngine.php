<?php

declare(strict_types=1);

namespace App\Module\Report\Execution;

use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Business\OutOfScopeException;
use App\Module\Report\Period\DateRange;
use App\Module\Report\Period\PeriodExpression;
use App\Module\Report\Period\PeriodResolver;
use App\Module\Report\Profile\IntegrationProfile;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;

/**
 * Deterministic execution of the closed operation catalog (RPT-SALES, RPT-CAT, RPT-DISC). Parameters are parsed
 * strictly per operation (extra fields rejected); scope comes from trusted context; money is decimal.
 */
final class ReportEngine
{
    private const string CUSTOMER = '/^K-\d{3}$/';
    private const string MANUFACTURER = '/^[A-Z0-9-]{2,20}$/';
    private const string SIMULATION_NOTICE = 'SYMULACJA — oferta niezatwierdzona, nie zmienia cen ani umów';

    public function __construct(
        private readonly IntegrationProfile $profile,
        private readonly BusinessApi $business,
        private readonly PeriodResolver $periods,
        private readonly DiscountCalculator $discounts,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @throws InvalidParametersException                          invalid or extra parameters (a question or correction is needed)
     * @throws \App\Module\Report\Period\PeriodException
     * @throws OutOfScopeException                                 customer outside the owner's scope
     * @throws \App\Module\Report\Business\DataSourceException
     * @throws \App\Module\Report\Business\ReportTooLargeException
     */
    public function run(string $operation, array $parameters, Scope $scope, \DateTimeImmutable $reference): ReportResult
    {
        $this->profile->operation($operation); // outside the catalog → exception before any data access

        return match ($operation) {
            'sales_report' => $this->salesReport($parameters, $scope, $reference),
            'parts_catalog' => $this->partsCatalog($parameters),
            'customer_discount_simulation' => $this->discountSimulation($parameters, $scope, $reference),
            default => throw new \OutOfBoundsException('Operation without an executor: '.$operation),
        };
    }

    /**
     * Validates a plan without reading report data: the same parsing, period resolution, catalog and scope checks as
     * run(), so a plan accepted here fails at execution only if the data or the scope changed in between.
     *
     * @param array<array-key, mixed> $parameters
     *
     * @throws InvalidParametersException
     * @throws \App\Module\Report\Period\PeriodException
     * @throws OutOfScopeException
     * @throws \App\Module\Report\Business\DataSourceException
     */
    public function check(string $operation, array $parameters, Scope $scope, \DateTimeImmutable $reference): void
    {
        $this->profile->operation($operation);
        match ($operation) {
            'sales_report' => $this->salesParams($parameters, $scope, $reference),
            'parts_catalog' => $this->catalogParams($parameters),
            'customer_discount_simulation' => $this->discountParams($parameters, $scope, $reference),
            default => throw new \OutOfBoundsException('Operation without an executor: '.$operation),
        };
    }

    /**
     * @param array<array-key, mixed> $p
     *
     * @return array{period: DateRange, codes: list<string>, manufacturer: ?string, category: ?string, inScope: list<\App\Module\Report\Business\Customer>}
     */
    private function salesParams(array $p, Scope $scope, \DateTimeImmutable $reference): array
    {
        self::allowOnly($p, ['period', 'customers', 'manufacturer', 'category']);
        $period = $this->periods->resolve(PeriodExpression::fromArray(self::map($p, 'period')), $reference);
        $requested = $p['customers'] ?? 'all_assigned';
        $codes = 'all_assigned' === $requested ? [] : self::codes($requested);
        $manufacturer = self::optional($p, 'manufacturer', self::MANUFACTURER);
        $category = $p['category'] ?? null;
        if (null !== $category && (!\is_string($category) || !\in_array($category, $this->profile->categories, true))) {
            throw new InvalidParametersException('unknown_category');
        }
        if (null !== $manufacturer) {
            $this->assertManufacturer($manufacturer);
        }

        $assigned = $this->business->customersOf($scope->salesRep);
        $assignedCodes = array_map(static fn ($c): string => $c->code, $assigned);
        if ([] !== array_diff($codes, $assignedCodes)) {
            throw new OutOfScopeException('Requested customer outside the owner\'s scope');
        }
        $inScope = [] === $codes ? $assigned : array_values(array_filter($assigned, static fn ($c): bool => \in_array($c->code, $codes, true)));

        return ['period' => $period, 'codes' => $codes, 'manufacturer' => $manufacturer, 'category' => \is_string($category) ? $category : null, 'inScope' => $inScope];
    }

    /**
     * @param array<array-key, mixed> $p
     */
    private function salesReport(array $p, Scope $scope, \DateTimeImmutable $reference): ReportResult
    {
        ['period' => $period, 'codes' => $codes, 'manufacturer' => $manufacturer, 'category' => $category, 'inScope' => $inScope] = $this->salesParams($p, $scope, $reference);
        $lines = $this->business->salesLines($scope->salesRep, $period, $codes, $manufacturer, $category);

        $op = $this->profile->operation('sales_report');
        $width = \count($op->columns);
        $scopeLabel = [] === $codes
            ? \sprintf('Przypisani klienci (%d)', \count($inScope))
            : implode(', ', array_map(static fn ($c): string => $c->name, $inScope));
        $rows = $this->meta($op->label, $period, $scopeLabel, $width);
        if (null !== $manufacturer) {
            $rows[] = Row::meta('Producent', $manufacturer, $width);
        }
        if (null !== $category) {
            $rows[] = Row::meta('Kategoria', $category, $width);
        }

        $totals = [];
        foreach ($inScope as $customer) {
            $totals[$customer->code] = ['name' => $customer->name, 'sum' => BigDecimal::of('0.00')];
        }
        $grand = BigDecimal::of('0.00');
        foreach ($lines as $line) {
            $rows[] = new Row('pozycja', [
                'pozycja', $line->inclusionDate, $line->documentNumber, 'adjustment' === $line->documentKind ? 'Korekta' : 'Faktura',
                $line->customerCode, $line->customerName, $line->partNumber, $line->manufacturerName, $line->category,
                NumberCell::quantity($line->quantity), NumberCell::money($line->netValue),
            ]);
            $totals[$line->customerCode] ??= ['name' => $line->customerName, 'sum' => BigDecimal::of('0.00')];
            $totals[$line->customerCode]['sum'] = $totals[$line->customerCode]['sum']->plus($line->netValue);
            $grand = $grand->plus($line->netValue);
        }
        uasort($totals, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        foreach ($totals as $code => $total) {
            $rows[] = self::totalRow(['suma_klienta', '', '', '', (string) $code, $total['name']], NumberCell::money($total['sum']), $width);
        }
        $rows[] = self::totalRow(['suma_calkowita'], NumberCell::money($grand), $width);

        // Every customer whose data the file may contain: the scope, plus any line's customer (defensive).
        $customers = array_values(array_unique([...array_map(static fn ($c): string => $c->code, $inScope), ...array_keys($totals)]));

        return new ReportResult('sales_report', \sprintf('%s_%s_%s.csv', $op->fileName, $period->start->format('Y-m-d'), $period->end->format('Y-m-d')), $op->columns, $rows, $period->label(), $scopeLabel, [] === $lines, array_map('strval', $customers));
    }

    /**
     * @param array<array-key, mixed> $p
     */
    private function partsCatalog(array $p): ReportResult
    {
        ['code' => $code, 'name' => $manufacturer] = $this->catalogParams($p);
        $op = $this->profile->operation('parts_catalog');
        $width = \count($op->columns);
        $rows = [
            Row::meta('Operacja', $op->label, $width),
            Row::meta('Producent', $manufacturer, $width),
            Row::meta('Stan na', $this->clock->now()->setTimezone(DateRange::zone())->format('Y-m-d'), $width),
            ...$this->versionRows($width),
        ];
        $parts = $this->business->parts($code);
        foreach ($parts as $part) {
            $rows[] = new Row('pozycja', ['pozycja', $part->manufacturerName, $part->partNumber, $part->name, $part->category, $part->unit, NumberCell::money($part->listPriceNet)]);
        }

        return new ReportResult('parts_catalog', \sprintf('%s_%s_%s.csv', $op->fileName, strtolower($code), $this->clock->now()->setTimezone(DateRange::zone())->format('Y-m-d')), $op->columns, $rows, 'nie dotyczy', $manufacturer, [] === $parts, []);
    }

    /**
     * @param array<array-key, mixed> $p
     *
     * @return array{code: string, name: string}
     */
    private function catalogParams(array $p): array
    {
        self::allowOnly($p, ['manufacturer']);
        $code = self::optional($p, 'manufacturer', self::MANUFACTURER) ?? throw new InvalidParametersException('missing_manufacturer');

        return ['code' => $code, 'name' => $this->assertManufacturer($code)];
    }

    /**
     * @param array<array-key, mixed> $p
     */
    private function discountSimulation(array $p, Scope $scope, \DateTimeImmutable $reference): ReportResult
    {
        ['customer' => $customer, 'period' => $period, 'policy' => $policy] = $this->discountParams($p, $scope, $reference);
        $code = $customer->code;
        $lines = $this->business->salesLines($scope->salesRep, $period, [$code]);

        $invoices = BigDecimal::of('0.00');
        $adjustments = BigDecimal::of('0.00');
        $documents = [];
        $lastSale = null;
        foreach ($lines as $line) {
            $documents[$line->documentNumber] = true;
            if ('adjustment' === $line->documentKind) {
                $adjustments = $adjustments->plus($line->netValue);
            } else {
                $invoices = $invoices->plus($line->netValue);
                $lastSale = max($lastSale ?? $line->inclusionDate, $line->inclusionDate);
            }
        }
        $basis = $invoices->plus($adjustments);
        $sim = $this->discounts->simulate($policy, $basis, $customer->active);

        $op = $this->profile->operation('customer_discount_simulation');
        $width = \count($op->columns);
        $tierLabel = null === $sim['tier'] ? 'brak progu' : 'od '.NumberCell::money($sim['tier']->from)->text;
        $rows = [
            Row::meta('UWAGA', self::SIMULATION_NOTICE, $width),
            ...$this->meta($op->label, $period, $customer->name.' ('.$customer->code.')', $width),
            Row::meta('Polityka', \sprintf('%s %s%s', $policy->code, $policy->version, $policy->fictional ? ' (fikcyjna)' : ''), $width),
            new Row('pozycja', ['pozycja', 'Liczba dokumentów', NumberCell::integer(\count($documents))]),
            new Row('pozycja', ['pozycja', 'Liczba pozycji', NumberCell::integer(\count($lines))]),
            new Row('pozycja', ['pozycja', 'Sprzedaż netto (faktury)', NumberCell::money($invoices)]),
            new Row('pozycja', ['pozycja', 'Korekty netto', NumberCell::money($adjustments)]),
            new Row('pozycja', ['pozycja', 'Ostatnia sprzedaż w okresie', $lastSale ?? 'brak']),
            new Row('pozycja', ['pozycja', 'Podstawa rabatu', NumberCell::money($basis)]),
            new Row('pozycja', ['pozycja', 'Próg', $tierLabel]),
            new Row('pozycja', ['pozycja', 'Stawka %', NumberCell::quantity($sim['rate']->multipliedBy(100))]),
        ];
        if (null !== $sim['reason']) {
            $rows[] = new Row('pozycja', ['pozycja', 'Uwagi', $sim['reason']]);
        }
        $rows[] = new Row('suma', ['suma', 'Symulowany rabat', NumberCell::money($sim['discount'])]);
        $rows[] = new Row('suma', ['suma', 'Netto po symulowanym rabacie', NumberCell::money($sim['net'])]);

        return new ReportResult(
            'customer_discount_simulation',
            \sprintf('%s_%s_%s_%s.csv', $op->fileName, strtolower($code), $period->start->format('Y-m-d'), $period->end->format('Y-m-d')),
            $op->columns,
            $rows,
            $period->label(),
            $customer->name,
            [] === $lines,
            [$code],
        );
    }

    /**
     * @param array<array-key, mixed> $p
     *
     * @return array{customer: \App\Module\Report\Business\Customer, period: DateRange, policy: \App\Module\Report\Business\DiscountPolicy}
     */
    private function discountParams(array $p, Scope $scope, \DateTimeImmutable $reference): array
    {
        self::allowOnly($p, ['customer', 'period', 'policy']);
        $code = self::optional($p, 'customer', self::CUSTOMER) ?? throw new InvalidParametersException('missing_customer');
        $period = $this->periods->resolve(PeriodExpression::fromArray(self::map($p, 'period')), $reference);
        $version = self::optional($p, 'policy', '/^[0-9A-Za-z.-]{1,20}$/');

        $customer = null;
        foreach ($this->business->customersOf($scope->salesRep) as $candidate) {
            if ($candidate->code === $code) {
                $customer = $candidate;
            }
        }
        if (null === $customer) {
            throw new OutOfScopeException('Customer outside the owner\'s scope');
        }
        $policy = $this->discounts->applicable($this->business->discountPolicies(), $reference, $version)
            ?? throw new InvalidParametersException('unknown_policy');

        return ['customer' => $customer, 'period' => $period, 'policy' => $policy];
    }

    /**
     * @return list<Row>
     */
    private function meta(string $operation, DateRange $period, string $scope, int $width): array
    {
        return [
            Row::meta('Operacja', $operation, $width),
            Row::meta('Zakres', $scope, $width),
            Row::meta('Okres', \sprintf('%s – %s (Europe/Warsaw)', $period->start->format('Y-m-d'), $period->end->format('Y-m-d')), $width),
            Row::meta('Waluta', 'PLN, netto po korektach, bez dokumentów anulowanych', $width),
            ...$this->versionRows($width),
        ];
    }

    /**
     * @return list<Row>
     */
    private function versionRows(int $width): array
    {
        return [
            Row::meta('Wersje', \sprintf('profil %s · dane %s', $this->profile->label(), $this->business->dataVersion()), $width),
            Row::meta('Wygenerowano', $this->clock->now()->setTimezone(DateRange::zone())->format('Y-m-d H:i'), $width),
        ];
    }

    private function assertManufacturer(string $code): string
    {
        foreach ($this->business->manufacturers() as $manufacturer) {
            if ($manufacturer->code === $code) {
                return $manufacturer->name;
            }
        }

        throw new InvalidParametersException('unknown_manufacturer');
    }

    /**
     * @param array<array-key, mixed> $p
     * @param list<string>            $allowed
     */
    private static function allowOnly(array $p, array $allowed): void
    {
        if ([] !== array_diff(array_keys($p), $allowed)) {
            throw new InvalidParametersException('unexpected_parameter');
        }
    }

    /**
     * @param array<array-key, mixed> $p
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $p, string $key): array
    {
        $value = $p[$key] ?? null;

        return \is_array($value) ? $value : throw new InvalidParametersException('missing_'.$key);
    }

    /**
     * @param array<array-key, mixed> $p
     */
    private static function optional(array $p, string $key, string $pattern): ?string
    {
        $value = $p[$key] ?? null;
        if (null === $value) {
            return null;
        }

        return \is_string($value) && 1 === preg_match($pattern, $value) ? $value : throw new InvalidParametersException('invalid_'.$key);
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value) || [] === $value || \count($value) > 100) {
            throw new InvalidParametersException('invalid_customers');
        }
        foreach ($value as $code) {
            if (!\is_string($code) || 1 !== preg_match(self::CUSTOMER, $code)) {
                throw new InvalidParametersException('invalid_customers');
            }
        }

        return array_values(array_unique($value));
    }

    /**
     * Total row: label cells first, the amount in the last column.
     *
     * @param list<string> $cells
     */
    private static function totalRow(array $cells, NumberCell $amount, int $width): Row
    {
        $padded = array_pad($cells, $width - 1, '');
        $padded[] = $amount;

        return new Row($cells[0], $padded);
    }
}
