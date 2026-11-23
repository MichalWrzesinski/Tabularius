<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticket;

use App\Module\Identity\Role;
use App\Module\Interpretation\Job\InterpretTicket;
use App\Module\Interpretation\Job\InterpretTicketHandler;
use App\Module\Mail\IncomingMessage;
use App\Module\Mail\Ingestor;
use App\Module\Notification\Job\SendNotification;
use App\Module\Notification\Job\SendNotificationHandler;
use App\Module\Ticket\Job\ExecutePlan;
use App\Module\Ticket\Job\ExecutePlanHandler;
use App\Module\Ticket\TicketService;
use App\Module\Ticket\TicketStore;
use App\Tests\Double\RecordingMailer;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * TASK-014: no failure leaves a ticket or a notification claiming progress, and nothing is resent without a decision.
 * Demo mode, rule interpreter, a recording mailer instead of SMTP.
 */
final class FailureScenarioTest extends IdentityTestCase
{
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account('handlowiec@example.com', rep: 'PH-01', shared: true);
        $this->account('admin@example.com', Role::Admin, rep: null);
        $this->account('rep@example.com', rep: 'PH-02');
        $this->mailer = new RecordingMailer();
    }

    public function testAnExhaustedExecutionGoesToTheAdministratorAndRetryCompletesIt(): void
    {
        $id = $this->mail('Sprzedaż za październik dla moich klientów');
        $this->work(skip: ExecutePlan::class);
        self::assertSame(['queued', null], $this->state($id));

        $this->failed(new ExecutePlan($id, 1), retry: true);
        self::assertSame(['queued', null], $this->state($id), 'a failure that will be retried changes nothing');
        $this->failed(new ExecutePlan($id, 0));
        self::assertSame(['queued', null], $this->state($id), 'a stale version touches nothing');
        $this->failed(new ExecutePlan($id, 1));
        self::assertSame(['needs_intervention', 'processing_failed'], $this->state($id));
        self::assertSame(0, $this->value('SELECT count(*) FROM report_file'));
        self::assertSame(0, $this->value("SELECT count(*) FROM outbox WHERE template = 'report_ready'"), 'never reported as done');

        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/retry'));
        $this->work();
        self::assertSame(['completed', null], $this->state($id));
        $this->failed(new ExecutePlan($id, 1));
        self::assertSame(['completed', null], $this->state($id), 'a late failure never reopens a completed ticket');
    }

    public function testAnExhaustedInterpretationGoesToTheAdministrator(): void
    {
        $id = $this->mail('Sprzedaż za październik dla moich klientów');
        $this->failed(new InterpretTicket($id));
        self::assertSame(['needs_intervention', 'processing_failed'], $this->state($id));
        self::assertStringContainsString('Interpretacja', $this->text('SELECT text FROM ticket_event WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', $id));
    }

    public function testAnExhaustedNotificationIsFailedOrUnknownNeverSending(): void
    {
        $id = $this->mail('Sprzedaż za październik dla moich klientów');
        $this->work(skip: SendNotification::class);
        [$received, $ready] = $this->outbox($id);
        $this->db()->executeStatement("UPDATE outbox SET status = 'sending' WHERE id = ?", [$ready]);

        $this->failed(new SendNotification($received));
        $this->failed(new SendNotification($ready));
        self::assertSame('failed', $this->value('SELECT status FROM outbox WHERE id = ?', $received));
        self::assertSame('unknown', $this->value('SELECT status FROM outbox WHERE id = ?', $ready), 'it may have left: not resent');
        self::assertSame('completed', $this->state($id)[0], 'notification state is separate from the ticket');
    }

    public function testTicketsStalledForADayGoToTheAdministrator(): void
    {
        $stuck = $this->mail('Sprzedaż za październik dla moich klientów');
        $recent = $this->mail('Sprzedaż za wrzesień dla moich klientów');
        $this->work(skip: ExecutePlan::class);
        $this->db()->executeStatement("UPDATE ticket SET status = 'running', updated_at = '2026-11-14 08:00:00+00' WHERE id = ?", [$stuck]);
        $this->db()->executeStatement("UPDATE ticket SET updated_at = '2026-11-15 09:00:00+00' WHERE id = ?", [$recent]);

        self::assertSame(1, self::getContainer()->get(TicketService::class)->expireStalled());
        self::assertSame(['needs_intervention', 'processing_stalled'], $this->state($stuck));
        self::assertSame(['queued', null], $this->state($recent));
        $this->work();
        self::assertSame(['needs_intervention', 'processing_stalled'], $this->state($stuck), 'a late job finds nothing to claim');
        self::assertSame(0, $this->value('SELECT count(*) FROM report_file WHERE ticket_id = ?', $stuck));
    }

    public function testTheAdministratorResolvesAnUnknownSend(): void
    {
        $id = $this->mail('Sprzedaż za październik dla moich klientów');
        $this->mailer = new RecordingMailer(new TransportException('Expected response code "250" but got "451"'));
        $this->work();
        [$received, $ready] = $this->outbox($id);
        self::assertSame('unknown', $this->value('SELECT status FROM outbox WHERE id = ?', $received));
        $uri = static fn (string $n): string => '/api/v1/admin/tickets/'.$id.'/notifications/'.$n.'/resolve';

        self::assertSame(200, $this->login('rep@example.com'));
        self::assertSame(403, $this->api('POST', $uri($received), ['resolution' => 'sent']));
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(422, $this->api('POST', $uri($received), ['resolution' => 'delete']));
        self::assertSame(404, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/notifications/00000000-0000-0000-0000-000000000000/resolve', ['resolution' => 'sent']));

        self::assertSame(200, $this->api('POST', $uri($received), ['resolution' => 'sent']));
        self::assertSame(['accepted', 'resolved_by_admin'], array_values((array) $this->db()->fetchAssociative('SELECT status, error FROM outbox WHERE id = ?', [$received])));
        self::assertSame(409, $this->api('POST', $uri($received), ['resolution' => 'resend']), 'an accepted message is never resent');

        $this->mailer = new RecordingMailer();
        self::assertSame(200, $this->api('POST', $uri($ready), ['resolution' => 'resend']));
        $this->work();
        self::assertCount(1, $this->mailer->sent, 'resent exactly once');
        self::assertSame($this->text('SELECT message_id FROM outbox WHERE id = ?', $ready), trim((string) $this->mailer->sent[0]->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>'), 'same Message-ID');
        self::assertSame('accepted', $this->value('SELECT status FROM outbox WHERE id = ?', $ready));
        self::assertSame(2, $this->value("SELECT count(*) FROM audit_event WHERE action = 'notification.resolve'"));

        $this->db()->executeStatement("UPDATE outbox SET status = 'unknown', body_text = NULL, body_html = NULL, recipient = NULL, purged_at = '2026-11-15 10:00:00+00' WHERE id = ?", [$ready]);
        self::assertSame(409, $this->api('POST', $uri($ready), ['resolution' => 'resend']));
        $this->assertProblemCode('notification_purged');
    }

    public function testABounceMarksOurMessageButAnAutoReplyDoesNot(): void
    {
        $id = $this->mail('Sprzedaż za październik dla moich klientów');
        $this->work();
        [$received, $ready] = $this->outbox($id);
        $ids = fn (string $o): string => $this->text('SELECT message_id FROM outbox WHERE id = ?', $o);
        $tickets = $this->value('SELECT count(*) FROM ticket');

        $this->ingest("From: mailer-daemon@example.com\r\nSubject: Out of office\r\nAuto-Submitted: auto-replied\r\nIn-Reply-To: <{$ids($received)}>\r\nContent-Type: text/plain\r\n\r\nJestem na urlopie.\r\n");
        self::assertSame('accepted', $this->value('SELECT status FROM outbox WHERE id = ?', $received), 'an auto-reply is not a bounce');

        $foreign = bin2hex(random_bytes(16)).'@gitpush.app';
        $this->ingest($this->dsn($foreign));
        self::assertSame(0, $this->value("SELECT count(*) FROM outbox WHERE status = 'bounced'"), 'an id that is not ours changes nothing');

        $this->ingest($this->dsn($ids($ready)));
        self::assertSame('bounced', $this->value('SELECT status FROM outbox WHERE id = ?', $ready));
        self::assertSame('accepted', $this->value('SELECT status FROM outbox WHERE id = ?', $received));
        self::assertStringContainsString('zwrot', $this->text('SELECT text FROM ticket_event WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', $id));
        self::assertSame($tickets, $this->value('SELECT count(*) FROM ticket'), 'a bounce never creates a ticket');
        self::assertSame(2, $this->value('SELECT count(*) FROM outbox WHERE ticket_id = ?', $id), 'and is never answered');
        self::assertSame('completed', $this->state($id)[0]);
    }

    private function dsn(string $returned): string
    {
        return "From: MAILER-DAEMON@example.com\r\nSubject: Undelivered Mail Returned to Sender\r\nMessage-ID: <dsn-".bin2hex(random_bytes(4))."@example.com>\r\n"
            ."Content-Type: multipart/report; report-type=delivery-status; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain\r\n\r\nThe message could not be delivered.\r\n"
            ."--b1\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; example.com\r\n\r\nFinal-Recipient: rfc822; jan@example.com\r\nAction: failed\r\nStatus: 5.1.1\r\n"
            ."--b1\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: tabularius@gitpush.app\r\nTo: jan@example.com\r\nMessage-ID: <$returned>\r\nSubject: Raport gotowy\r\n"
            ."--b1--\r\n";
    }

    private function failed(object $job, bool $retry = false): void
    {
        // Messenger's own retry listener decides: a first failure is retried, the 4th attempt (3 retries) is exhausted.
        $envelope = new Envelope($job, $retry ? [] : [new RedeliveryStamp(3)]);
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('database gone')));
    }

    private function mail(string $subject): string
    {
        $this->ingest("From: jan@example.com\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMessage-ID: <n-".bin2hex(random_bytes(4))."@example.com>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n");
        $id = $this->db()->fetchOne('SELECT ticket_id FROM mail_message ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function ingest(string $raw): string
    {
        return self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage('local', null, null, $raw, \strlen($raw), new \DateTimeImmutable('2026-11-15 09:00:00+00:00')));
    }

    /** @param class-string|null $skip jobs of this class are dropped (a lost or failing job) */
    private function work(?string $skip = null): void
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $c = self::getContainer();
        $sender = new SendNotificationHandler($c->get(Connection::class), $c->get(TicketStore::class), $this->mailer, new MockClock(), new NullLogger(), 'tabularius@gitpush.app');
        while ([] !== $batch = [...$transport->get()]) {
            foreach ($batch as $envelope) {
                $message = $envelope->getMessage();
                if (null === $skip || !$message instanceof $skip) {
                    match (true) {
                        $message instanceof InterpretTicket => $c->get(InterpretTicketHandler::class)($message),
                        $message instanceof ExecutePlan => $c->get(ExecutePlanHandler::class)($message),
                        $message instanceof SendNotification => $sender($message),
                        default => null,
                    };
                }
                $transport->ack($envelope);
            }
        }
    }

    /** @return list<string> outbox ids of the ticket, oldest first */
    private function outbox(string $ticketId): array
    {
        /** @var list<string> */
        return $this->db()->fetchFirstColumn('SELECT id FROM outbox WHERE ticket_id = ? ORDER BY created_at, id', [$ticketId]);
    }

    /** @return array{0: mixed, 1: mixed} */
    private function state(string $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT status, reason FROM ticket WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return [$row['status'], $row['reason']];
    }

    private function value(string $sql, ?string $param = null): mixed
    {
        return $this->db()->fetchOne($sql, null === $param ? [] : [$param]);
    }

    private function text(string $sql, ?string $param = null): string
    {
        $value = $this->value($sql, $param);
        self::assertIsString($value);

        return $value;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
