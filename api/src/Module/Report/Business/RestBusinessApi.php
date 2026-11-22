<?php

declare(strict_types=1);

namespace App\Module\Report\Business;

use App\Module\Report\Period\DateRange;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * `rest-json` adapter for the demo business system (business/README.md). Timeouts are explicit, every field is
 * validated, list endpoints are paginated up to the profile's row limit. Transport errors, timeouts and 5xx are
 * data-source errors (controlled outcome, no partial report); 403 out_of_scope is a scope refusal.
 */
final class RestBusinessApi implements BusinessApi
{
    private const int PER_PAGE = 1000;

    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire(env: 'BUSINESS_API_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'BUSINESS_API_TOKEN')]
        private readonly string $token,
        private readonly int $maxRows = 50000,
        private readonly float $timeout = 10.0,
    ) {
    }

    public function salesReps(): array
    {
        return array_map(static fn (array $r): string => self::s($r, 'code'), self::items($this->get('/v1/sales-reps')['items'] ?? null));
    }

    public function customersOf(string $salesRep): array
    {
        return array_map(
            static fn (array $c): Customer => new Customer(self::s($c, 'code'), self::s($c, 'name'), self::b($c, 'active')),
            self::items($this->get('/v1/sales-reps/'.rawurlencode($salesRep).'/customers')['items'] ?? null),
        );
    }

    public function salesLines(string $salesRep, DateRange $period, array $customerCodes = [], ?string $manufacturer = null, ?string $category = null): array
    {
        $query = array_filter([
            'salesRep' => $salesRep,
            'from' => $period->start->format('Y-m-d'),
            'to' => $period->end->format('Y-m-d'),
            'customers' => [] === $customerCodes ? null : implode(',', $customerCodes),
            'manufacturer' => $manufacturer,
            'category' => $category,
        ], static fn (?string $v): bool => null !== $v);

        return array_map(static fn (array $l): SalesLine => new SalesLine(
            self::s($l, 'documentNumber'),
            self::s($l, 'documentKind'),
            self::s($l, 'inclusionDate'),
            self::s($l, 'customerCode'),
            self::s($l, 'customerName'),
            self::s($l, 'partNumber'),
            self::s($l, 'manufacturerName'),
            self::s($l, 'category'),
            self::d($l, 'quantity'),
            self::d($l, 'netValue'),
        ), $this->paginate('/v1/sales-lines', $query));
    }

    public function manufacturers(): array
    {
        return array_map(static function (array $m): Manufacturer {
            $aliases = $m['aliases'] ?? null;
            if (!\is_array($aliases) || !array_is_list($aliases) || array_filter($aliases, static fn (mixed $a): bool => !\is_string($a))) {
                throw new DataSourceException('Business API returned an invalid manufacturer');
            }

            /** @var list<string> $aliases */
            return new Manufacturer(self::s($m, 'code'), self::s($m, 'name'), $aliases, self::b($m, 'active'));
        }, self::items($this->get('/v1/manufacturers')['items'] ?? null));
    }

    public function parts(string $manufacturerCode): array
    {
        return array_map(static fn (array $p): Part => new Part(
            self::s($p, 'manufacturerName'),
            self::s($p, 'partNumber'),
            self::s($p, 'name'),
            self::s($p, 'category'),
            self::s($p, 'unit'),
            self::d($p, 'listPriceNet'),
        ), $this->paginate('/v1/manufacturers/'.rawurlencode($manufacturerCode).'/parts', []));
    }

    public function discountPolicies(): array
    {
        return array_map(static function (array $p): DiscountPolicy {
            $tiers = [];
            foreach (self::items($p['tiers'] ?? null) as $t) {
                $tiers[] = new DiscountTier(self::d($t, 'from'), self::d($t, 'rate'));
            }

            return new DiscountPolicy(self::s($p, 'code'), self::s($p, 'version'), self::s($p, 'validFrom'), $tiers, self::b($p, 'fictional'));
        }, self::items($this->get('/v1/discount-policies')['items'] ?? null));
    }

    public function dataVersion(): string
    {
        return self::s($this->get('/v1/meta'), 'dataVersion');
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<array-key, mixed>>
     */
    private function paginate(string $path, array $query): array
    {
        $items = [];
        for ($page = 1;; ++$page) {
            $body = $this->get($path, $query + ['page' => (string) $page, 'perPage' => (string) self::PER_PAGE]);
            $total = $body['total'] ?? null;
            if (!\is_int($total) || $total < 0) {
                throw new DataSourceException('Business API returned an invalid page');
            }
            if ($total > $this->maxRows) {
                throw new ReportTooLargeException(\sprintf('The report would have %d rows (limit %d)', $total, $this->maxRows));
            }
            $pageItems = self::items($body['items'] ?? null);
            array_push($items, ...$pageItems);
            if ([] === $pageItems || \count($items) >= $total) {
                return $items;
            }
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client->request('GET', rtrim($this->baseUrl, '/').$path, [
                'query' => $query,
                'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'],
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout * 2,
            ]);
            $status = $response->getStatusCode();
            if (403 === $status) {
                throw new OutOfScopeException('The business system refused the requested scope');
            }
            if ($status >= 500) {
                throw new DataSourceException(\sprintf('Business API answered %d', $status));
            }
            if (200 !== $status) {
                // 400/401/404 mean a contract or configuration bug on our side, not a user problem.
                throw new \LogicException(\sprintf('Business API rejected the request with %d for %s', $status, $path));
            }
            $body = $response->toArray(false);
        } catch (HttpException $e) {
            throw new DataSourceException('Business API unavailable: '.$e::class, 0, $e);
        }

        return $body;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function items(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new DataSourceException('Business API returned an invalid list');
        }
        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw new DataSourceException('Business API returned an invalid list item');
            }
        }

        /** @var list<array<array-key, mixed>> $value */
        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function s(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : throw new DataSourceException('Business API: invalid field '.$key);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function b(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) ? $value : throw new DataSourceException('Business API: invalid field '.$key);
    }

    /**
     * Decimal strings only (money and quantities); never floats.
     *
     * @param array<array-key, mixed> $data
     */
    private static function d(array $data, string $key): BigDecimal
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || 1 !== preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', $value)) {
            throw new DataSourceException('Business API: invalid decimal '.$key);
        }
        try {
            return BigDecimal::of($value);
        } catch (MathException $e) {
            throw new DataSourceException('Business API: invalid decimal '.$key, 0, $e);
        }
    }
}
