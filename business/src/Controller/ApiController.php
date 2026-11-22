<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiException;
use App\Api\Query;
use App\Db\Value;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only API of the demo business system (ADR 0002). Runs as business_reader against v_* views only.
 * It accepts no SQL, table names or expressions; every filter is a validated parameter. Scope: the caller
 * (Tabularius) sends the sales representative from trusted context, and requested customers must be assigned
 * to that representative, otherwise 403 without saying which customer failed.
 */
#[Route('/v1', methods: ['GET'])]
final class ApiController
{
    private const string REP = '/^PH-\d{2}$/';
    private const string CUSTOMER = '/^K-\d{3}$/';
    private const string MANUFACTURER = '/^[A-Z0-9-]{2,20}$/';
    private const string CATEGORY = '/^[\p{L} ]{2,40}$/u';
    private const int MAX_PERIOD_DAYS = 400;

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/meta', name: 'api_meta')]
    public function meta(Request $request): JsonResponse
    {
        new Query($request, []);
        $row = $this->connection->fetchAssociative('SELECT data_version, random_seed, reference_date, generated_at FROM v_seed_meta');
        if (false === $row) {
            throw new ApiException(503, 'not_seeded', 'Business data has not been loaded');
        }

        return new JsonResponse([
            'dataVersion' => $row['data_version'],
            'randomSeed' => Value::int($row['random_seed']),
            'referenceDate' => $row['reference_date'],
            'generatedAt' => (new \DateTimeImmutable(Value::string($row['generated_at'])))->format(\DATE_ATOM),
        ]);
    }

    #[Route('/sales-reps/{code}/customers', name: 'api_rep_customers')]
    public function repCustomers(Request $request, string $code): JsonResponse
    {
        new Query($request, []);
        $this->assertRep($code);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT code, name, city, active FROM v_customer WHERE sales_rep_code = ? ORDER BY code',
            [$code],
        );

