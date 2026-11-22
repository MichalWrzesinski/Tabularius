<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Db\Value;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Read-only business API (ADR 0002) against the seed for 2026-11-15.
 */
final class ApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRejectsMissingOrWrongToken(): void
    {
        $this->client->request('GET', '/v1/meta');
        $this->assertProblem(401, 'unauthorized');

        $this->client->request('GET', '/v1/meta', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']);
        $this->assertProblem(401, 'unauthorized');

        $this->client->request('GET', '/v1/meta', server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('a:b')]);
        $this->assertProblem(401, 'unauthorized');
    }

    public function testHealthNeedsNoToken(): void
    {
        $this->client->request('GET', '/health');
        self::assertResponseIsSuccessful();
    }

    public function testMeta(): void
    {
        $body = $this->get('/v1/meta');
        self::assertSame('2026.11-1', $body['dataVersion']);
        self::assertSame('2026-11-15', $body['referenceDate']);
        self::assertSame(20261122, $body['randomSeed']);
    }

    public function testRepresentatives(): void
    {
        $items = $this->items('/v1/sales-reps');
        self::assertSame(['PH-01', 'PH-02', 'PH-03', 'PH-04', 'PH-05'], array_column($items, 'code'));
        self::assertSame('Handlowiec demonstracyjny', $items[0]['name'] ?? null);
    }

    public function testRepresentativeCustomers(): void
    {
        $items = $this->items('/v1/sales-reps/PH-01/customers');
        self::assertSame(['K-001', 'K-002', 'K-003', 'K-004', 'K-005', 'K-006', 'K-007'], array_column($items, 'code'));
        self::assertFalse($items[6]['active'] ?? null);

        $this->authorized('/v1/sales-reps/PH-99/customers');
        $this->assertProblem(404, 'not_found');
        $this->authorized('/v1/sales-reps/x/customers');
        $this->assertProblem(404, 'not_found');
    }

    public function testSalesLinesForAPeriodAndAllAssignedCustomers(): void
    {
        $body = $this->get('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31');
        self::assertSame(8, $body['total']); // K-001 ×2, K-002 invoice + adjustment, K-003, K-004 ×2, K-005 (canceled excluded)
        $items = self::listOf($body['items']);
        self::assertSame('2026-10-02', $items[0]['inclusionDate'] ?? null);
        $sum = 0;
        foreach ($items as $line) {
            $sum += Value::int(str_replace('.', '', Value::string($line['netValue'] ?? null)));
            self::assertArrayNotHasKey('purchasePriceNet', $line);
        }
        self::assertSame(5310740, $sum); // 319.20 + 754.10 + 745.00 + 50 000.10 + 1 289.00 = 53 107.40
    }

    public function testSalesLinesFiltersAndPagination(): void
    {
        $page = $this->get('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31&customers=K-002,K-003&perPage=2&page=2');
        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['page']);
        self::assertCount(1, self::listOf($page['items']));

        $bosch = $this->get('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31&manufacturer=BOSCH');
        self::assertSame(['BOSCH'], array_values(array_unique(array_map(Value::string(...), array_column(self::listOf($bosch['items']), 'manufacturerCode')))));

        $all = self::listOf($this->get('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31')['items']);
        $wanted = Value::string($all[0]['category'] ?? null);
        $filtered = self::listOf($this->get('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31&category='.rawurlencode($wanted))['items']);
        self::assertNotEmpty($filtered);
        self::assertLessThan(\count($all), \count($filtered));
        foreach ($filtered as $line) {
            self::assertSame($wanted, $line['category'] ?? null);
        }
    }

    public function testSalesLinesRefusesCustomersOutsideTheRepresentativesScope(): void
    {
        $this->authorized('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31&customers=K-001,K-008');
        $body = $this->assertProblem(403, 'out_of_scope');
        $json = json_encode($body, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('K-008', $json);
        self::assertStringNotContainsString('Alfatrans', $json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSalesLineQueries(): iterable
    {
        yield 'unknown parameter' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&sql=SELECT', 'unknown_parameter'];
        yield 'missing rep' => ['from=2026-10-01&to=2026-10-31', 'missing_parameter'];
        yield 'missing to' => ['salesRep=PH-01&from=2026-10-01', 'missing_parameter'];
        yield 'bad date' => ['salesRep=PH-01&from=2026-02-30&to=2026-03-31', 'invalid_parameter'];
        yield 'bad date format' => ['salesRep=PH-01&from=1.10.2026&to=2026-10-31', 'invalid_parameter'];
        yield 'from after to' => ['salesRep=PH-01&from=2026-10-31&to=2026-10-01', 'invalid_period'];
        yield 'period too long' => ['salesRep=PH-01&from=2025-01-01&to=2026-10-31', 'invalid_period'];
        yield 'injection in customers' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&customers=K-001\'--', 'invalid_parameter'];
        yield 'injection in category' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&category=x%27%20OR%201%3D1', 'invalid_parameter'];
        yield 'empty list item' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&customers=K-001,,K-002', 'invalid_parameter'];
        yield 'perPage too big' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&perPage=5000', 'invalid_parameter'];
        yield 'perPage over max' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&perPage=1001', 'invalid_parameter'];
        yield 'page zero' => ['salesRep=PH-01&from=2026-10-01&to=2026-10-31&page=0', 'invalid_parameter'];
        yield 'array parameter' => ['salesRep[]=PH-01&from=2026-10-01&to=2026-10-31', 'invalid_parameter'];
    }

    /**
     * @dataProvider invalidSalesLineQueries
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSalesLineQueries')]
    public function testSalesLinesRejectsInvalidQueries(string $query, string $code): void
    {
        $this->authorized('/v1/sales-lines?'.$query);
        $this->assertProblem(400, $code);
    }

    public function testTooManyCustomersAreRejected(): void
    {
        $codes = implode(',', array_map(static fn (int $i): string => \sprintf('K-%03d', $i), range(1, 101)));
        $this->authorized('/v1/sales-lines?salesRep=PH-01&from=2026-10-01&to=2026-10-31&customers='.$codes);
        $this->assertProblem(400, 'invalid_parameter');
    }

    public function testManufacturersWithAliases(): void
    {
        $items = $this->items('/v1/manufacturers');
        $byCode = array_column($items, null, 'code');
        self::assertSame(['Robert Bosch'], $byCode['BOSCH']['aliases'] ?? null);
        self::assertContains(false, array_column($items, 'active'));
    }

    public function testCatalogReturnsOnlyActivePartsAndPermittedFields(): void
    {
        $body = $this->get('/v1/manufacturers/BOSCH/parts?perPage=100');
        self::assertSame(36, $body['total']); // 38 parts, 2 inactive
        $parts = self::listOf($body['items']);
        self::assertSame(['manufacturerName', 'partNumber', 'name', 'category', 'unit', 'listPriceNet'], array_keys($parts[0]));
        self::assertContains('-20% Wkład filtra powietrza (wyprzedaż)', array_column($parts, 'name'));

        self::assertSame(0, $this->get('/v1/manufacturers/LUCAS/parts')['total'], 'inactive manufacturer has no active parts');
        $this->authorized('/v1/manufacturers/NOPE/parts');
        $this->assertProblem(404, 'not_found');
        $this->authorized('/v1/manufacturers/bosch;drop/parts');
        $this->assertProblem(404, 'not_found');
    }

    public function testDiscountPolicyIsMarkedFictional(): void
    {
        $items = $this->items('/v1/discount-policies');
        self::assertSame('DEMO-RABAT', $items[0]['code'] ?? null);
        self::assertTrue($items[0]['fictional'] ?? null);
        $tiers = self::listOf($items[0]['tiers'] ?? null);
        self::assertSame('50000.00', $tiers[2]['from'] ?? null);
    }

    public function testWritesAndUnknownRoutesAreRefused(): void
    {
        $this->client->request('POST', '/v1/meta', server: $this->auth());
        $this->assertProblem(405, 'method_not_allowed');
        $this->authorized('/v1/nope');
        $this->assertProblem(404, 'not_found');
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        $token = $_SERVER['BUSINESS_API_TOKEN'] ?? $_ENV['BUSINESS_API_TOKEN'] ?? null;
        self::assertIsString($token);

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function authorized(string $uri): void
    {
        $this->client->request('GET', $uri, server: $this->auth());
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri): array
    {
        $this->authorized($uri);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $uri): array
    {
        return self::listOf($this->get($uri)['items']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        self::assertIsList($value);
        foreach ($value as $item) {
            self::assertIsArray($item);
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertProblem(int $status, string $code): array
    {
        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($code, $body['code']);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
