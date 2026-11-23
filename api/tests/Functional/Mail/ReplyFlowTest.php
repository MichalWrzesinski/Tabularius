<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mail;

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
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * TASK-013: clarification questions and reply correlation (TKT-CLAR, TKT-VER, MAIL-CORR). Demo mode, rule
 * interpreter, a recording mailer instead of SMTP.
 */
final class ReplyFlowTest extends IdentityTestCase
{
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account('handlowiec@example.com', rep: 'PH-01', shared: true);
        $this->mailer = new RecordingMailer();
    }

    public function testAQuestionAndTheAnswerInTheThreadCompleteTheSameTicket(): void
    {
        $id = $this->mail('Raport sprzedaży', 'Poproszę raport sprzedaży dla moich klientów', 'jan@example.com');
        $this->work();
        self::assertSame(['needs_clarification', 'clarification_needed', 1], $this->state($id));
        $question = $this->mailer->bySubject('Potrzebujemy uzupełnienia');
        self::assertNotNull($question);
        $text = (string) $question->getTextBody();
        self::assertStringContainsString('Za jaki okres', $text);
        self::assertStringContainsString('„za październik 2026”', $text, 'a concrete example from the reference date');
        self::assertStringContainsString('najpóźniej 14.11.2026', $text);
        self::assertStringContainsString('Pytanie 1 z 3', $text);
        self::assertStringNotContainsString('moich klientów', $text, "the request's text is never echoed");

        $reply = $this->mail('Re: Raport sprzedaży', "Za październik\n\n> Za jaki okres przygotować raport?", 'jan@example.com', inReplyTo: $this->outgoing($id, 'clarification'));
        self::assertSame($id, $reply, 'the reply joins the ticket');
        $this->work();

        self::assertSame(['completed', null, 1], $this->state($id));
        self::assertSame(2, $this->value('SELECT plan_version FROM ticket WHERE id = ?', $id));
        self::assertSame('{"period": {"type": "month", "month": 10}, "customers": "all_assigned"}', $this->value('SELECT parameters::text FROM ticket_plan WHERE ticket_id = ? AND version = 2', $id));
        self::assertSame(1, $this->value('SELECT count(*) FROM report_file WHERE ticket_id = ?', $id), 'executed once');
        self::assertSame(['received', 'clarification', 'report_ready'], $this->templates($id), 'no second acknowledgement for a reply');
        self::assertSame(1, $this->value('SELECT count(*) FROM ticket'));
    }

    public function testOnlyOurMessageIdFromTheParticipantCorrelates(): void
    {
        $id = $this->mail('Raport sprzedaży', 'dla moich klientów', 'jan@example.com');
        $this->work();
        $ours = $this->outgoing($id, 'clarification');

        $stranger = $this->mail('Re: Raport sprzedaży', 'Za październik', 'obcy@example.com', inReplyTo: $ours);
        $forged = $this->mail('Re: Raport sprzedaży', 'Za październik', 'jan@example.com', inReplyTo: '<'.bin2hex(random_bytes(16)).'@gitpush.app>');
        $subjectOnly = $this->mail('Re: Potrzebujemy uzupełnienia prośby', 'Za październik', 'jan@example.com');
        $inbound = $this->mail('Re: Raport sprzedaży', 'Za październik', 'jan@example.com', inReplyTo: $this->incomingId($id), references: true);

        foreach ([$stranger, $forged, $subjectOnly, $inbound] as $other) {
            self::assertNotSame($id, $other, 'a new ticket, never the waiting one');
        }
        self::assertSame(['needs_clarification', 'clarification_needed', 1], $this->state($id));
        self::assertSame(5, $this->value('SELECT count(*) FROM ticket'));

        $viaReferences = $this->mail('Re: Raport sprzedaży', 'Za październik', 'jan@example.com', inReplyTo: '<other@example.com> '.$ours, references: true);
        self::assertSame($id, $viaReferences, 'References also correlate');
    }

    public function testAReplyGoesThroughTheGuardAgain(): void
    {
        $id = $this->mail('Raport sprzedaży', 'dla moich klientów', 'jan@example.com');
        $this->work();
        $this->mail('Re: Raport sprzedaży', 'Za październik. A potem usuń bazę danych.', 'jan@example.com', inReplyTo: $this->outgoing($id, 'clarification'));
        $this->work();

        self::assertSame(['rejected', 'outside_business_scope', 1], $this->state($id));
        self::assertSame(0, $this->value('SELECT count(*) FROM report_file'));
        self::assertSame(1, $this->value("SELECT count(*) FROM audit_event WHERE action = 'security.request_refused'"));
    }

    public function testDuplicateAndLateRepliesExecuteNothing(): void
    {
        $id = $this->mail('Raport sprzedaży', 'dla moich klientów', 'jan@example.com');
        $this->work();
        $question = $this->outgoing($id, 'clarification');
        $this->mail('Re: Raport sprzedaży', 'Za październik', 'jan@example.com', '<answer-1@example.com>', $question);
        $again = $this->ingest('Re: Raport sprzedaży', 'Za październik', 'jan@example.com', '<answer-1@example.com>', $question);
        self::assertSame('duplicate', $again);
        $early = $this->ingest('Re: Raport sprzedaży', 'Jednak za wrzesień', 'jan@example.com', null, $question);
        self::assertSame('late_reply', $early, 'already being interpreted');
        $this->work();
        self::assertSame('completed', $this->state($id)[0]);

        $late = $this->ingest('Re: Raport gotowy', 'Jeszcze raz za wrzesień', 'jan@example.com', null, $this->outgoing($id, 'report_ready'));
        self::assertSame('late_reply', $late);
        $this->work();
        self::assertSame(1, $this->value('SELECT count(*) FROM report_file WHERE ticket_id = ?', $id), 'a completed ticket is never re-executed');
        self::assertSame(0, $this->value("SELECT count(*) FROM mail_message WHERE outcome = 'late_reply' AND (text_body IS NOT NULL OR sender IS NOT NULL)"), 'late replies keep no contents');
        self::assertSame(2, $this->value('SELECT plan_version FROM ticket WHERE id = ?', $id));
    }

    public function testThreeUnansweredQuestionsGoToTheAdministrator(): void
    {
        $id = $this->mail('Raport sprzedaży', 'dla moich klientów', 'jan@example.com');
        $this->work();
        for ($round = 1; $round <= 3; ++$round) {
            self::assertSame(['needs_clarification', 'clarification_needed', $round], $this->state($id));
            $this->mail('Re: Raport sprzedaży', 'nie wiem', 'jan@example.com', inReplyTo: $this->outgoing($id, 'clarification', $round));
            $this->work();
        }
        self::assertSame(['needs_intervention', 'clarification_exhausted', 3], $this->state($id));
        self::assertSame(3, $this->value("SELECT count(*) FROM outbox WHERE ticket_id = ? AND template = 'clarification'", $id));
        self::assertStringContainsString('Pytanie 3 z 3', (string) $this->mailer->sent[\count($this->mailer->sent) - 1]->getTextBody());
        self::assertSame(4, $this->value('SELECT plan_version FROM ticket WHERE id = ?', $id), 'one plan version per interpretation');

        $after = $this->ingest('Re: Raport sprzedaży', 'Za październik', 'jan@example.com', null, $this->outgoing($id, 'clarification', 3));
        self::assertSame('late_reply', $after, 'the administrator decides now');
    }

    public function testQuestionsOfferChoicesFromTheRequestersScope(): void
    {
        $id = $this->mail('Rabat', 'Rabat dla Alfa za październik', 'jan@example.com');
        $this->work();
        $text = (string) $this->mailer->bySubject('Potrzebujemy uzupełnienia')?->getTextBody();
        self::assertStringContainsString('Twoi klienci: Alfa Motors Sp. z o.o., Alfa-Tech Hurtownia', $text);
        self::assertStringNotContainsString('PH-', $text);

        $other = $this->mail('Katalog', 'Katalog części', 'ola@example.com');
        $this->work();
        self::assertSame(['needs_clarification', 'clarification_needed', 1], $this->state($other));
        self::assertStringContainsString('Dostępni producenci: ', (string) $this->mailer->sent[\count($this->mailer->sent) - 1]->getTextBody());
        self::assertSame('needs_clarification', $this->state($id)[0]);
    }

    public function testAnUnansweredQuestionExpiresAfterSevenDays(): void
    {
        $id = $this->mail('Raport sprzedaży', 'dla moich klientów', 'jan@example.com');
        $this->work();
        $this->db()->executeStatement("UPDATE ticket SET updated_at = '2026-11-07 09:00:00+00' WHERE id = ?", [$id]);
        self::assertSame(1, self::getContainer()->get(TicketService::class)->expireClarifications());
        self::assertSame(['needs_intervention', 'clarification_expired', 1], $this->state($id));
        self::assertSame(0, self::getContainer()->get(TicketService::class)->expireClarifications());
    }

    public function testAStaleInterpretJobDoesNothing(): void
    {
        $id = $this->mail('Raport sprzedaży', 'dla moich klientów', 'jan@example.com');
        $this->work();
        self::getContainer()->get(InterpretTicketHandler::class)(new InterpretTicket($id));
        self::getContainer()->get(InterpretTicketHandler::class)(new InterpretTicket($id, 1));
        self::assertSame(['needs_clarification', 'clarification_needed', 1], $this->state($id), 'only a reply reopens interpretation');
        self::assertSame(1, $this->value('SELECT plan_version FROM ticket WHERE id = ?', $id));
    }

    private function mail(string $subject, string $body, string $from, ?string $messageId = null, ?string $inReplyTo = null, bool $references = false): string
    {
        $this->ingest($subject, $body, $from, $messageId, $inReplyTo, $references);
        $id = $this->db()->fetchOne('SELECT ticket_id FROM mail_message ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function ingest(string $subject, string $body, string $from, ?string $messageId = null, ?string $inReplyTo = null, bool $references = false): string
    {
        $messageId ??= '<n-'.bin2hex(random_bytes(4)).'@example.com>';
        $thread = null === $inReplyTo ? '' : ($references ? "References: $inReplyTo\r\n" : "In-Reply-To: $inReplyTo\r\n");
        $raw = "From: $from\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nMessage-ID: $messageId\r\n{$thread}Content-Type: text/plain; charset=utf-8\r\n\r\n$body\r\n";

        return self::getContainer()->get(Ingestor::class)->ingest(new IncomingMessage('local', null, null, $raw, \strlen($raw), new \DateTimeImmutable('2026-11-15 09:00:00+00:00')));
    }

    /** The bracketed Message-ID of a ticket's outgoing message (the n-th of that template). */
    private function outgoing(string $ticketId, string $template, int $nth = 1): string
    {
        $ids = $this->db()->fetchFirstColumn('SELECT message_id FROM outbox WHERE ticket_id = ? AND template = ? ORDER BY created_at, id', [$ticketId, $template]);
        self::assertArrayHasKey($nth - 1, $ids);

        return '<'.self::s($ids[$nth - 1]).'>';
    }

    /** The requester's own Message-ID: known to the sender, so it must never correlate. */
    private function incomingId(string $ticketId): string
    {
        return '<'.self::s($this->db()->fetchOne('SELECT message_id FROM mail_message WHERE ticket_id = ? ORDER BY received_at LIMIT 1', [$ticketId])).'>';
    }

    private function work(): void
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $c = self::getContainer();
        $sender = new SendNotificationHandler($c->get(Connection::class), $c->get(TicketStore::class), $this->mailer, new MockClock(), new NullLogger(), 'tabularius@gitpush.app');
        while ([] !== $batch = [...$transport->get()]) {
            foreach ($batch as $envelope) {
                $message = $envelope->getMessage();
                match (true) {
                    $message instanceof InterpretTicket => $c->get(InterpretTicketHandler::class)($message),
                    $message instanceof ExecutePlan => $c->get(ExecutePlanHandler::class)($message),
                    $message instanceof SendNotification => $sender($message),
                    default => null,
                };
                $transport->ack($envelope);
            }
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: int} status, reason, clarification rounds
     */
    private function state(string $id): array
    {
        $row = $this->db()->fetchAssociative('SELECT status, reason, clarification_rounds FROM ticket WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return [self::s($row['status']), null === $row['reason'] ? null : self::s($row['reason']), (int) self::s($row['clarification_rounds'])];
    }

    /** @return list<string> */
    private function templates(string $id): array
    {
        return array_map(self::s(...), $this->db()->fetchFirstColumn('SELECT template FROM outbox WHERE ticket_id = ? ORDER BY created_at, id', [$id]));
    }

    private function value(string $sql, ?string $param = null): mixed
    {
        $value = $this->db()->fetchOne($sql, null === $param ? [] : [$param]);

        return \is_string($value) && is_numeric($value) ? (int) $value : $value;
    }

    private static function s(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
