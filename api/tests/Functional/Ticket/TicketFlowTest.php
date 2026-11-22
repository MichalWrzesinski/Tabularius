<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticket;

use App\Module\Identity\Account;
use App\Module\Identity\Role;
use App\Module\Report\Business\Customer;
use App\Module\Report\Business\ReportTooLargeException;
use App\Module\Ticket\Job\ExecutePlan;
use App\Module\Ticket\Job\ExecutePlanHandler;
use App\Tests\Double\StubBusinessApi;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * CP-001 without mail (manual ticket → worker → download), CP-003 file part, and TKT-VER idempotency.
 * Clock and business data are frozen at 2026-11-15 (config/services.yaml when@test, tests/Fixtures/business).
 */
final class TicketFlowTest extends IdentityTestCase
{
    private Account $admin;
    private Account $rep;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob($this->storageDir().'/*/*.csv') ?: [] as $stale) {
            unlink($stale);
        }
        $this->admin = $this->account('admin@example.com', Role::Admin);
        $this->rep = $this->account('rep@example.com', rep: 'PH-01');
    }

    public function testManualSalesTicketProducesTheExpectedFileForItsOwner(): void
    {
        $id = $this->createSalesTicket();
        self::assertSame('queued', $this->at('status'));
        self::assertSame([['label' => 'Okres', 'value' => '01–31.10.2026'], ['label' => 'Zakres', 'value' => 'Przypisani klienci']], $this->at('plans.0.params'));
        self::assertSame(1, $this->work());

        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        self::assertSame('completed', $this->at('status'));
        $fileId = $this->at('fileId');
        self::assertIsString($fileId);
        self::assertSame([
            'Zgłoszenie utworzone ręcznie w imieniu pracownika',
            'Plan v1 w kolejce (jedno wykonanie na wersję)',
            'Ponowna kontrola uprawnień przed wykonaniem: OK; raport w realizacji',
            'Raport wygenerowany: sprzedaz_2026-10-01_2026-10-31.csv',
        ], array_column($this->rows('events'), 'text'));

        self::assertSame(200, $this->login('rep@example.com'));
        self::assertSame(200, $this->api('GET', '/api/v1/me/files'));
        self::assertCount(1, $this->rows('items'));
        self::assertSame(['01–31.10.2026', 'Przypisani klienci (7)', 8], [$this->at('items.0.periodLabel'), $this->at('items.0.scopeLabel'), $this->at('items.0.rowCount')]);

        self::assertSame(200, $this->api('GET', '/api/v1/files/'.$fileId));
        self::assertSame('typ_wiersza', $this->at('header.0'));
        $rows = $this->rows('rows');
        self::assertSame(['total', ['suma_calkowita', '', '', '', '', '', '', '', '', '', '53107,40']], [end($rows)['type'] ?? null, end($rows)['cells'] ?? null]);

        $this->client->request('GET', '/api/v1/files/'.$fileId.'/content');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringEqualsFile(\dirname(__DIR__, 2).'/Fixtures/expected/sales_PH-01_2026-10.csv', (string) $response->getContent());
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=sprzedaz_2026-10-01_2026-10-31.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(1, $this->rowsIn("SELECT count(*) FROM audit_event WHERE action = 'file.download'"));
    }

    public function testDuplicateAndStaleJobsProduceNoSecondFile(): void
    {
        $id = $this->createSalesTicket();
        $handler = self::getContainer()->get(ExecutePlanHandler::class);
        $handler(new ExecutePlan($id, 1));
        $handler(new ExecutePlan($id, 1)); // duplicate delivery
        $handler(new ExecutePlan($id, 2)); // a version that is not current
        self::assertSame(1, $this->rowsIn('SELECT count(*) FROM report_file WHERE ticket_id = ?', [$id]));
        self::assertSame(2, $this->rowsIn("SELECT count(*) FROM ticket_event WHERE ticket_id = ? AND text LIKE 'Zadanie dla planu%pominięte%'", [$id]));
    }

    public function testConcurrentJobsForTheSameVersionKeepOneFile(): void
    {
        $id = $this->createSalesTicket();
        $handler = self::getContainer()->get(ExecutePlanHandler::class);
        // A second delivery of the same job runs to completion while the first one is executing (after its claim).
        StubBusinessApi::$duringSalesLines = static fn () => $handler(new ExecutePlan($id, 1));
        $handler(new ExecutePlan($id, 1));

        self::assertSame(1, $this->rowsIn('SELECT count(*) FROM report_file WHERE ticket_id = ?', [$id]));
        self::assertSame(1, $this->rowsIn("SELECT count(*) FROM ticket_event WHERE ticket_id = ? AND text LIKE 'Wynik planu v1 odrzucony%'", [$id]));
        $stored = glob($this->storageDir().'/*/*.csv') ?: [];
        self::assertSame($this->db()->fetchFirstColumn('SELECT storage_key FROM report_file'), array_map(static fn (string $f): string => basename($f, '.csv'), $stored), 'the losing job removed its content');
    }

    public function testAnotherRepresentativeGetsNotFound(): void
    {
        $fileId = $this->completedFile();
        $this->account('other@example.com', rep: 'PH-02');
        self::assertSame(200, $this->login('other@example.com'));
        self::assertSame(404, $this->api('GET', '/api/v1/files/'.$fileId));
        self::assertSame(404, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));
        self::assertSame(404, $this->api('GET', '/api/v1/files/00000000-0000-7000-8000-000000000000'));
        self::assertSame(404, $this->api('GET', '/api/v1/files/not-a-uuid'));
        self::assertSame(200, $this->api('GET', '/api/v1/me/files'));
        self::assertSame([], $this->at('items'));
        self::assertSame(403, $this->api('GET', '/api/v1/admin/files'));
    }

    public function testReassignedCustomerBlocksTheDownloadAtTheNextRequest(): void
    {
        $fileId = $this->completedFile();
        self::assertSame(200, $this->login('rep@example.com'));
        self::assertSame(200, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));

        StubBusinessApi::$customers['PH-01'] = [new Customer('K-001', 'Alfa Motors Sp. z o.o.', true)];
        self::assertSame(403, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));
        $this->assertProblemCode('scope_changed');

        StubBusinessApi::reset();
        StubBusinessApi::$down = true;
        self::assertSame(503, $this->api('GET', '/api/v1/files/'.$fileId.'/content'), 'fails closed when the scope cannot be read');
    }

    public function testBlockedAccountCannotDownload(): void
    {
        $fileId = $this->completedFile();
        self::assertSame(200, $this->login('rep@example.com'));
        self::assertSame(200, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));
        $repCookies = $this->client->getCookieJar()->all();

        $this->client->getCookieJar()->clear();
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/accounts/'.$this->rep->id()->toRfc4122().'/block'));

        $this->client->getCookieJar()->clear();
        foreach ($repCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        self::assertSame(401, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));
    }

    public function testExpiredFileIsGoneAndPurged(): void
    {
        $fileId = $this->completedFile();
        $this->db()->executeStatement("UPDATE report_file SET expires_at = '2026-11-15 09:00:00+00' WHERE id = ?", [$fileId]);
        self::assertSame(410, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));
        $this->assertProblemCode('file_expired');

        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel ?? throw new \LogicException())->find('app:files:purge'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Deleted 1 expired file(s).', $tester->getDisplay());
        self::assertNotNull($this->db()->fetchOne('SELECT deleted_at FROM report_file WHERE id = ?', [$fileId]));
        self::assertSame(200, $this->api('GET', '/api/v1/admin/files'));
        self::assertSame([], $this->at('items'));
    }

    public function testBlockedOwnerBeforeExecutionRejectsTheTicket(): void
    {
        $id = $this->createSalesTicket();
        self::assertSame(200, $this->api('POST', '/api/v1/admin/accounts/'.$this->rep->id()->toRfc4122().'/block'));
        self::assertSame(1, $this->work());

        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        self::assertSame(['rejected', 'owner_inactive', null], [$this->at('status'), $this->at('reason'), $this->at('fileId')]);
    }

    public function testDataSourceFailureNeedsInterventionAndRetryCompletes(): void
    {
        $id = $this->createSalesTicket();
        StubBusinessApi::$down = true;
        self::assertSame(1, $this->work());
        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        self::assertSame(['needs_intervention', 'data_source_error'], [$this->at('status'), $this->at('reason')]);

        StubBusinessApi::$down = false;
        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/retry'));
        self::assertSame('queued', $this->at('status'));
        self::assertSame(1, $this->work());
        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        self::assertSame('completed', $this->at('status'));
        self::assertSame(409, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/retry'));
        self::assertSame(409, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/reject'), 'completed is terminal');
    }

    public function testCreationIsValidatedAgainstCatalogScopeAndPeriod(): void
    {
        self::assertSame(200, $this->login('admin@example.com'));
        $owner = $this->rep->id()->toRfc4122();
        $cases = [
            'unknown_operation' => ['payroll', ['period' => ['type' => 'previous_month']]],
            'unexpected_parameter' => ['sales_report', ['period' => ['type' => 'previous_month'], 'sql' => 'DROP TABLE x']],
            'missing_period' => ['sales_report', []],
            'period_includes_today' => ['sales_report', ['period' => ['type' => 'explicit', 'start' => '2026-11-01', 'end' => '2026-11-15']]],
            'out_of_scope' => ['sales_report', ['period' => ['type' => 'previous_month'], 'customers' => ['K-099']]],
            'unknown_manufacturer' => ['parts_catalog', ['manufacturer' => 'NOPE']],
        ];
        foreach ($cases as $code => [$operation, $parameters]) {
            self::assertSame(422, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $owner, 'operation' => $operation, 'parameters' => $parameters]), $code);
            $this->assertProblemCode($code);
        }
        self::assertSame(422, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $this->admin->id()->toRfc4122(), 'operation' => 'sales_report', 'parameters' => ['period' => ['type' => 'previous_month']]]));
        $this->assertProblemCode('invalid_owner');
        self::assertSame(400, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $owner, 'operation' => 'sales_report']));
        self::assertSame(0, $this->rowsIn('SELECT count(*) FROM ticket'));
    }

    public function testRepresentativeCannotUseTheAdminTicketApi(): void
    {
        self::assertSame(200, $this->login('rep@example.com'));
        self::assertSame(403, $this->api('GET', '/api/v1/admin/tickets'));
        self::assertSame(403, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $this->rep->id()->toRfc4122(), 'operation' => 'sales_report', 'parameters' => []]));
        self::assertSame(200, $this->api('GET', '/api/v1/me/tickets'));
    }

    public function testCatalogAndCustomersForTheForm(): void
    {
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(200, $this->api('GET', '/api/v1/admin/catalog'));
        self::assertSame(['sales_report', 'parts_catalog', 'customer_discount_simulation'], array_column($this->rows('operations'), 'id'));
        self::assertContains('Hamulce', (array) $this->at('categories'));
        self::assertSame(200, $this->api('GET', '/api/v1/admin/accounts/'.$this->rep->id()->toRfc4122().'/customers'));
        self::assertCount(7, $this->rows('items'));
        self::assertSame(404, $this->api('GET', '/api/v1/admin/accounts/'.$this->admin->id()->toRfc4122().'/customers'));
    }

    public function testDiscountAndCatalogTicketsComplete(): void
    {
        self::assertSame(200, $this->login('admin@example.com'));
        $owner = $this->rep->id()->toRfc4122();
        self::assertSame(201, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $owner, 'operation' => 'customer_discount_simulation', 'parameters' => ['customer' => 'K-004', 'period' => ['type' => 'month', 'month' => 10]]]));
        self::assertSame(201, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $owner, 'operation' => 'parts_catalog', 'parameters' => ['manufacturer' => 'BOSCH']]));
        self::assertSame(2, $this->work());
        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets'));
        self::assertSame(['completed', 'completed'], array_column($this->rows('items'), 'status'));
        self::assertSame(['["K-004"]', '[]'], $this->db()->fetchFirstColumn('SELECT customer_codes::text FROM report_file f JOIN ticket t ON t.id = f.ticket_id ORDER BY t.number'));
    }

    public function testFailuresDuringExecutionNeedIntervention(): void
    {
        $cases = [
            'out_of_scope' => static fn () => StubBusinessApi::$customers['PH-01'] = [new Customer('K-001', 'Alfa Motors Sp. z o.o.', true)],
            'too_large' => static fn () => StubBusinessApi::$duringSalesLines = static fn () => throw new ReportTooLargeException('limit'),
            'internal_error' => static fn () => StubBusinessApi::$duringSalesLines = static fn () => throw new \RuntimeException('bug'),
        ];
        foreach ($cases as $reason => $arrange) {
            StubBusinessApi::reset();
            $id = $this->createSalesTicket(['period' => ['type' => 'month', 'month' => 10], 'customers' => ['K-004']]);
            $arrange();
            self::assertSame(1, $this->work());
            self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
            self::assertSame(['needs_intervention', $reason], [$this->at('status'), $this->at('reason')], $reason);
        }

        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/reject'));
        self::assertSame(['rejected', 'admin_rejected'], [$this->at('status'), $this->at('reason')]);
        self::assertSame(404, $this->api('POST', '/api/v1/admin/tickets/00000000-0000-7000-8000-000000000000/reject'));
        self::assertSame(404, $this->api('GET', '/api/v1/admin/tickets/nope'));
    }

    public function testPlanThatBecameInvalidNeedsIntervention(): void
    {
        $id = $this->createSalesTicket();
        $this->db()->executeStatement('UPDATE ticket_plan SET parameters = \'{"period": {"type": "month", "month": 13}}\' WHERE ticket_id = ?', [$id]);
        self::assertSame(1, $this->work());
        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        self::assertSame(['needs_intervention', 'invalid_parameters'], [$this->at('status'), $this->at('reason')]);
        self::assertSame('nieprawidłowy okres', $this->at('plans.0.params.0.value'));
    }

    public function testJobForAnUnknownTicketIsDiscarded(): void
    {
        $handler = self::getContainer()->get(ExecutePlanHandler::class);
        $handler(new ExecutePlan('00000000-0000-7000-8000-000000000000', 1));
        $handler(new ExecutePlan('not-a-uuid', 1));
        self::assertSame(0, $this->rowsIn('SELECT count(*) FROM ticket_event'));
    }

    public function testAdministratorSeesAndDownloadsEveryFile(): void
    {
        $fileId = $this->completedFile();
        self::assertSame(200, $this->api('GET', '/api/v1/admin/files'));
        self::assertSame($fileId, $this->at('items.0.id'));
        $number = $this->at('items.0.ticketNumber');
        self::assertIsString($number);
        self::assertMatchesRegularExpression('/^T-\d+$/', $number);
        self::assertSame(200, $this->api('GET', '/api/v1/files/'.$fileId.'/content'));
        StubBusinessApi::$down = true;
        self::assertSame(200, $this->api('GET', '/api/v1/files/'.$fileId), 'the administrator is not limited by a representative scope');
        self::assertSame(503, $this->api('GET', '/api/v1/admin/catalog'));
        self::assertSame(503, $this->api('GET', '/api/v1/admin/accounts/'.$this->rep->id()->toRfc4122().'/customers'));
        self::assertSame(503, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $this->rep->id()->toRfc4122(), 'operation' => 'parts_catalog', 'parameters' => ['manufacturer' => 'BOSCH']]));
        $this->assertProblemCode('data_source_unavailable');
    }

    public function testDiscountPlanIsShownWithResolvedParameters(): void
    {
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(201, $this->api('POST', '/api/v1/admin/tickets', ['ownerId' => $this->rep->id()->toRfc4122(), 'operation' => 'customer_discount_simulation', 'parameters' => ['customer' => 'K-004', 'period' => ['type' => 'previous_month'], 'policy' => '2026.1']]));
        self::assertSame([
            ['label' => 'Klient', 'value' => 'K-004'],
            ['label' => 'Okres', 'value' => '01–31.10.2026'],
            ['label' => 'Polityka', 'value' => 'DEMO-RABAT 2026.1'],
        ], $this->at('plans.0.params'));
        self::assertSame('Aktywność klienta i symulacja rabatu', $this->at('operationLabel'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function createSalesTicket(array $parameters = ['period' => ['type' => 'month', 'month' => 10]]): string
    {
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(201, $this->api('POST', '/api/v1/admin/tickets', [
            'ownerId' => $this->rep->id()->toRfc4122(),
            'operation' => 'sales_report',
            'parameters' => $parameters,
        ]));
        $id = $this->at('id');
        self::assertIsString($id);

        return $id;
    }

    private function completedFile(): string
    {
        $this->createSalesTicket();
        self::assertSame(1, $this->work());
        $id = $this->db()->fetchOne('SELECT id FROM report_file');
        self::assertIsString($id);

        return $id;
    }

    /** Runs the queued jobs like the worker does; returns how many were handled. */
    private function work(): int
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $handler = self::getContainer()->get(ExecutePlanHandler::class);
        $count = 0;
        while ([] !== $batch = [...$transport->get()]) {
            foreach ($batch as $envelope) {
                $message = $envelope->getMessage();
                \assert($message instanceof ExecutePlan);
                $handler($message);
                $transport->ack($envelope);
                ++$count;
            }
        }

        return $count;
    }

    private function storageDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test-reports';
    }

    /** Value at a dot path of the last JSON response (`plans.0.params`); fails when the path does not exist. */
    private function at(string $path): mixed
    {
        $value = $this->body();
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($value, $path);
            self::assertArrayHasKey(ctype_digit($key) ? (int) $key : $key, $value, $path);
            $value = $value[ctype_digit($key) ? (int) $key : $key];
        }

        return $value;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rows(string $path): array
    {
        $rows = $this->at($path);
        self::assertIsArray($rows);
        self::assertTrue(array_is_list($rows));
        foreach ($rows as $row) {
            self::assertIsArray($row);
        }

        /** @var list<array<array-key, mixed>> $rows */
        return $rows;
    }

    /**
     * @param list<string> $params
     */
    private function rowsIn(string $sql, array $params = []): int
    {
        $value = $this->db()->fetchOne($sql, $params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
