<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mail;

use App\Module\Audit\AuditLog;
use App\Module\Identity\Account;
use App\Module\Identity\Role;
use App\Module\Mail\IncomingMessage;
use App\Module\Mail\Ingestor;
use App\Module\Mail\MailStore;
use App\Module\Mail\MessageIdentity;
use App\Module\Mail\MessageParser;
use App\Module\Report\Execution\ReportEngine;
use App\Module\Report\Profile\IntegrationProfile;
use App\Module\Ticket\Job\ExecutePlan;
use App\Module\Ticket\Job\ExecutePlanHandler;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStore;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * TASK-007: identity of a message (ID-1 to ID-5, ID-DEMO-1 to ID-DEMO-3) and the portal confirmation (CP-003:
 * spoofed sender). Production-mode services are built explicitly; the test kernel runs in demo mode.
 */
final class MessageIdentityTest extends IdentityTestCase
{
    private const string AT = '2026-11-15 09:00:00+00:00';
    private const array OCTOBER = ['period' => ['type' => 'month', 'month' => 10]];

    private Account $admin;
    private Account $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->account('admin@example.com', Role::Admin);
        $this->anna = $this->account('anna@example.com', rep: 'PH-01');
    }

    public function testProductionAttributesOnlyToAnActiveRepresentativeAddress(): void
    {
        $this->account('blocked@example.com', rep: 'PH-03')->block();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->account('handlowiec@example.com', rep: 'PH-04', shared: true);

        $cases = [
            'Anna@Example.COM' => [true, 'received', null],
            'stranger@example.com' => [false, 'needs_intervention', 'identity_unverified'],
            'blocked@example.com' => [false, 'needs_intervention', 'identity_unverified'],
            'handlowiec@example.com' => [false, 'needs_intervention', 'identity_unverified'],
            'admin@example.com' => [false, 'needs_intervention', 'identity_unverified'],
        ];
        foreach ($cases as $from => [$owned, $status, $reason]) {
            $id = $this->ingestProduction($from);
            $row = $this->db()->fetchAssociative('SELECT owner_account_id, status, reason, participant_alias FROM ticket WHERE id = ?', [$id]);
            self::assertIsArray($row);
            self::assertSame([$owned ? $this->anna->id()->toRfc4122() : null, $status, $reason, null], [$row['owner_account_id'], $row['status'], $row['reason'], $row['participant_alias']], $from);
        }
        self::assertSame(
            ['Nadawca dopasowany do konta pracownika (tylko kandydat); wymagane potwierdzenie po zalogowaniu'],
            $this->db()->fetchFirstColumn("SELECT text FROM ticket_event WHERE actor_name = 'Tożsamość' AND text LIKE 'Nadawca dopasowany%'"),
        );
    }

    public function testSpoofedSenderRunsNothingUntilTheRealOwnerConfirms(): void
    {
        // Someone sends as anna@example.com; the ticket is attributed to Anna only as a candidate.
        $id = $this->ingestProduction('anna@example.com');
        $this->productionTickets()->setPlan($this->admin, $id, 'sales_report', self::OCTOBER);
        self::assertSame('awaiting_confirmation', $this->ticketStatus($id));
        self::assertSame(0, $this->queued(), 'nothing queued before confirmation');

        $this->account('other@example.com', rep: 'PH-02');
        self::assertSame(200, $this->login('other@example.com'));
        self::assertSame(404, $this->api('GET', '/api/v1/me/tickets/'.$id), 'another representative sees nothing');
        self::assertSame(404, $this->api('POST', '/api/v1/me/tickets/'.$id.'/confirm'));

        self::assertSame(200, $this->login('anna@example.com'));
        self::assertSame(200, $this->api('GET', '/api/v1/me/tickets/'.$id));
        self::assertSame('awaiting_confirmation', $this->body()['status'] ?? null);
        $plans = $this->body()['plans'] ?? null;
        self::assertIsArray($plans);
        self::assertIsArray($plans[0] ?? null);
        self::assertSame([['label' => 'Okres', 'value' => '01–31.10.2026'], ['label' => 'Zakres', 'value' => 'Przypisani klienci']], $plans[0]['params'] ?? null);
        self::assertArrayNotHasKey('employeeEmail', $this->body());
        self::assertSame(200, $this->api('POST', '/api/v1/me/tickets/'.$id.'/confirm'));
        self::assertSame('queued', $this->body()['status'] ?? null);
        self::assertSame(1, $this->queued());
        self::assertSame(409, $this->api('POST', '/api/v1/me/tickets/'.$id.'/confirm'), 'confirmed once');

        self::getContainer()->get(ExecutePlanHandler::class)(new ExecutePlan($id, 1));
        self::assertSame('completed', $this->ticketStatus($id));
        self::assertSame([$this->anna->id()->toRfc4122()], $this->db()->fetchFirstColumn('SELECT owner_account_id FROM report_file'));
        self::assertSame(['ticket.plan', 'ticket.confirm'], $this->db()->fetchFirstColumn("SELECT action FROM audit_event WHERE action LIKE 'ticket.%' ORDER BY id"));
    }

    public function testOwnerCancelsAndUnconfirmedRequestsExpire(): void
    {
        $cancelled = $this->ingestProduction('anna@example.com');
        $expiring = $this->ingestProduction('anna@example.com');
        foreach ([$cancelled, $expiring] as $id) {
            $this->productionTickets()->setPlan($this->admin, $id, 'parts_catalog', ['manufacturer' => 'BOSCH']);
        }

        self::assertSame(200, $this->login('anna@example.com'));
        self::assertSame(200, $this->api('POST', '/api/v1/me/tickets/'.$cancelled.'/cancel'));
        self::assertSame(['rejected', 'cancelled_by_owner'], [$this->body()['status'] ?? null, $this->body()['reason'] ?? null]);
        self::assertSame(409, $this->api('POST', '/api/v1/me/tickets/'.$cancelled.'/confirm'));

        $this->db()->executeStatement("UPDATE ticket SET updated_at = '2026-11-07 09:00:00+00' WHERE id = ?", [$expiring]);
        self::assertSame(1, self::getContainer()->get(TicketService::class)->expireUnconfirmed());
        self::assertSame(['rejected', 'confirmation_expired'], array_values((array) $this->db()->fetchAssociative('SELECT status, reason FROM ticket WHERE id = ?', [$expiring])));
        self::assertSame(0, $this->queued());
        self::assertSame(404, $this->api('GET', '/api/v1/me/tickets/not-a-ticket'));
    }

    public function testDemoAdminPlanForAMailTicketIsQueuedWithoutConfirmation(): void
    {
        $this->account('handlowiec@example.com', rep: 'PH-05', shared: true);
        $demo = self::getContainer()->get(Ingestor::class);
        $demo->ingest(new IncomingMessage('local', null, null, "From: guest@example.com\r\nMessage-ID: <d1@example.com>\r\n\r\nSprzedaż", 40, new \DateTimeImmutable(self::AT)));
        $id = $this->db()->fetchOne('SELECT id FROM ticket');
        self::assertIsString($id);

        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(422, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/plan', ['operation' => 'sales_report', 'parameters' => ['period' => ['type' => 'month', 'month' => 13]]]));
        self::assertSame(400, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/plan', ['operation' => 'parts_catalog']));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/plan', ['operation' => 'parts_catalog', 'parameters' => ['manufacturer' => 'BOSCH']]));
        self::assertSame(['queued', 1], [$this->body()['status'] ?? null, $this->body()['planVersion'] ?? null]);
        self::assertSame(1, $this->queued());
        self::assertSame(409, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/plan', ['operation' => 'parts_catalog', 'parameters' => ['manufacturer' => 'BOSCH']]), 'not while queued');
    }

    public function testUnverifiedTicketCannotGetAPlanOnlyBeRejected(): void
    {
        $id = $this->ingestProduction('stranger@example.com');
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(409, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/plan', ['operation' => 'parts_catalog', 'parameters' => ['manufacturer' => 'BOSCH']]));
        $this->assertProblemCode('no_owner');
        self::assertSame(404, $this->api('POST', '/api/v1/admin/tickets/00000000-0000-7000-8000-000000000000/plan', ['operation' => 'parts_catalog', 'parameters' => []]));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/reject'));
    }

    public function testNoEndpointOfTheSharedDemoAccountsRevealsMessageContents(): void
    {
        $this->account('handlowiec@example.com', rep: 'PH-05', shared: true);
        $this->account('demo-admin@example.com', Role::Admin, shared: true);
        self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage('local', null, null, "From: Sekretna Osoba <sekret.nadawca@firma-x.pl>\r\nSubject: Temat-ZNACZNIK\r\nMessage-ID: <x1@firma-x.pl>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nTresc-ZNACZNIK sprzedaż", 80, new \DateTimeImmutable(self::AT)));
        $id = $this->db()->fetchOne('SELECT id FROM ticket');
        self::assertIsString($id);

        $seen = [];
        foreach (['demo-admin@example.com' => ['/api/v1/admin/tickets', "/api/v1/admin/tickets/$id", '/api/v1/admin/files', '/api/v1/admin/audit', '/api/v1/admin/accounts', '/api/v1/auth/me'],
            'handlowiec@example.com' => ['/api/v1/me/tickets', "/api/v1/me/tickets/$id", '/api/v1/me/files', '/api/v1/auth/me']] as $user => $paths) {
            self::assertSame(200, $this->login($user));
            foreach ($paths as $path) {
                self::assertSame(200, $this->api('GET', $path), $path);
                $seen[$path] = (string) $this->client->getResponse()->getContent();
            }
        }
        foreach ($seen as $path => $json) {
            foreach (['sekret', 'firma-x', 'Sekretna', 'ZNACZNIK'] as $marker) {
                self::assertStringNotContainsString($marker, $json, "$path reveals $marker (ID-DEMO-3)");
            }
        }
        self::assertStringContainsString('Uczestnik #', $seen["/api/v1/admin/tickets/$id"]);
    }

    private function ingestProduction(string $from): string
    {
        $c = self::getContainer();
        $ingestor = new Ingestor($c->get(MailStore::class), $c->get(TicketStore::class), new MessageParser(), new MessageIdentity($c->get(Connection::class), 'production'), $c->get(MessageBusInterface::class), new MockClock(self::AT), new NullLogger(), 'secret', 'production');
        $n = $this->db()->fetchOne('SELECT count(*) FROM mail_message');
        self::assertIsNumeric($n);
        $ingestor->ingest(new IncomingMessage('local', null, null, "From: $from\r\nMessage-ID: <p{$n}@example.com>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nRaport", 60, new \DateTimeImmutable(self::AT)));
        $id = $this->db()->fetchOne('SELECT ticket_id FROM mail_message ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function productionTickets(): TicketService
    {
        $c = self::getContainer();

        return new TicketService($c->get(TicketStore::class), $c->get(EntityManagerInterface::class), $c->get(ReportEngine::class), $c->get(IntegrationProfile::class), $c->get(MessageBusInterface::class), $c->get(AuditLog::class), new MockClock(self::AT), 'production');
    }

    private function ticketStatus(string $id): mixed
    {
        return $this->db()->fetchOne('SELECT status FROM ticket WHERE id = ?', [$id]);
    }

    private function queued(): int
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        // Execution jobs only (interpretation jobs are queued for every received mail ticket). get() returns one
        // message at a time and marks it delivered, so this drains the queue.
        $count = 0;
        while ([] !== $batch = [...$transport->get()]) {
            foreach ($batch as $envelope) {
                $count += $envelope->getMessage() instanceof ExecutePlan ? 1 : 0;
            }
        }

        return $count;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
