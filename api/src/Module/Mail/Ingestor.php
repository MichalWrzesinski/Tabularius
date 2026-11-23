<?php

declare(strict_types=1);

namespace App\Module\Mail;

use App\Module\Report\Period\DateRange;
use App\Module\Ticket\TicketStatus;
use App\Module\Ticket\TicketStore;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a mailbox message into at most one durable ticket (MAIL-1, MAIL-2, MAIL-5, SEC limits):
 * - a known UID or Message-ID is a duplicate: recorded without contents, no ticket;
 * - automatic messages (auto-replies, bounces, bulk) never create a ticket, so they can never be answered;
 * - demo-mode sender and global limits are counted durably in the database;
 * - oversized, unparseable, empty or too long messages still become tickets, in `needs_intervention` with a reason,
 *   never silently truncated or dropped;
 * - a reply (MAIL-CORR) joins a ticket only when its In-Reply-To/References name one of that ticket's own outgoing
 *   Message-IDs and its sender is the ticket's participant; a ticket waiting for an answer goes back to
 *   interpretation, any other ticket only records it (`late_reply`). Subject, `Re:` or the sender alone never do;
 * - everything else becomes a `received` ticket. The message row and the ticket are written in one transaction.
 * Logs never contain addresses, subjects or bodies.
 */
final class Ingestor
{
    public const int MAX_TEXT = 4000;

    public function __construct(
        private readonly MailStore $mail,
        private readonly TicketStore $tickets,
        private readonly MessageParser $parser,
        private readonly MessageIdentity $identity,
        private readonly \Symfony\Component\Messenger\MessageBusInterface $bus,
        private readonly \App\Module\Notification\Outbox $outbox,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_SECRET')]
        private readonly string $secret,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
        #[Autowire(env: 'int:MAIL_LIMIT_SENDER_HOUR')]
        private readonly int $perSenderHour = 5,
        #[Autowire(env: 'int:MAIL_LIMIT_SENDER_DAY')]
        private readonly int $perSenderDay = 20,
        #[Autowire(env: 'int:MAIL_LIMIT_GLOBAL_DAY')]
        private readonly int $globalDay = 200,
        #[Autowire(env: 'MAIL_INGEST_SINCE')]
        private readonly string $since = '',
    ) {
    }

    /** @return string the outcome (mail_message.outcome) */
    public function ingest(IncomingMessage $message): string
    {
        if (null !== $message->uid && null !== $message->uidValidity && $this->mail->knownUid($message->mailbox, $message->uidValidity, $message->uid)) {
            return 'duplicate';
        }
        // Mail that was already in the mailbox when it was connected: recorded (so polling moves on), never read.
        if ('' !== $this->since && $message->receivedAt < new \DateTimeImmutable($this->since)) {
            return $this->record($message, null, null, 'before_start');
        }
        try {
            $parsed = $this->parser->parse($message->raw);
        } catch (\Throwable $e) {
            $this->logger->warning('Unparseable message', ['exception_class' => $e::class, 'size' => $message->size]);
            $parsed = null;
        }

        $idHash = null === $parsed?->messageId ? null : hash('sha256', mb_strtolower($parsed->messageId));
        if (null !== $idHash && $this->mail->knownMessageId($idHash)) {
            // With a mailbox UID the copy is recorded (without contents) so polling moves past it.
            return null === $message->uid ? 'duplicate' : $this->record($message, null, null, 'duplicate');
        }
        if (null !== $parsed && $parsed->automatic) {
            return $this->record($message, null, $idHash, 'auto_reply');
        }
        $senderHash = null === $parsed?->sender ? null : hash_hmac('sha256', $parsed->sender, $this->secret);
        if ('demo' === $this->mode && $this->limited($senderHash, $message->receivedAt)) {
            return $this->record($message, null, $idHash, 'rate_limited', $senderHash);
        }

        [$outcome, $status, $reason] = match (true) {
            null === $parsed => ['unparseable', TicketStatus::NeedsIntervention, 'message_unparseable'],
            $message->headersOnly => ['too_large', TicketStatus::NeedsIntervention, 'message_too_large'],
            '' === $parsed->text && '' === $parsed->subject => ['no_text', TicketStatus::NeedsIntervention, 'message_empty'],
            mb_strlen($parsed->text) > self::MAX_TEXT => ['text_too_long', TicketStatus::NeedsIntervention, 'text_too_long'],
            default => ['ticketed', TicketStatus::Received, null],
        };

        if ('ticketed' === $outcome && null !== $parsed && [] !== $parsed->replyTo) {
            $reply = $this->reply($message, $parsed, $idHash, $senderHash);
            if (null !== $reply) {
                return $reply;
            }
        }

        return $this->record($message, $parsed, $idHash, $outcome, $senderHash, $status, $reason);
    }

