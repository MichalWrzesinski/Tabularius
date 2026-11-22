<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\Module\Report\Business\DataSourceException;
use App\Module\Report\Business\OutOfScopeException;
use App\Module\Report\Business\ReportTooLargeException;
use App\Module\Report\Business\RestBusinessApi;
use App\Module\Report\Period\DateRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Failure modes of the business adapter: controlled outcomes, never partial data (brief §8, backend standard).
 */
final class RestBusinessApiTest extends TestCase
{
    private static function api(MockResponse|callable ...$responses): RestBusinessApi
    {
        return new RestBusinessApi(new MockHttpClient(array_values($responses), 'http://b.test'), 'http://b.test/', 't', maxRows: 3, timeout: 0.5);
    }

    /**
     * @return array<string, string>
     */
    private static function line(int $i): array
    {
        return ['documentNumber' => "FV/$i", 'documentKind' => 'invoice', 'inclusionDate' => '2026-10-02', 'customerCode' => 'K-001', 'customerName' => 'A',
            'partNumber' => 'P', 'manufacturerName' => 'M', 'category' => 'C', 'quantity' => '1.000', 'netValue' => '10.00'];
    }

    public function testTimeoutIsADataSourceError(): void
    {
        $this->expectException(DataSourceException::class);
        self::api(new MockResponse('', ['error' => 'Idle timeout reached']))->dataVersion();
    }

    public function testServerErrorIsADataSourceError(): void
    {
        $this->expectException(DataSourceException::class);
        self::api(new MockResponse('{}', ['http_code' => 503]))->manufacturers();
    }

    public function testForbiddenIsAScopeRefusal(): void
    {
        $this->expectException(OutOfScopeException::class);
        self::api(new MockResponse('{"code":"out_of_scope"}', ['http_code' => 403]))->salesLines('PH-01', DateRange::of('2026-10-01', '2026-10-31'), ['K-008']);
    }

    public function testClientErrorsAreContractBugsNotUserProblems(): void
    {
        $this->expectException(\LogicException::class);
        self::api(new MockResponse('{"code":"unauthorized"}', ['http_code' => 401]))->customersOf('PH-01');
    }

    public function testInvalidJsonIsADataSourceError(): void
    {
        $this->expectException(DataSourceException::class);
        self::api(new MockResponse('<html>', ['http_code' => 200]))->dataVersion();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function brokenLines(): iterable
    {
        yield 'float money' => [['netValue' => 10.5] + self::line(1)];
        yield 'money with comma' => [['netValue' => '10,50'] + self::line(1)];
        yield 'scientific notation' => [['netValue' => '1e3'] + self::line(1)];
        yield 'missing field' => [array_diff_key(self::line(1), ['customerCode' => 0])];
        yield 'number instead of string' => [['documentNumber' => 7] + self::line(1)];
    }

    /**
     * @param array<string, mixed> $line
     */
    #[DataProvider('brokenLines')]
    public function testRejectsInvalidFields(array $line): void
    {
        $this->expectException(DataSourceException::class);
        self::api(new MockResponse((string) json_encode(['items' => [$line], 'total' => 1])))->salesLines('PH-01', DateRange::of('2026-10-01', '2026-10-31'));
    }

    public function testRejectsInvalidListsAndBooleans(): void
    {
        foreach ([
            static fn () => self::api(new MockResponse('{"items":{"a":1}}'))->manufacturers(),
            static fn () => self::api(new MockResponse('{"items":[1]}'))->manufacturers(),
            static fn () => self::api(new MockResponse('{"items":[{"code":"K-1","name":"A","active":"yes"}]}'))->customersOf('PH-01'),
            static fn () => self::api(new MockResponse('{"items":[{"code":"B","name":"B","aliases":[1],"active":true}]}'))->manufacturers(),
            static fn () => self::api(new MockResponse('{"items":[],"total":"x"}'))->parts('BOSCH'),
        ] as $i => $call) {
            try {
                $call();
                self::fail('Case '.$i.' should fail');
            } catch (DataSourceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPaginatesUntilTotal(): void
    {
        $requested = [];
        $page = static function (array $items, int $total) use (&$requested): callable {
            return static function (string $method, string $url) use ($items, $total, &$requested): MockResponse {
                $requested[] = $url;

                return new MockResponse((string) json_encode(['items' => $items, 'total' => $total]));
            };
        };
        $api = new RestBusinessApi(new MockHttpClient([$page([self::line(1), self::line(2)], 3), $page([self::line(3)], 3)], 'http://b.test'), 'http://b.test', 't', maxRows: 10);

        $lines = $api->salesLines('PH-01', DateRange::of('2026-10-01', '2026-10-31'), ['K-001'], 'BOSCH', 'Hamulce');

        self::assertCount(3, $lines);
        self::assertSame('10.00', (string) $lines[2]->netValue);
        self::assertStringContainsString('page=2', $requested[1]);
        self::assertStringContainsString('customers=K-001', $requested[0]);
        self::assertStringContainsString('manufacturer=BOSCH', $requested[0]);
    }

    public function testRefusesReportsAboveTheRowLimitInsteadOfTruncating(): void
    {
        $this->expectException(ReportTooLargeException::class);
        self::api(new MockResponse((string) json_encode(['items' => [self::line(1)], 'total' => 4])))->salesLines('PH-01', DateRange::of('2026-10-01', '2026-10-31'));
    }

    public function testListsRepresentativeCodes(): void
    {
        self::assertSame(['PH-01', 'PH-02'], self::api(new MockResponse('{"items":[{"code":"PH-01","name":"A"},{"code":"PH-02","name":"B"}]}'))->salesReps());
    }

    public function testSendsTheBearerToken(): void
    {
        $seen = null;
        $api = new RestBusinessApi(new MockHttpClient(static function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o['headers'] ?? null;

            return new MockResponse('{"dataVersion":"v"}');
        }), 'http://b.test', 'secret-token');

        self::assertSame('v', $api->dataVersion());
        self::assertIsArray($seen);
        self::assertContains('Authorization: Bearer secret-token', $seen);
    }
}
