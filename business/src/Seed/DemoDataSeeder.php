<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic synthetic data for the demo wholesaler (docs/tasks/TASK-002-business-system.md).
 *
 * Every date is relative to a reference date R, so the demo stays useful over time: the same R and seed always
 * produce identical data, and refreshing with a new R moves the whole history. Customers of the demo
 * representative PH-01 get hand-placed documents in the two completed months before R ("known answers" used by
 * report tests); all other data is generated from a fixed random seed. Some documents lie in the future and must
 * never appear in a period that ends yesterday.
 */
final class DemoDataSeeder
{
    public const string DATA_VERSION = '2026.11-1';
    public const int RANDOM_SEED = 20261122;

    private const array TABLES = ['sales_line', 'sales_document', 'customer_assignment', 'customer', 'sales_rep', 'part', 'supplier', 'manufacturer', 'discount_policy', 'seed_meta'];

    private const array MANUFACTURERS = [
        [1, 'BOSCH', 'Bosch', ['Robert Bosch'], true],
        [2, 'MANN', 'Mann-Filter', ['Mann', 'MANN+HUMMEL'], true],
        [3, 'TRW', 'TRW', ['ZF TRW'], true],
        [4, 'VALEO', 'Valeo', [], true],
        [5, 'SKF', 'SKF', [], true],
        [6, 'LUCAS', 'Lucas', [], false],
    ];

    private const array CATEGORIES = ['Hamulce', 'Filtry', 'Zapłon', 'Oświetlenie', 'Zawieszenie', 'Wycieraczki', 'Sprzęgła', 'Łożyska'];

    private const array REPS = [
        [1, 'PH-01', 'Handlowiec demonstracyjny'],
        [2, 'PH-02', 'Piotr Nowak'],
        [3, 'PH-03', 'Marta Wiśniewska'],
        [4, 'PH-04', 'Jan Zieliński'],
        [5, 'PH-05', 'Ewa Dąbrowska'],
    ];

