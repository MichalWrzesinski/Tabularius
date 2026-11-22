<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mail;

use App\Command\MailInjectCommand;
use App\Command\MailPurgeCommand;
use App\Module\Identity\Role;
use App\Module\Mail\Imap\ImapException;
use App\Module\Mail\IncomingMessage;
use App\Module\Mail\Ingestor;
use App\Module\Mail\MailboxSource;
use App\Module\Mail\MailPoller;
use App\Module\Mail\MailStore;
use App\Module\Mail\MessageIdentity;
use App\Module\Mail\MessageParser;
use App\Module\Ticket\TicketStore;
use App\Tests\Functional\Identity\IdentityTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TASK-006: mailbox message → at most one durable ticket (MAIL-1, MAIL-2, MAIL-5, demo limits, retention).
 * Clock frozen at 2026-11-15 10:42 Europe/Warsaw (config/services.yaml when@test).
 */
final class MailIngestionTest extends IdentityTestCase
{
    private const string AT = '2026-11-15 09:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();
        // Demo mode: every mail ticket runs as the shared demo representative (ID-DEMO-1).
        $this->account('handlowiec@example.com', rep: 'PH-01', shared: true);
    }

    public function testEachFixtureHasItsDefinedOutcome(): void
    {
        $expected = [
            'plain-request' => ['ticketed', 'received', null],
            'html-only' => ['ticketed', 'received', null],
            'quoted-reply' => ['ticketed', 'received', null],
            'with-attachment' => ['ticketed', 'received', null],
            'auto-reply' => ['auto_reply', null, null],
            'bounce' => ['auto_reply', null, null],
        ];
        foreach ($expected as $fixture => [$outcome, $status, $reason]) {
            self::assertSame($outcome, $this->ingest(self::fixture($fixture)), $fixture);
            $row = $this->db()->fetchAssociative('SELECT t.status, t.reason, t.source, t.owner_account_id, t.reference_date FROM mail_message m LEFT JOIN ticket t ON t.id = m.ticket_id ORDER BY m.created_at DESC, m.id DESC LIMIT 1');
            self::assertIsArray($row);
            self::assertSame([$status, $reason], [$row['status'], $row['reason']], $fixture);
            if (null !== $status) {
                self::assertSame(['mail', '2026-11-15'], [$row['source'], $row['reference_date']], $fixture);
                self::assertIsString($row['owner_account_id'], 'the shared demo representative');
            }
        }
        self::assertSame(4, $this->rowsIn('SELECT count(*) FROM ticket'));
        self::assertSame(0, $this->rowsIn("SELECT count(*) FROM mail_message WHERE outcome = 'auto_reply' AND (sender IS NOT NULL OR text_body IS NOT NULL)"), 'automatic messages keep no contents');
        self::assertSame(['Proszę o katalog Bosch, szczegóły w załączniku.'], $this->db()->fetchFirstColumn('SELECT text_body FROM mail_message WHERE attachment_count = 1'));
        $events = $this->db()->fetchFirstColumn('SELECT e.text FROM ticket_event e JOIN mail_message m ON m.ticket_id = e.ticket_id WHERE m.attachment_count = 1 ORDER BY e.id');
        self::assertSame('Wiadomość e-mail przyjęta ze skrzynki (501 B; załączniki pominięte: 1)', $events[0] ?? null);
        self::assertMatchesRegularExpression('/^Tryb demo: zgłoszenie wykonywane na wspólnym koncie handlowca jako Uczestnik #[0-9A-Z]{4}$/', \is_string($events[1] ?? null) ? $events[1] : '');
    }

    public function testDuplicatesByUidAndMessageIdCreateNoSecondTicket(): void
    {
        $raw = self::fixture('plain-request');
        self::assertSame('ticketed', $this->ingest($raw, 7, 100));
        self::assertSame('duplicate', $this->ingest($raw, 7, 100), 'same UID polled twice');
        self::assertSame('duplicate', $this->ingest($raw, 7, 101), 'same Message-ID under another UID');
        self::assertSame('duplicate', $this->ingest($raw), 'injected again');

        self::assertSame(1, $this->rowsIn('SELECT count(*) FROM ticket'));
        self::assertSame(101, self::getContainer()->get(MailStore::class)->lastUid('imap:test', 7), 'the copy is recorded so polling moves on');
        self::assertSame(0, self::getContainer()->get(MailStore::class)->lastUid('imap:test', 8), 'a new UIDVALIDITY starts over');
    }

    public function testMessagesThatNeedInterventionAreKeptNotDropped(): void
    {
        $long = "From: a@example.com\r\nMessage-ID: <long@example.com>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n".str_repeat('słowo ', 700);
        $empty = "From: a@example.com\r\nMessage-ID: <empty@example.com>\r\n\r\n   ";
        $big = "From: b@example.com\r\nMessage-ID: <big@example.com>\r\nSubject: duży\r\n\r\n";

        self::assertSame('text_too_long', $this->ingest($long));
        self::assertSame('no_text', $this->ingest($empty));
        self::assertSame('too_large', self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage('imap:test', 1, 1, $big, 900000, new \DateTimeImmutable(self::AT), true)));

        self::assertSame(
            ['text_too_long', 'message_empty', 'message_too_large'],
            $this->db()->fetchFirstColumn("SELECT t.reason FROM ticket t WHERE t.status = 'needs_intervention' ORDER BY t.number"),
        );
        self::assertSame([null], $this->db()->fetchFirstColumn("SELECT text_body FROM mail_message WHERE outcome = 'text_too_long'"), 'never stored truncated');
    }

    public function testDemoLimitsPerSenderAndGlobally(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $outcome = $this->ingest("From: same@example.com\r\nMessage-ID: <s$i@example.com>\r\n\r\nraport $i");
            self::assertSame($i <= 5 ? 'ticketed' : 'rate_limited', $outcome, "message $i");
        }
        self::assertSame('ticketed', $this->ingest("From: other@example.com\r\nMessage-ID: <o1@example.com>\r\n\r\nraport"));
        self::assertSame(0, $this->rowsIn("SELECT count(*) FROM mail_message WHERE outcome = 'rate_limited' AND sender IS NOT NULL"));

        $container = self::getContainer();
        $strict = new Ingestor($container->get(MailStore::class), $container->get(TicketStore::class), new MessageParser(), $container->get(MessageIdentity::class), $container->get(\Symfony\Component\Messenger\MessageBusInterface::class), new MockClock(self::AT), new NullLogger(), 'secret', 'demo', 5, 20, 6);
        self::assertSame('rate_limited', $strict->ingest(new IncomingMessage('local', null, null, "From: new@example.com\r\n\r\nraport", 30, new \DateTimeImmutable(self::AT))), 'global daily limit');
        $production = new Ingestor($container->get(MailStore::class), $container->get(TicketStore::class), new MessageParser(), $container->get(MessageIdentity::class), $container->get(\Symfony\Component\Messenger\MessageBusInterface::class), new MockClock(self::AT), new NullLogger(), 'secret', 'production', 5, 20, 6);
        self::assertSame('ticketed', $production->ingest(new IncomingMessage('local', null, null, "From: same@example.com\r\nMessage-ID: <p1@example.com>\r\n\r\nraport", 30, new \DateTimeImmutable(self::AT))), 'demo limits only in demo mode');
    }

    public function testPollerContinuesAfterTheLastUidAndSurvivesMailboxErrors(): void
    {
        $container = self::getContainer();
        $source = new class implements MailboxSource {
            public ?int $after = null;
            public bool $fail = false;

            public function name(): string
            {
                return 'imap:fake';
            }

            public function configured(): bool
            {
                return true;
            }

            public function fetch(\Closure $lastUid, int $limit): iterable
            {
                $this->after = $lastUid(9);
                foreach ([$this->after + 1, $this->after + 2] as $uid) {
                    yield new IncomingMessage('imap:fake', 9, $uid, "From: p@example.com\r\nMessage-ID: <poll-$uid@example.com>\r\n\r\nraport $uid", 40, new \DateTimeImmutable('2026-11-15 09:00:00+00:00'));
                }
                if ($this->fail) {
                    throw new ImapException('Mailbox connection closed');
                }
            }
        };
        $poller = new MailPoller($source, $container->get(MailStore::class), $container->get(Ingestor::class), new NullLogger());

        self::assertSame(['ticketed' => 2], $poller->poll());
        self::assertSame(0, $source->after);
        $source->fail = true;
        self::assertSame(['ticketed' => 2], $poller->poll(), 'messages before the failure are kept');
        self::assertSame(2, $source->after);
        self::assertSame(4, $this->rowsIn('SELECT count(*) FROM ticket'));
        self::assertSame([], $container->get(MailPoller::class)->poll(), 'no mailbox configured in tests');
    }

    public function testAdminSeesMailTicketsWithoutMessageContents(): void
    {
        $this->ingest(self::fixture('plain-request'));
        $this->account('admin@example.com', Role::Admin);
        self::assertSame(200, $this->login('admin@example.com'));

        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets'));
        $list = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"source":"mail"', $list);
        self::assertMatchesRegularExpression('/"employeeName":"Uczestnik #[0-9A-Z]{4}","employeeEmail":""/', $list, 'only the alias (ID-DEMO-3)');
        self::assertStringNotContainsString('handlowiec', $list, 'not even the shared account');
        $id = $this->db()->fetchOne('SELECT id FROM ticket');
        self::assertIsString($id);
        self::assertSame(200, $this->api('GET', '/api/v1/admin/tickets/'.$id));
        $detail = (string) $this->client->getResponse()->getContent();
        foreach ([$list, $detail] as $json) {
            foreach (['kowalska', 'Kowalska', 'październik', 'październik', 'Sprzeda'] as $secret) {
                self::assertStringNotContainsString($secret, $json, 'no sender, subject or body in the API (ID-DEMO-3)');
            }
        }
        self::assertSame(409, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/retry'));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/tickets/'.$id.'/reject'), 'a received ticket can be rejected');
    }

    public function testInjectCommandAndRetention(): void
    {
        $container = self::getContainer();
        $file = \dirname(__DIR__, 2).'/Fixtures/mail/plain-request.eml';
        $refused = new MailInjectCommand($container->get(Ingestor::class), new MockClock(self::AT), 'imaps://u:p@imap.example.com');
        self::assertSame(1, $refused(self::io(), $file), 'not with a real mailbox');
        $inject = new MailInjectCommand($container->get(Ingestor::class), new MockClock(self::AT), '');
        self::assertSame(2, $inject(self::io(), '/nonexistent.eml'));
        self::assertSame(0, $inject(self::io(), $file, '2026-11-01T08:00:00+01:00'));
        self::assertSame(['2026-11-01'], $this->db()->fetchFirstColumn('SELECT reference_date FROM ticket'), 'reference date = receive date in Warsaw');

        $purge = new MailPurgeCommand($container->get(MailStore::class), new MockClock('2026-11-15 10:00:00+00:00'), 7);
        self::assertSame(0, $purge(self::io()));
        self::assertSame(1, $this->rowsIn('SELECT count(*) FROM mail_message WHERE purged_at IS NOT NULL AND sender IS NULL AND subject IS NULL AND header_block IS NULL AND text_body IS NULL AND sender_hash IS NOT NULL'));
    }

    private function ingest(string $raw, ?int $uidValidity = null, ?int $uid = null): string
    {
        $mailbox = null === $uid ? 'local' : 'imap:test';

        return self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage($mailbox, $uidValidity, $uid, $raw, \strlen($raw), new \DateTimeImmutable(self::AT)));
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/mail/'.$name.'.eml');
    }

    private static function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    private function rowsIn(string $sql): int
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