    /** The outcome when the message correlates with a ticket, or null to treat it as a new request. */
    private function reply(IncomingMessage $message, ParsedMessage $parsed, ?string $idHash, ?string $senderHash): ?string
    {
        if (null === $senderHash) {
            return null;
        }
        $db = $this->tickets->connection();
        $tickets = $db->fetchFirstColumn(
            'SELECT DISTINCT ticket_id FROM outbox WHERE message_id IN (?)',
            [$parsed->replyTo],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        if (1 !== \count($tickets) || !\is_string($tickets[0])) {
            return null;
        }
        $ticketId = $tickets[0];
        // The participant: the sender of the ticket's request (demo, ID-DEMO-2) who, in production, is still the
        // ticket owner's registered address of an active account.
        $original = $db->fetchOne("SELECT sender_hash FROM mail_message WHERE ticket_id = ? AND outcome NOT IN ('reply', 'late_reply') ORDER BY received_at LIMIT 1", [$ticketId]);
        $owner = $db->fetchOne('SELECT owner_account_id FROM ticket WHERE id = ?', [$ticketId]);
        if ($original !== $senderHash || !\is_string($owner)
            || (!$this->identity->demo() && $this->identity->resolve($parsed->sender, $senderHash)['owner'] !== $owner)) {
            return null;
        }
        $now = $this->clock->now();
        try {
            return $db->transactional(function () use ($message, $parsed, $idHash, $senderHash, $ticketId, $now): string {
                $ticket = $this->tickets->lock($ticketId);
                $waiting = null !== $ticket && TicketStatus::NeedsClarification === $ticket['status'];
                $outcome = $waiting ? 'reply' : 'late_reply';
                if ($waiting) {
                    $this->tickets->setStatus($ticketId, TicketStatus::Interpreting, null, $now);
                    $this->tickets->event($ticketId, 'system', 'Poczta', 'Odpowiedź w wątku przyjęta; ponowna interpretacja', $now);
                    $this->bus->dispatch(new \App\Module\Interpretation\Job\InterpretTicket($ticketId, $ticket['plan_version']));
                } else {
                    $this->tickets->event($ticketId, 'system', 'Poczta', 'Odpowiedź w wątku, gdy zgłoszenie nie czekało na uzupełnienie; pominięta, nic nie wykonano', $now);
                }
                $this->mail->insert($this->row($message, $waiting ? $parsed : null, $idHash, $outcome, $senderHash, $ticketId, $now));

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
    }

    private function limited(?string $senderHash, \DateTimeImmutable $at): bool
    {
        if ($this->mail->ticketedSince(null, $at->modify('-1 day')) >= $this->globalDay) {
            return true;
        }

        return null !== $senderHash && (
            $this->mail->ticketedSince($senderHash, $at->modify('-1 hour')) >= $this->perSenderHour
            || $this->mail->ticketedSince($senderHash, $at->modify('-1 day')) >= $this->perSenderDay
        );
    }

    private function record(IncomingMessage $message, ?ParsedMessage $parsed, ?string $idHash, string $outcome, ?string $senderHash = null, ?TicketStatus $status = null, ?string $reason = null): string
    {
        $now = $this->clock->now();
        $keep = null !== $status; // contents are kept only for messages that became tickets
        try {
            $this->tickets->connection()->transactional(function () use ($message, $parsed, $idHash, $outcome, $senderHash, $status, $reason, $now, $keep): void {
                $ticketId = null;
                if (null !== $status) {
                    $id = Uuid::v7();
                    $ticketId = $id->toRfc4122();
                    $reference = $message->receivedAt->setTimezone(DateRange::zone())->setTime(0, 0);
                    ['owner' => $owner, 'alias' => $alias] = $this->identity->resolve($parsed?->sender, $senderHash);
                    if (null === $owner && TicketStatus::Received === $status) {
                        [$status, $reason] = [TicketStatus::NeedsIntervention, 'identity_unverified']; // ID-4
                    }
                    $this->tickets->insert($id, $owner, 'mail', $status, $reference, $message->receivedAt, 'mail', $alias);
                    if (null !== $reason) {
                        $this->tickets->setStatus($ticketId, $status, $reason, $now);
                    }
                    $this->tickets->event($ticketId, 'system', 'Poczta', self::receivedText($message, $parsed), $now);
                    $this->tickets->event($ticketId, 'system', 'Tożsamość', $this->identityText($owner, $alias), $now);
                    if (null !== $reason && 'identity_unverified' !== $reason) {
                        $this->tickets->event($ticketId, 'system', 'Poczta', self::reasonText($reason), $now);
                    }
                    if (TicketStatus::Received === $status) {
                        // Same connection as the ticket: the job exists exactly when the ticket does.
                        $this->bus->dispatch(new \App\Module\Interpretation\Job\InterpretTicket($ticketId));
                    }
                }
                $this->mail->insert($this->row($message, $keep ? $parsed : null, $idHash, $outcome, $senderHash, $ticketId, $now, null === $parsed ? 0 : $parsed->attachmentCount));
                if (null !== $ticketId) {
                    $this->outbox->enqueue($ticketId, 'received', $ticketId.':received');
                }
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate'; // a concurrent poll stored it first
        }
        $this->logger->info('Mail message ingested', ['outcome' => $outcome, 'size' => $message->size]);

        return $outcome;
    }

    /**
     * The mail_message row; contents (`$kept`) only for messages that became or joined a ticket.
     *
     * @return array{id: string, mailbox: string, uid_validity: ?int, uid: ?int, message_id_hash: ?string, message_id: ?string, received_at: string, sender_hash: ?string, sender: ?string, subject: ?string, header_block: ?string, text_body: ?string, size_bytes: int, attachment_count: int, outcome: string, ticket_id: ?string, created_at: string}
     */
    private function row(IncomingMessage $message, ?ParsedMessage $kept, ?string $idHash, string $outcome, ?string $senderHash, ?string $ticketId, \DateTimeImmutable $now, ?int $attachments = null): array
    {
        return [
            'id' => Uuid::v7()->toRfc4122(),
            'mailbox' => $message->mailbox,
            'uid_validity' => $message->uidValidity,
            'uid' => $message->uid,
            'message_id_hash' => 'duplicate' === $outcome ? null : $idHash,
            'message_id' => $kept?->messageId,
            'received_at' => TicketStore::ts($message->receivedAt),
            'sender_hash' => $senderHash,
            'sender' => $kept?->sender,
            'subject' => $kept?->subject,
            'header_block' => $kept?->headerBlock,
            'text_body' => 'text_too_long' !== $outcome ? $kept?->text : null,
            'size_bytes' => $message->size,
            'attachment_count' => $attachments ?? $kept->attachmentCount ?? 0,
            'outcome' => $outcome,
            'ticket_id' => $ticketId,
            'created_at' => TicketStore::ts($now),
        ];
    }

    private static function receivedText(IncomingMessage $message, ?ParsedMessage $parsed): string
    {
        $attachments = $parsed->attachmentCount ?? 0;

        return \sprintf(
            'Wiadomość e-mail przyjęta ze skrzynki (%d B%s)',
            $message->size,
            $attachments > 0 ? \sprintf('; załączniki pominięte: %d', $attachments) : '',
        );
    }

    private function identityText(?string $owner, ?string $alias): string
    {
        if ($this->identity->demo()) {
            return null === $owner
                ? 'Tryb demo: brak wspólnego konta handlowca; obsługa ręczna'
                : \sprintf('Tryb demo: zgłoszenie wykonywane na wspólnym koncie handlowca jako %s', $alias);
        }

        return null === $owner
            ? 'Nadawca nierozpoznany lub konto nieaktywne; obsługa ręczna, nic nie ujawniono'
            : 'Nadawca dopasowany do konta pracownika (tylko kandydat); wymagane potwierdzenie po zalogowaniu';
    }

    private static function reasonText(string $reason): string
    {
        return match ($reason) {
            'message_too_large' => 'Wiadomość przekracza limit rozmiaru; zachowano tylko nagłówki',
            'message_unparseable' => 'Nie udało się odczytać struktury wiadomości',
            'message_empty' => 'Wiadomość nie zawiera tekstu',
            default => \sprintf('Tekst prośby przekracza %d znaków; nie został skrócony', self::MAX_TEXT),
        };
    }
}