    /** [code, name, city, active, rep id] — similar names on purpose (Alfa …), one inactive, one without sales. */
    private const array CUSTOMERS = [
        ['K-001', 'Alfa Motors Sp. z o.o.', 'Warszawa', true, 1],
        ['K-002', 'Alfa-Tech Hurtownia', 'Łódź', true, 1],
        ['K-003', '+48 Auto Części', 'Kraków', true, 1],
        ['K-004', 'Moto-Lux Gdańsk', 'Gdańsk', true, 1],
        ['K-005', 'Serwis Bielany', 'Warszawa', true, 1],
        ['K-006', 'Warsztat Pod Lipami', 'Piaseczno', true, 1],
        ['K-007', 'Auto-Kombi Radom', 'Radom', false, 1],
        ['K-008', 'Alfatrans Logistyka', 'Poznań', true, 2],
        ['K-009', 'Auto Serwis Malta', 'Poznań', true, 2],
        ['K-010', 'Hurtownia Motoryzacyjna Warta', 'Gniezno', true, 2],
        ['K-011', 'Garaż u Zbyszka', 'Konin', true, 2],
        ['K-012', 'Car-Med Leszno', 'Leszno', true, 2],
        ['K-013', 'Polmot Kalisz', 'Kalisz', false, 2],
        ['K-014', 'Moto-Partner Wrocław', 'Wrocław', true, 3],
        ['K-015', 'Serwis Krzyki', 'Wrocław', true, 3],
        ['K-016', 'Autoczęści Opole', 'Opole', true, 3],
        ['K-017', 'Odra Car Service', 'Legnica', true, 3],
        ['K-018', 'Mechanika Pojazdowa Ślęża', 'Sobótka', true, 3],
        ['K-019', 'Beta Motors', 'Katowice', true, 3],
        ['K-020', 'Auto-Śląsk Gliwice', 'Gliwice', true, 4],
        ['K-021', 'Serwis Zabrze-Centrum', 'Zabrze', true, 4],
        ['K-022', 'Silesia Truck Parts', 'Tychy', true, 4],
        ['K-023', 'Warsztat Rybnik', 'Rybnik', true, 4],
        ['K-024', 'Bielsko Auto Service', 'Bielsko-Biała', true, 4],
        ['K-025', 'Lublin Car Center', 'Lublin', true, 5],
        ['K-026', 'Auto-Wschód Zamość', 'Zamość', true, 5],
        ['K-027', 'Serwis Puławy', 'Puławy', true, 5],
        ['K-028', 'Moto-Bug Biała Podlaska', 'Biała Podlaska', true, 5],
        ['K-029', 'Gamma Autoczęści', 'Chełm', true, 5],
        ['K-030', 'Rzeszów Motors', 'Rzeszów', true, 5],
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $rows = [];
    private int $documentId = 0;
    private int $lineId = 0;
    /** @var array<string, int> */
    private array $numberSeq = [];
    /** @var array<int, int> list price per part id, in grosze */
    private array $listPrice = [];

    /**
     * @return array<string, list<array<string, mixed>>> rows per table
     */
    public function build(\DateTimeImmutable $reference): array
    {
        $this->rows = array_fill_keys(self::TABLES, []);
        $this->documentId = 0;
        $this->lineId = 0;
        $this->numberSeq = [];
        $this->listPrice = [];
        $random = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $r = $reference->setTime(0, 0);

        foreach (self::MANUFACTURERS as [$id, $code, $name, $aliases, $active]) {
            $this->rows['manufacturer'][] = ['id' => $id, 'code' => $code, 'name' => $name, 'aliases' => '{'.implode(',', array_map(static fn (string $a): string => '"'.$a.'"', $aliases)).'}', 'active' => $active];
        }
        foreach ([[1, 'Inter-Parts Sp. z o.o.'], [2, 'Moto-Dystrybucja S.A.'], [3, 'EuroAuto Logistics']] as [$id, $name]) {
            $this->rows['supplier'][] = ['id' => $id, 'name' => $name];
        }
        $this->buildParts($random);
        foreach (self::REPS as [$id, $code, $name]) {
            $this->rows['sales_rep'][] = ['id' => $id, 'code' => $code, 'name' => $name];
        }
        foreach (self::CUSTOMERS as $i => [$code, $name, $city, $active, $rep]) {
            $this->rows['customer'][] = ['id' => $i + 1, 'code' => $code, 'name' => $name, 'city' => $city, 'active' => $active];
            $this->rows['customer_assignment'][] = ['customer_id' => $i + 1, 'sales_rep_id' => $rep];
        }
        $this->rows['discount_policy'][] = [
            'code' => 'DEMO-RABAT',
            'version' => '2026.1',
            'valid_from' => '2026-01-01',
            'tiers' => json_encode([
                ['from' => '0.00', 'rate' => '0.00'],
                ['from' => '10000.00', 'rate' => '0.03'],
                ['from' => '50000.00', 'rate' => '0.05'],
                ['from' => '100000.00', 'rate' => '0.07'],
            ], \JSON_THROW_ON_ERROR),
            'fictional' => true,
        ];

        $this->buildKnownAnswers($r);
        $this->buildRandomHistory($r, $random);

        $this->rows['seed_meta'][] = [
            'id' => true,
            'data_version' => self::DATA_VERSION,
            'random_seed' => self::RANDOM_SEED,
            'reference_date' => $r->format('Y-m-d'),
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
        ];

        return $this->rows;
    }

    /**
     * Replaces all business data in one transaction. Callers must check that seeding is allowed.
     */
    public function write(Connection $connection, \DateTimeImmutable $reference): void
    {
        $rows = $this->build($reference);
        $connection->transactional(static function (Connection $c) use ($rows): void {
            $c->executeStatement('TRUNCATE '.implode(', ', self::TABLES));
            foreach (array_reverse(self::TABLES) as $table) {
                foreach (array_chunk($rows[$table], 500) as $chunk) {
                    $columns = array_keys($chunk[0]);
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as $row) {
                        $placeholders[] = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
                        foreach ($columns as $column) {
                            $value = $row[$column];
                            $params[] = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
                        }
                    }
                    $c->executeStatement(\sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', $placeholders)), $params);
                }
            }
        });
    }

    /**
     * Content checksum over all business tables (seed_meta.generated_at excluded), for reproducibility tests.
     */
    public static function checksum(Connection $connection): string
    {
        $parts = [];
        foreach (self::TABLES as $table) {
            $expression = 'seed_meta' === $table
                ? "concat_ws('|', data_version, random_seed, reference_date)"
                : 't::text';
            $parts[] = \App\Db\Value::string($connection->fetchOne(\sprintf('SELECT md5(coalesce(string_agg(%s, %s ORDER BY t::text), %s)) FROM %s t', $expression, "'\n'", "''", $table)));
        }

        return md5(implode(':', $parts));
    }

    private function buildParts(Randomizer $random): void
    {
        $id = 0;
        foreach (self::MANUFACTURERS as [$manufacturerId, $code]) {
            $count = 6 === $manufacturerId ? 10 : 38;
            for ($i = 1; $i <= $count; ++$i) {
                ++$id;
                $category = self::CATEGORIES[($i + $manufacturerId) % \count(self::CATEGORIES)];
                $list = $random->getInt(1500, 98000); // grosze
                $this->listPrice[$id] = $list;
                $this->rows['part'][] = [
                    'id' => $id,
                    'manufacturer_id' => $manufacturerId,
                    'supplier_id' => 1 + $id % 3,
                    'part_number' => \sprintf('%s-%05d', substr($code, 0, 2), 1000 + $i * 37),
                    'name' => $this->partName($category, $i),
                    'category' => $category,
                    'unit' => 'szt.',
                    'list_price_net' => self::money($list),
                    'purchase_price_net' => self::money(intdiv($list * $random->getInt(55, 80), 100)),
                    'active' => 6 !== $manufacturerId && 0 !== $i % 17,
                ];
            }
        }
        // Text fields starting with formula characters (CSV injection tests, RPT-OUT-4).
        $this->renameFirst('Filtry', '-20% Wkład filtra powietrza (wyprzedaż)');
        $this->renameFirst('Hamulce', '@Serwis zestaw klocków hamulcowych');
    }

    private function renameFirst(string $category, string $name): void
    {
        foreach ($this->rows['part'] as $i => $part) {
            if ($part['category'] === $category) {
                $this->rows['part'][$i]['name'] = $name;

                return;
            }
        }
    }

    private function partName(string $category, int $i): string
    {
        $names = [
            'Hamulce' => ['Klocki hamulcowe przód', 'Tarcza hamulcowa', 'Szczęki hamulcowe', 'Przewód hamulcowy'],
            'Filtry' => ['Filtr oleju', 'Filtr powietrza', 'Filtr kabinowy', 'Filtr paliwa'],
            'Zapłon' => ['Świeca zapłonowa', 'Cewka zapłonowa', 'Przewody zapłonowe', 'Świeca żarowa'],
            'Oświetlenie' => ['Żarówka H7', 'Reflektor przedni', 'Lampa tylna', 'Żarówka LED W5W'],
            'Zawieszenie' => ['Amortyzator przedni', 'Wahacz dolny', 'Łącznik stabilizatora', 'Sprężyna zawieszenia'],
            'Wycieraczki' => ['Pióro wycieraczki 600 mm', 'Pióro wycieraczki 450 mm', 'Silniczek wycieraczek', 'Ramię wycieraczki'],
            'Sprzęgła' => ['Zestaw sprzęgła', 'Łożysko oporowe', 'Docisk sprzęgła', 'Tarcza sprzęgła'],
            'Łożyska' => ['Łożysko koła przód', 'Łożysko koła tył', 'Piasta koła', 'Zestaw łożysk'],
        ];

        return $names[$category][$i % 4];
    }

    /**
     * Hand-placed documents for PH-01's customers. P = last completed month before R, P2 = the month before P,
     * C = R's own month. Expected totals are documented in the business README.
     */
    private function buildKnownAnswers(\DateTimeImmutable $r): void
    {
        $c = $r->modify('first day of this month');
        $p = $c->modify('-1 month');
        $p2 = $c->modify('-2 months');
        $day = static fn (\DateTimeImmutable $month, int $d): \DateTimeImmutable => $month->modify('+'.($d - 1).' days');
        $lastOf = static fn (\DateTimeImmutable $month): \DateTimeImmutable => $month->modify('last day of this month');

        // K-001 Alfa Motors: two lines in P (754,10); one completed day (yesterday) and one today in C.
        $this->invoice(1, $day($p, 2), [[2, 4, 51960], [41, 10, 23450]]);
        if ((int) $r->format('j') > 1) { // on the 1st, yesterday belongs to P and would change its known total
            $this->invoice(1, $r->modify('-1 day'), [[3, 1, 10000]]);
        }
        $this->invoice(1, $r, [[3, 1, 7700]]);
        // K-002 Alfa-Tech: invoice 894,00 in P, adjustment −149,00 issued in P (net 745,00 in P);
        // invoice with sale date on the last day of P2 issued on the 1st of P (counts in P2: 410,00);
        // adjustment −50,00 to the P invoice issued in C (counts in C, not in P).
        $alfaTech = $this->invoice(2, $day($p, 14), [[80, 6, 89400]]);
        $this->adjustment(2, $day($p, 23), $alfaTech, [[80, -1, -14900]]);
        $this->invoice(2, $lastOf($p2), [[81, 2, 41000]], issueDate: $p);
        $this->adjustment(2, $r, $alfaTech, [[80, 0, -5000]]);
        // K-003 +48 Auto Części: 319,20 in P (name starts with a formula character).
        $this->invoice(3, $day($p, 7), [[3, 8, 31920]]);
        // K-004 Moto-Lux: P total exactly 50 000,10 (5 % tier → 2 500,01 half up); P2 total 9 999,99 (0 % tier);
        // a future invoice that must never appear.
        $this->invoice(4, $day($p, 3), [[120, 50, 3000000]]);
        $this->invoice(4, $day($p, 28), [[121, 20, 2000010]]);
        $this->invoice(4, $day($p2, 10), [[122, 3, 999999]]);
        $this->invoice(4, $r->modify('+10 days'), [[120, 5, 500000]]);
        // K-005 Serwis Bielany: 1 289,00 in P; a canceled invoice 500,00 in P (excluded); a future invoice.
        $this->invoice(5, $day($p, 21), [[160, 1, 128900]]);
        $this->invoice(5, $day($p, 22), [[161, 1, 50000]], status: 'canceled');
        $this->invoice(5, $r->modify('+5 days'), [[160, 1, 128900]]);
        // K-006 Warsztat Pod Lipami: no sales at all.
        // K-007 Auto-Kombi Radom (inactive): one invoice six months before C.
        $this->invoice(7, $c->modify('-6 months')->modify('+4 days'), [[42, 3, 120000]]);
    }

    /**
     * Random history from R − 13 months to R + 2 months: other representatives' customers every month,
     * PH-01's customers only before P2 (so known-answer months stay exact). About 5 % of invoices are canceled
     * and about 8 % get an adjustment a few days later.
     */
    private function buildRandomHistory(\DateTimeImmutable $r, Randomizer $random): void
    {
        $c = $r->modify('first day of this month');
        $knownFrom = $c->modify('-2 months');
        $partCount = \count($this->rows['part']);
        for ($m = -13; $m <= 2; ++$m) {
            $month = $c->modify(\sprintf('%+d months', $m));
            $days = (int) $month->format('t');
            foreach (self::CUSTOMERS as $i => [, , , $active, $rep]) {
                $customerId = $i + 1;
                if (6 === $customerId || 7 === $customerId) { // no sales / one known invoice only
                    continue;
                }
                if (1 === $rep && $month >= $knownFrom) {
                    continue;
                }
                if (!$active && $m > -8) {
                    continue;
                }
                $documents = $random->getInt(1, 4);
                for ($d = 0; $d < $documents; ++$d) {
                    $date = $month->modify('+'.$random->getInt(0, $days - 1).' days');
                    $lines = [];
                    for ($l = $random->getInt(1, 4); $l > 0; --$l) {
                        $partIndex = $random->getInt(0, $partCount - 1);
                        $quantity = $random->getInt(1, 12);
                        $lines[] = [$partIndex + 1, $quantity, $this->listPrice[$partIndex + 1] * $quantity];
                    }
                    $status = 0 === $random->getInt(0, 19) ? 'canceled' : 'issued';
                    $invoice = $this->invoice($customerId, $date, $lines, status: $status);
                    if ('issued' === $status && 0 === $random->getInt(0, 12)) {
                        [$part, , $value] = $lines[0];
                        $this->adjustment($customerId, $date->modify('+'.$random->getInt(1, 9).' days'), $invoice, [[$part, -1, -intdiv($value, 4)]]);
                    }
                }
            }
        }
    }

    /**
     * @param list<array{int, int, int}> $lines [part id, quantity, net value in grosze]
     */
    private function invoice(int $customerId, \DateTimeImmutable $saleDate, array $lines, string $status = 'issued', ?\DateTimeImmutable $issueDate = null): int
    {
        return $this->document('invoice', $customerId, $saleDate, $issueDate ?? $saleDate, null, $lines, $status);
    }

    /**
     * @param list<array{int, int, int}> $lines
     */
    private function adjustment(int $customerId, \DateTimeImmutable $issueDate, int $correctsId, array $lines): int
    {
        return $this->document('adjustment', $customerId, null, $issueDate, $correctsId, $lines, 'issued');
    }

    /**
     * @param list<array{int, int, int}> $lines
     */
    private function document(string $kind, int $customerId, ?\DateTimeImmutable $saleDate, \DateTimeImmutable $issueDate, ?int $correctsId, array $lines, string $status): int
    {
        $id = ++$this->documentId;
        $prefix = 'invoice' === $kind ? 'FV' : 'KOR';
        $key = $prefix.$issueDate->format('Y/m');
        $this->numberSeq[$key] = ($this->numberSeq[$key] ?? 0) + 1;
        $this->rows['sales_document'][] = [
            'id' => $id,
            'number' => \sprintf('%s/%s/%04d', $prefix, $issueDate->format('Y/m'), $this->numberSeq[$key]),
            'kind' => $kind,
            'status' => $status,
            'customer_id' => $customerId,
            'sale_date' => $saleDate?->format('Y-m-d'),
            'issue_date' => $issueDate->format('Y-m-d'),
            'corrects_document_id' => $correctsId,
        ];
        foreach ($lines as $n => [$partId, $quantity, $value]) {
            $this->rows['sales_line'][] = [
                'id' => ++$this->lineId,
                'document_id' => $id,
                'line_no' => $n + 1,
                'part_id' => $partId,
                'quantity' => (string) $quantity,
                'net_value' => self::money($value),
            ];
        }

        return $id;
    }

    /** Integer grosze → decimal string for numeric(14,2); no binary floats for money. */
    public static function money(int $grosze): string
    {
        return \sprintf('%s%d.%02d', $grosze < 0 ? '-' : '', intdiv(abs($grosze), 100), abs($grosze) % 100);
    }
}
