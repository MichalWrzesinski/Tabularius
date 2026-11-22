<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Serves captured business API responses (tests/Fixtures/business) to the real RestBusinessApi adapter, so engine
 * tests exercise adapter + engine end to end. Unknown requests fail the test.
 */
final class FixtureBusinessHttp
{
    /** @var list<string> */
    public array $requests = [];

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = $url;
            $headers = $options['headers'] ?? [];
            \assert(\is_array($headers));
            if (!\in_array('Authorization: Bearer test-token', $headers, true)) {
                return new MockResponse('{"code":"unauthorized"}', ['http_code' => 401]);
            }
            $file = self::fixtureFor($url);
            if (null === $file) {
                throw new \LogicException('No fixture for '.$url);
            }

            return new MockResponse((string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/business/'.$file.'.json'), ['http_code' => 200]);
        }, 'http://business.test');
    }

    private static function fixtureFor(string $url): ?string
    {
        $parts = parse_url($url);
        $path = \is_array($parts) ? ($parts['path'] ?? '') : '';
        parse_str(\is_array($parts) ? ($parts['query'] ?? '') : '', $q);

        return match (true) {
            '/v1/meta' === $path => 'meta',
            '/v1/sales-reps/PH-01/customers' === $path => 'customers_PH-01',
            '/v1/manufacturers' === $path => 'manufacturers',
            '/v1/manufacturers/BOSCH/parts' === $path => 'parts_BOSCH_p1',
            '/v1/discount-policies' === $path => 'discount-policies',
            '/v1/sales-lines' === $path && 'PH-01' === ($q['salesRep'] ?? null) => self::salesFixture($q),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $q
     */
    private static function salesFixture(array $q): ?string
    {
        $month = substr(\is_string($q['from'] ?? null) ? $q['from'] : '', 0, 7);
        $customers = $q['customers'] ?? null;

        return match ([$customers, $month]) {
            [null, '2026-10'] => 'sales_PH-01_2026-10',
            ['K-004', '2026-10'] => 'sales_PH-01_K-004_2026-10',
            ['K-004', '2026-09'] => 'sales_PH-01_K-004_2026-09',
            ['K-006', '2026-10'] => 'sales_PH-01_K-006_2026-10',
            ['K-007', '2026-05'] => 'sales_PH-01_K-007_2026-05',
            default => null,
        };
    }
}
