<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mail;

use App\Module\Identity\Role;
use App\Module\Interpretation\Job\InterpretTicket;
use App\Module\Interpretation\Job\InterpretTicketHandler;
use App\Module\Mail\IncomingMessage;
use App\Module\Mail\Ingestor;
use App\Module\Notification\Job\SendNotification;
use App\Module\Notification\Job\SendNotificationHandler;
use App\Module\Notification\Outbox;
use App\Module\Notification\Templates;
use App\Module\Ticket\Job\ExecutePlan;
use App\Module\Ticket\Job\ExecutePlanHandler;
use App\Module\Ticket\TicketStore;
use App\Tests\Double\RecordingMailer;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * TASK-009: outbox and notifications (MAIL-3, MAIL-4, ID-DEMO-2). Demo mode; a recording mailer stands in for SMTP.
 */
final class NotificationTest extends IdentityTestCase
{
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account('handlowiec@example.com', rep: 'PH-01', shared: true);
        $this->mailer = new RecordingMailer();
    }

    public function testEmailRequestToReportReadyMailInTheSameThread(): void
    {
        $id = $this->mail('Sprzedaż za październik', 'jan@example.com', '<request-1@example.com>');
        $this->work();

        self::assertSame('completed', $this->db()->fetchOne('SELECT status FROM ticket WHERE id = ?', [$id]));
        self::assertSame(
            [['received', 'capture', 'accepted', 'jan@example.com'], ['report_ready', 'capture', 'accepted', 'jan@example.com']],
            array_map('array_values', $this->db()->fetchAllAssociative('SELECT template, route, status, recipient FROM outbox ORDER BY created_at, template')),
        );
        self::assertCount(2, $this->mailer->sent);
        $ready = $this->mailer->sent[1];
        self::assertSame('Raport gotowy (T-'.$this->number($id).')', $ready->getSubject());
        self::assertSame(['jan@example.com'], array_map(static fn ($a) => $a->getAddress(), $ready->getTo()));
        self::assertSame('<request-1@example.com>', $ready->getHeaders()->get('In-Reply-To')?->getBodyAsString());
        self::assertSame('auto-generated', $ready->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
        $fileId = $this->db()->fetchOne('SELECT id FROM report_file WHERE ticket_id = ?', [$id]);
        self::assertIsString($fileId);
        self::assertStringContainsString('/moje-raporty/'.$fileId, (string) $ready->getTextBody());
        foreach ([(string) $ready->getTextBody(), (string) $ready->getHtmlBody()] as $body) {
            self::assertStringNotContainsString('53107', $body, 'no report data in mail');
            self::assertStringNotContainsString('październik', $body, 'the request text is not echoed');
        }
        self::assertStringNotContainsString('<img', (string) $ready->getHtmlBody());
        self::assertSame(
            ['Powiadomienie received przechwycone (adres spoza listy testowej; demo przed uruchomieniem)', 'Powiadomienie report_ready przechwycone (adres spoza listy testowej; demo przed uruchomieniem)'],
            $this->db()->fetchFirstColumn("SELECT text FROM ticket_event WHERE ticket_id = ? AND text LIKE 'Powiadomienie%' ORDER BY id", [$id]),
        );

        $this->account('admin@example.com', Role::Admin);
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        $messages = $this->body()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertSame(['received', 'report_ready'], array_column($messages, 'template'));
        self::assertStringNotContainsString('jan@example.com', (string) $this->client->getResponse()->getContent(), 'no recipient address in the dashboard');
    }

    public function testRefusalsAndAdminRejection(): void
    {
        $payroll = $this->mail('Raport płacowy', 'anna@example.com');
        $other = $this->mail('Sprzedaż', 'piotr@example.com');
        $this->work();
        self::assertSame(['received', 'refused'], $this->db()->fetchFirstColumn('SELECT template FROM outbox WHERE ticket_id = ? ORDER BY created_at, template', [$payroll]));
        self::assertStringContainsString('wyłącznie raporty z katalogu', (string) $this->mailer->bySubject('Nie możemy')?->getTextBody());

        $this->account('admin@example.com', Role::Admin);
        self::assertSame(200, $this->login('admin@example.com'));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$other.'/reject'));
        $this->work();
        self::assertSame(['clarification', 'received', 'refused'], $this->db()->fetchFirstColumn('SELECT template FROM outbox WHERE ticket_id = ? ORDER BY created_at, template', [$other]), 'asked first, then rejected by the administrator');
    }

    public function testControlledRecipientsBeforeLaunch(): void
    {
        $id = $this->mail('Katalog Bosch', 'tester@firma-testowa.pl');
        $routes = [];
        foreach (['' => false, 'tester@firma-testowa.pl' => false, 'x@y.pl, @firma-testowa.pl' => false, 'nobody@x.pl' => true] as $allowed => $public) {
            $this->db()->executeStatement('DELETE FROM outbox');
            $this->outbox((string) $allowed, $public)->enqueue($id, 'received', 'k');
            $routes[] = $this->db()->fetchOne('SELECT route FROM outbox');
        }
        self::assertSame(['capture', 'deliver', 'deliver', 'deliver'], $routes);

        $this->db()->executeStatement('DELETE FROM outbox');
        $this->db()->executeStatement("UPDATE mail_message SET sender = 'a@b.pl\r\nBcc: victim@x.pl' WHERE ticket_id = ?", [$id]);
        $this->outbox('', false)->enqueue($id, 'received', 'injected');
        self::assertSame(0, $this->rows('SELECT count(*) FROM outbox'), 'header injection is refused');
        self::assertSame(1, $this->rows("SELECT count(*) FROM ticket_event WHERE text = 'Powiadomienie received pominięte: brak uprawnionego adresata'"));
    }

    public function testEachNotificationIsSentAtMostOnce(): void
    {
        $id = $this->mail('Katalog Bosch', 'jan@example.com');
        $outboxId = $this->db()->fetchOne('SELECT id FROM outbox');
        self::assertIsString($outboxId);
        $handler = $this->sender($this->mailer);
        $handler(new SendNotification($outboxId));
        $handler(new SendNotification($outboxId)); // duplicate job
        self::assertSame(1, $this->sentCount(), 'the duplicate job sent nothing');

        // An attempt interrupted after the claim: the outcome is unknown and the message is not sent again.
        $this->outbox('', false)->enqueue($id, 'refused', 'second', ['reason' => 'admin_rejected']);
        $second = $this->db()->fetchOne("SELECT id FROM outbox WHERE dedup_key = 'second'");
        self::assertIsString($second);
        $this->db()->executeStatement("UPDATE outbox SET status = 'sending' WHERE id = ?", [$second]);
        $handler(new SendNotification($second));
        self::assertSame(['unknown', 'interrupted'], array_values((array) $this->db()->fetchAssociative('SELECT status, error FROM outbox WHERE id = ?', [$second])));
        self::assertSame(1, $this->sentCount());

        $this->outbox('', false)->enqueue($id, 'refused', 'second'); // same key again
        self::assertSame(2, $this->rows('SELECT count(*) FROM outbox'));
    }

    public function testTransportFailures(): void
    {
        $id = $this->mail('Katalog Bosch', 'jan@example.com');
        $outboxId = $this->db()->fetchOne('SELECT id FROM outbox');
        self::assertIsString($outboxId);
        $down = new RecordingMailer(new TransportException('Connection could not be established with host "smtp"'));
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $this->sender($down)(new SendNotification($outboxId));
                self::fail('expected a retry');
            } catch (RecoverableMessageHandlingException) {
                self::assertSame('pending', $this->db()->fetchOne('SELECT status FROM outbox WHERE id = ?', [$outboxId]));
            }
        }
        $this->sender($down)(new SendNotification($outboxId));
        self::assertSame('failed', $this->db()->fetchOne('SELECT status FROM outbox WHERE id = ?', [$outboxId]));

        $this->outbox('', false)->enqueue($id, 'refused', 'other', ['reason' => 'out_of_scope']);
        $other = $this->db()->fetchOne("SELECT id FROM outbox WHERE dedup_key = 'other'");
        self::assertIsString($other);
        $this->sender(new RecordingMailer(new TransportException('451 Temporary failure after DATA')))(new SendNotification($other));
        self::assertSame('unknown', $this->db()->fetchOne('SELECT status FROM outbox WHERE id = ?', [$other]), 'possibly accepted: never resent');
    }

    public function testProductionNotifiesOnlyTheRegisteredAddress(): void
    {
        $this->account('anna@example.com', rep: 'PH-02');
        $c = self::getContainer();
        $production = new Outbox($c->get(Connection::class), $c->get(TicketStore::class), new Templates('https://tabularius.example', 'production'), $c->get(MessageBusInterface::class), new MockClock(), 'production', 'tabularius@gitpush.app');
        $id = $this->mail('Katalog Bosch', 'someone-else@example.com');
        $this->db()->executeStatement("UPDATE ticket SET owner_account_id = (SELECT id FROM account WHERE email = 'anna@example.com') WHERE id = ?", [$id]);
        $this->db()->executeStatement('DELETE FROM outbox');
        $production->enqueue($id, 'confirmation_request', 'c');
        self::assertSame(['anna@example.com', 'deliver'], array_values((array) $this->db()->fetchAssociative('SELECT recipient, route FROM outbox')));
        self::assertStringContainsString('https://tabularius.example/moje-raporty/potwierdz/'.$id, self::text($this->db()->fetchOne('SELECT body_text FROM outbox')));
        self::assertStringContainsString('wiadomość wysłana automatycznie', self::text($this->db()->fetchOne('SELECT body_text FROM outbox')));

        $manual = $this->db()->fetchOne("SELECT id FROM ticket WHERE source = 'mail' LIMIT 1");
        $this->db()->executeStatement("UPDATE ticket SET source = 'manual' WHERE id = ?", [$manual]);
        $production->enqueue(self::text($manual), 'report_ready', 'manual');
        self::assertSame(1, $this->rows('SELECT count(*) FROM outbox'), 'manual tickets are not mailed');
    }

    public function testRetentionKeepsStatesButNotContents(): void
    {
        $this->mail('Katalog Bosch', 'jan@example.com');
        self::assertSame(1, self::getContainer()->get(Outbox::class)->purge(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-01-01')));
        self::assertSame(1, $this->rows("SELECT count(*) FROM outbox WHERE recipient IS NULL AND body_text IS NULL AND body_html IS NULL AND status = 'pending'"));
    }

    private function mail(string $subject, string $from, ?string $messageId = null): string
    {
        $messageId ??= '<n-'.bin2hex(random_bytes(4)).'@example.com>';
        $raw = "From: $from\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMessage-ID: $messageId\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n";
        self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage('local', null, null, $raw, \strlen($raw), new \DateTimeImmutable('2026-11-15 09:00:00+00:00')));
        $id = $this->db()->fetchOne('SELECT ticket_id FROM mail_message ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function work(): void
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        while ([] !== $batch = [...$transport->get()]) {
            foreach ($batch as $envelope) {
                $message = $envelope->getMessage();
                match (true) {
                    $message instanceof InterpretTicket => self::getContainer()->get(InterpretTicketHandler::class)($message),
                    $message instanceof ExecutePlan => self::getContainer()->get(ExecutePlanHandler::class)($message),
                    $message instanceof SendNotification => $this->sender($this->mailer)($message),
                    default => null,
                };
                $transport->ack($envelope);
            }
        }
    }

    private function sender(MailerInterface $mailer): SendNotificationHandler
    {
        $c = self::getContainer();

        return new SendNotificationHandler($c->get(Connection::class), $c->get(TicketStore::class), $mailer, new MockClock(), new NullLogger(), 'tabularius@gitpush.app');
    }

    private function outbox(string $allowed, bool $public): Outbox
    {
        $c = self::getContainer();

        return new Outbox($c->get(Connection::class), $c->get(TicketStore::class), $c->get(Templates::class), $c->get(MessageBusInterface::class), new MockClock(), 'demo', 'tabularius@gitpush.app', $allowed, $public);
    }

    private function sentCount(): int
    {
        return \count($this->mailer->sent);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function number(string $id): string
    {
        $n = $this->db()->fetchOne('SELECT number FROM ticket WHERE id = ?', [$id]);

        return \is_scalar($n) ? (string) $n : '';
    }

    private function rows(string $sql): int
    {
        $value = $this->db()->fetchOne($sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
