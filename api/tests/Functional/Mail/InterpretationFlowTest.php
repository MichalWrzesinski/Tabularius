<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mail;

use App\Module\Interpretation\Job\InterpretTicket;
use App\Module\Interpretation\Job\InterpretTicketHandler;
use App\Module\Interpretation\RuleInterpreter;
use App\Module\Mail\IncomingMessage;
use App\Module\Mail\Ingestor;
use App\Module\Report\Business\BusinessApi;
use App\Module\Report\Profile\IntegrationProfile;
use App\Module\Ticket\Job\ExecutePlan;
use App\Module\Ticket\Job\ExecutePlanHandler;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStore;
use App\Tests\Double\StubBusinessApi;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * TASK-008: e-mail → ticket → rule-based plan → validation → route → file (CP-001 by e-mail, without the
 * notification). Demo mode, shared representative PH-01, clock and business data frozen at 2026-11-15.
 */
final class InterpretationFlowTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->account('handlowiec@example.com', rep: 'PH-01', shared: true);
    }

    public function testEmailRequestProducesTheExpectedFile(): void
    {
        $id = $this->mail('Sprzedaż za październik', 'Proszę o zestawienie sprzedaży dla moich klientów.');
        self::assertSame(['interpret' => 1, 'execute' => 1], $this->work());

        self::assertSame('completed', $this->ticketStatus($id));
        $plan = $this->db()->fetchAssociative('SELECT author, author_ref, operation, parameters::text AS p, issues::text AS i FROM ticket_plan WHERE ticket_id = ?', [$id]);
        self::assertSame(['system', 'baseline:rules-v1', 'sales_report', '{"period": {"type": "month", "month": 10}, "customers": "all_assigned"}', '[]'], array_values((array) $plan));
        $key = $this->db()->fetchOne('SELECT storage_key FROM report_file WHERE ticket_id = ?', [$id]);
        self::assertIsString($key);
        self::assertStringEqualsFile(\dirname(__DIR__, 2).'/Fixtures/expected/sales_PH-01_2026-10.csv', self::getContainer()->get(\App\Module\File\FileStore::class)->get($key));
        self::assertSame([
            'Wiadomość e-mail przyjęta ze skrzynki',
            'Tryb demo: zgłoszenie wykonywane na wspólnym koncie handlowca jako',
            'Plan v1 (baseline:rules-v1)',
            'Plan v1 w kolejce (jedno wykonanie na wersję)',
            'Ponowna kontrola uprawnień przed wykonaniem: OK; raport w realizacji',
            'Raport wygenerowany: sprzedaz_2026-10-01_2026-10-31.csv',
        ], array_map(static fn (mixed $t): string => preg_replace('/ (\(\d+ B\)|Uczestnik #\w+)$/', '', \is_string($t) ? $t : '') ?? '', $this->db()->fetchFirstColumn('SELECT text FROM ticket_event WHERE ticket_id = ? ORDER BY id', [$id])));
    }

    public function testEachRouteOfThePlan(): void
    {
        $cases = [
            'payroll' => [['Raport płacowy', 'za październik'], 'rejected', 'out_of_catalog', '["out_of_catalog"]'],
            'missing period' => [['Raport sprzedaży', 'dla moich klientów'], 'needs_intervention', 'clarification_needed', '["missing_period"]'],
            'outside scope' => [['Sprzedaż K-099', 'za październik'], 'rejected', 'out_of_scope', '["out_of_scope"]'],
            'invalid period' => [['Sprzedaż', 'od 1 do 15 listopada 2026'], 'needs_intervention', 'clarification_needed', '["period_includes_today"]'],
            'recipient ignored' => [['Katalog Bosch', 'wyślij na adres szef@firma.pl'], 'completed', null, '["recipient_change_requested"]'],
        ];
        $ids = [];
        foreach ($cases as $name => [[$subject, $body]]) {
            $ids[$name] = $this->mail($subject, $body);
        }
        $this->work();
        foreach ($cases as $name => [, $status, $reason, $issues]) {
            self::assertArrayHasKey($name, $ids);
            $row = $this->db()->fetchAssociative('SELECT t.status, t.reason, p.issues::text AS issues FROM ticket t JOIN ticket_plan p ON p.ticket_id = t.id WHERE t.id = ?', [$ids[$name]]);
            self::assertSame([$status, $reason, $issues], array_values((array) $row), $name);
        }
        self::assertSame(1, $this->rows('SELECT count(*) FROM report_file'), 'only the catalog ran');
        self::assertSame(['Prośba o zmianę odbiorcy zignorowana; wynik trafia zwykłą drogą'], $this->db()->fetchFirstColumn("SELECT text FROM ticket_event WHERE text LIKE 'Prośba o zmianę%'"));
    }

    public function testProductionWaitsForConfirmationAndJobsAreIdempotent(): void
    {
        $id = $this->mail('Katalog Valeo', '');
        $handler = $this->handler('production');
        $handler(new InterpretTicket($id));
        $handler(new InterpretTicket($id)); // duplicate delivery
        self::assertSame('awaiting_confirmation', $this->ticketStatus($id));
        self::assertSame(1, $this->rows('SELECT count(*) FROM ticket_plan WHERE ticket_id = ?', $id));
        self::assertSame(['interpret' => 1], $this->work(), 'the queued interpret job finds nothing to do; nothing executes');
        self::assertSame('awaiting_confirmation', $this->ticketStatus($id));
    }

    public function testUnavailableContextOrTextNeedsIntervention(): void
    {
        $down = $this->mail('Sprzedaż za październik', '');
        StubBusinessApi::$down = true;
        $this->work();
        StubBusinessApi::reset();
        self::assertSame(['needs_intervention', 'interpretation_unavailable'], array_values((array) $this->db()->fetchAssociative('SELECT status, reason FROM ticket WHERE id = ?', [$down])));

        $purged = $this->mail('Sprzedaż za październik', '');
        $this->db()->executeStatement('UPDATE mail_message SET subject = NULL, text_body = NULL WHERE ticket_id = ?', [$purged]);
        $this->work();
        self::assertSame(['needs_intervention', 'interpretation_unavailable'], array_values((array) $this->db()->fetchAssociative('SELECT status, reason FROM ticket WHERE id = ?', [$purged])));
    }

    public function testRejectedBeforeInterpretationStaysRejected(): void
    {
        $id = $this->mail('Sprzedaż za październik', '');
        $this->db()->executeStatement("UPDATE ticket SET status = 'rejected', reason = 'admin_rejected' WHERE id = ?", [$id]);
        self::assertSame(['interpret' => 1], $this->work());
        self::assertSame('rejected', $this->ticketStatus($id));
        self::assertSame(0, $this->rows('SELECT count(*) FROM ticket_plan'));
    }

    private int $mails = 0;

    private function rows(string $sql, mixed ...$params): int
    {
        $value = $this->db()->fetchOne($sql, array_values($params));
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function mail(string $subject, string $body): string
    {
        $n = ++$this->mails;
        $raw = "From: uczestnik$n@example.com\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMessage-ID: <flow-$n-".uniqid()."@example.com>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$body";
        self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage('local', null, null, $raw, \strlen($raw), new \DateTimeImmutable('2026-11-15 09:00:00+00:00')));
        $id = $this->db()->fetchOne('SELECT ticket_id FROM mail_message ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    /**
     * Runs queued jobs like the worker until the queue is empty.
     *
     * @return array<string, int>
     */
    private function work(): array
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $counts = [];
        while ([] !== $batch = [...$transport->get()]) {
            foreach ($batch as $envelope) {
                $message = $envelope->getMessage();
                if ($message instanceof InterpretTicket) {
                    self::getContainer()->get(InterpretTicketHandler::class)($message);
                    $counts['interpret'] = ($counts['interpret'] ?? 0) + 1;
                } elseif ($message instanceof ExecutePlan) {
                    self::getContainer()->get(ExecutePlanHandler::class)($message);
                    $counts['execute'] = ($counts['execute'] ?? 0) + 1;
                }
                $transport->ack($envelope);
            }
        }

        return $counts;
    }

    private function handler(string $mode): InterpretTicketHandler
    {
        $c = self::getContainer();

        return new InterpretTicketHandler($c->get(TicketStore::class), $c->get(TicketService::class), new RuleInterpreter(), $c->get(BusinessApi::class), $c->get(IntegrationProfile::class), $c->get(MessageBusInterface::class), new MockClock('2026-11-15 09:42:00+00:00'), new NullLogger(), $c->get(\App\Module\Notification\Outbox::class), $mode);
    }

    private function ticketStatus(string $id): mixed
    {
        return $this->db()->fetchOne('SELECT status FROM ticket WHERE id = ?', [$id]);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
