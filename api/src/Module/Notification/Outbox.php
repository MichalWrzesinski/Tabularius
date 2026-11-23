<?php

declare(strict_types=1);

namespace App\Module\Notification;

use App\Module\Notification\Job\SendNotification;
use App\Module\Ticket\TicketStore;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a notification in the caller's transaction and queues its sending job on the same connection (MAIL-3): the
 * message exists exactly when the ticket change does. One row per dedup key, so repeating a step sends nothing twice.
 *
 * Recipient (ID-2, ID-DEMO-2): demo mail tickets answer only the sender of that ticket; otherwise the owner's
 * registered address (never a shared demo account, never an address from the message body or Reply-To/CC).
 * Route: until the public demo is approved (MAIL_PUBLIC_REPLIES=0) demo messages are delivered only to the controlled
 * test addresses (MAIL_ALLOWED_RECIPIENTS) and captured otherwise; production delivers to registered addresses.
 */
final class Outbox
{
    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(
        private readonly Connection $db,
        private readonly TicketStore $tickets,
        private readonly Templates $templates,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
        #[Autowire(env: 'MAIL_ALLOWED_RECIPIENTS')]
        string $allowed = '',
        #[Autowire(env: 'bool:MAIL_PUBLIC_REPLIES')]
        private readonly bool $publicReplies = false,
    ) {
        $this->allowed = array_values(array_filter(array_map(static fn (string $a): string => mb_strtolower(trim($a)), explode(',', $allowed))));
    }

    /**
     * @param 'received'|'confirmation_request'|'report_ready'|'refused'|'clarification'      $template
     * @param array{fileId?: string, reason?: ?string, questions?: list<string>, round?: int} $values
     */
    public function enqueue(string $ticketId, string $template, string $dedupKey, array $values = []): void
    {
        $now = $this->clock->now();
        $ticket = $this->db->fetchAssociative('SELECT t.number, t.source, a.email, a.status, a.shared FROM ticket t LEFT JOIN account a ON a.id = t.owner_account_id WHERE t.id = ?', [$ticketId]);
        if (false === $ticket || 'mail' !== $ticket['source']) {
            return; // a request sent by e-mail is answered by e-mail; manual tickets are not
        }
        $message = $this->db->fetchAssociative('SELECT sender, message_id FROM mail_message WHERE ticket_id = ? ORDER BY received_at LIMIT 1', [$ticketId]) ?: ['sender' => null, 'message_id' => null];
        $recipient = 'demo' === $this->mode
            ? $message['sender']
            : ('active' === $ticket['status'] && true !== $ticket['shared'] ? $ticket['email'] : null);
        // Header injection guard (MAIL-4): one plain address, nothing else.
        if (!\is_string($recipient) || false === filter_var($recipient, \FILTER_VALIDATE_EMAIL) || 1 === preg_match('/[\r\n,;<>]/', $recipient)) {
            $this->tickets->event($ticketId, 'system', 'Poczta', \sprintf('Powiadomienie %s pominięte: brak uprawnionego adresata', $template), $now);

            return;
        }
        if (false !== $this->db->fetchOne('SELECT 1 FROM outbox WHERE dedup_key = ?', [$dedupKey])) {
            return;
        }

        $rendered = $this->templates->render($template, ['number' => 'T-'.(\is_scalar($ticket['number']) ? $ticket['number'] : ''), 'ticketId' => $ticketId] + $values);
        $id = Uuid::v7()->toRfc4122();
        $this->db->insert('outbox', [
            'id' => $id,
            'ticket_id' => $ticketId,
            'dedup_key' => $dedupKey,
            'template' => $template,
            'route' => $this->route($recipient),
            'recipient' => $recipient,
            'message_id' => bin2hex(random_bytes(16)).'@'.$this->domain(),
            'in_reply_to' => \is_string($message['message_id']) ? $message['message_id'] : null,
            'subject' => $rendered['subject'],
            'body_text' => $rendered['text'],
            'body_html' => $rendered['html'],
            'status' => 'pending',
            'created_at' => TicketStore::ts($now),
        ]);
        $this->bus->dispatch(new SendNotification($id));
    }

    /**
     * Outgoing messages of a ticket for the dashboard: template, delivery state and the rendered text. Never the
     * recipient address (ID-DEMO-3); the text is the backend template, without the request's content.
     *
     * @return list<array{id: string, direction: 'out', at: string, kind: string, template: string, delivery: string, route: string, body: ?string}>
     */
    public function forTicket(string $ticketId): array
    {
        $kinds = ['received' => 'notification', 'report_ready' => 'notification', 'refused' => 'refusal', 'confirmation_request' => 'confirmation_request', 'clarification' => 'clarification_request'];

        return array_map(static fn (array $r): array => [
            'id' => self::s($r['id']),
            'direction' => 'out',
            'at' => new \DateTimeImmutable(self::s($r['created_at']))->format(\DATE_ATOM),
            'kind' => $kinds[self::s($r['template'])] ?? 'notification',
            'template' => self::s($r['template']),
            'delivery' => self::s($r['status']),
            'route' => self::s($r['route']),
            'body' => \is_string($r['body_text']) ? $r['body_text'] : null,
        ], $this->db->fetchAllAssociative('SELECT id, created_at, template, status, route, body_text FROM outbox WHERE ticket_id = ? ORDER BY created_at, id', [$ticketId]));
    }

    /** Retention (ID-DEMO-7): recipient and bodies of messages older than `$before` are deleted; states stay. */
    public function purge(\DateTimeImmutable $before, \DateTimeImmutable $now): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE outbox SET recipient = NULL, body_text = NULL, body_html = NULL, in_reply_to = NULL, purged_at = ? WHERE purged_at IS NULL AND created_at < ?',
            [TicketStore::ts($now), TicketStore::ts($before)],
        );
    }

    private function route(string $recipient): string
    {
        if ('demo' !== $this->mode || $this->publicReplies) {
            return 'deliver';
        }
        foreach ($this->allowed as $entry) {
            if ($recipient === $entry || (str_starts_with($entry, '@') && str_ends_with($recipient, $entry))) {
                return 'deliver';
            }
        }

        return 'capture';
    }

    private function domain(): string
    {
        $at = strrchr($this->from, '@');

        return false === $at || '@' === $at ? 'tabularius.invalid' : substr($at, 1);
    }

    private static function s(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