        return new JsonResponse(['items' => array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'name' => $r['name'],
            'city' => $r['city'],
            'active' => (bool) $r['active'],
        ], $rows)]);
    }

    #[Route('/sales-lines', name: 'api_sales_lines')]
    public function salesLines(Request $request): JsonResponse
    {
        $query = new Query($request, ['salesRep', 'from', 'to', 'customers', 'manufacturer', 'category', 'page', 'perPage']);
        $rep = (string) $query->string('salesRep', self::REP, true);
        $from = $query->date('from');
        $to = $query->date('to');
        if ($from > $to) {
            throw new ApiException(400, 'invalid_period', '"from" must not be after "to"');
        }
        if ($from->diff($to)->days >= self::MAX_PERIOD_DAYS) {
            throw new ApiException(400, 'invalid_period', 'Period too long');
        }
        $customers = $query->list('customers', self::CUSTOMER);
        $manufacturer = $query->string('manufacturer', self::MANUFACTURER);
        $category = $query->string('category', self::CATEGORY);
        [$page, $perPage] = $query->pagination();

        $this->assertRep($rep);
        $assigned = array_map(Value::string(...), $this->connection->fetchFirstColumn('SELECT code FROM v_customer WHERE sales_rep_code = ?', [$rep]));
        if ([] !== array_diff($customers, $assigned)) {
            throw new ApiException(403, 'out_of_scope', 'Requested customers are outside the representative\'s scope');
        }

        $where = ['sales_rep_code = :rep', 'inclusion_date BETWEEN :from AND :to'];
        $params = ['rep' => $rep, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        $types = [];
        if ([] !== $customers) {
            $where[] = 'customer_code IN (:customers)';
            $params['customers'] = $customers;
            $types['customers'] = ArrayParameterType::STRING;
        }
        if (null !== $manufacturer) {
            $where[] = 'manufacturer_code = :manufacturer';
            $params['manufacturer'] = $manufacturer;
        }
        if (null !== $category) {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }
        $condition = implode(' AND ', $where);
        $total = Value::int($this->connection->fetchOne("SELECT count(*) FROM v_sales_line WHERE $condition", $params, $types));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT document_number, document_kind, inclusion_date, customer_code, customer_name, part_number,
                    manufacturer_code, manufacturer_name, category, quantity, net_value
               FROM v_sales_line WHERE $condition
              ORDER BY inclusion_date, document_number, line_id
              LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new JsonResponse([
            'items' => array_map(static fn (array $r): array => [
                'documentNumber' => $r['document_number'],
                'documentKind' => $r['document_kind'],
                'inclusionDate' => $r['inclusion_date'],
                'customerCode' => $r['customer_code'],
                'customerName' => $r['customer_name'],
                'partNumber' => $r['part_number'],
                'manufacturerCode' => $r['manufacturer_code'],
                'manufacturerName' => $r['manufacturer_name'],
                'category' => $r['category'],
                'quantity' => $r['quantity'],
                'netValue' => $r['net_value'],
            ], $rows),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ]);
    }

    #[Route('/manufacturers', name: 'api_manufacturers')]
    public function manufacturers(Request $request): JsonResponse
    {
        new Query($request, []);
        $rows = $this->connection->fetchAllAssociative('SELECT code, name, array_to_json(aliases) AS aliases, active FROM v_manufacturer ORDER BY name');

        return new JsonResponse(['items' => array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'name' => $r['name'],
            'aliases' => json_decode(Value::string($r['aliases']), true, 2, \JSON_THROW_ON_ERROR),
            'active' => (bool) $r['active'],
        ], $rows)]);
    }

    #[Route('/manufacturers/{code}/parts', name: 'api_manufacturer_parts')]
    public function parts(Request $request, string $code): JsonResponse
    {
        $query = new Query($request, ['page', 'perPage']);
        [$page, $perPage] = $query->pagination();
        if (1 !== preg_match(self::MANUFACTURER, $code) || false === $this->connection->fetchOne('SELECT 1 FROM v_manufacturer WHERE code = ?', [$code])) {
            throw new ApiException(404, 'not_found', 'Manufacturer not found');
        }
        $total = Value::int($this->connection->fetchOne('SELECT count(*) FROM v_part_catalog WHERE manufacturer_code = ? AND active', [$code]));
        // Only the fields permitted by the integration profile; purchase prices and suppliers are not in the view.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT manufacturer_name, part_number, name, category, unit, list_price_net
               FROM v_part_catalog WHERE manufacturer_code = ? AND active
              ORDER BY part_number LIMIT ? OFFSET ?',
            [$code, $perPage, ($page - 1) * $perPage],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return new JsonResponse([
            'items' => array_map(static fn (array $r): array => [
                'manufacturerName' => $r['manufacturer_name'],
                'partNumber' => $r['part_number'],
                'name' => $r['name'],
                'category' => $r['category'],
                'unit' => $r['unit'],
                'listPriceNet' => $r['list_price_net'],
            ], $rows),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ]);
    }

    #[Route('/discount-policies', name: 'api_discount_policies')]
    public function discountPolicies(Request $request): JsonResponse
    {
        new Query($request, []);
        $rows = $this->connection->fetchAllAssociative('SELECT code, version, valid_from, tiers, fictional FROM v_discount_policy ORDER BY code, valid_from');

        return new JsonResponse(['items' => array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'version' => $r['version'],
            'validFrom' => $r['valid_from'],
            'tiers' => json_decode(Value::string($r['tiers']), true, 4, \JSON_THROW_ON_ERROR),
            'fictional' => (bool) $r['fictional'],
        ], $rows)]);
    }

    private function assertRep(string $code): void
    {
        if (1 !== preg_match(self::REP, $code) || false === $this->connection->fetchOne('SELECT 1 FROM v_sales_rep WHERE code = ?', [$code])) {
            throw new ApiException(404, 'not_found', 'Sales representative not found');
        }
    }
}
