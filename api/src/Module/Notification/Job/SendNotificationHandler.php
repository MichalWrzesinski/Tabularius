<?php

declare(strict_types=1);

namespace App\Module\Notification\Job;

use App\Module\Ticket\TicketStore;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one outbox message, at most once (tickets.md, notification state):
 * - only a `pending` row is claimed (→ `sending`); a duplicate job finds nothing to do;
 * - a row found in `sending` means an earlier attempt was interrupted after the claim: the outcome is unknown, so it is
 *   marked `unknown` and never resent automatically;
 * - a connection that could not be established is retried (at most 3 attempts), then `failed`; any other transport
 *   error leaves the outcome `unknown`.
 * `accepted` means the SMTP server accepted the message, not that it was delivered.
 */
#[AsMessageHandler]
final class SendNotificationHandler
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly TicketStore $tickets,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
    ) {
    }

    public function __invoke(SendNotification $job): void
    {
        $row = $this->db->fetchAssociative(
            "UPDATE outbox SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'pending' RETURNING ticket_id, template, route, recipient, message_id, in_reply_to, subject, body_text, body_html, attempts",
            [$job->outboxId],
        );
        if (false === $row) {
            $this->interrupted($job->outboxId);

            return;
        }
        $ticketId = self::s($row['ticket_id']);
        $template = self::s($row['template']);
        try {
            $this->mailer->send($this->email($row));
        } catch (TransportExceptionInterface $e) {
            $notConnected = str_contains($e->getMessage(), 'Connection could not be established');
            if ($notConnected && (int) self::s($row['attempts']) < self::MAX_ATTEMPTS) {
                $this->db->executeStatement("UPDATE outbox SET status = 'pending' WHERE id = ?", [$job->outboxId]);

                throw new RecoverableMessageHandlingException('Mail server unreachable; retrying', 0, $e);
            }
            $status = $notConnected ? 'failed' : 'unknown';
            $this->finish($job->outboxId, $ticketId, $status, $e::class, \sprintf('Powiadomienie %s: %s', $template, 'failed' === $status ? 'serwer poczty nieosiągalny' : 'wynik wysyłki nieznany (bez ponawiania)'));
            $this->logger->warning('Notification not sent', ['outbox' => $job->outboxId, 'status' => $status, 'error' => $e::class]);

            return;
        }
        $this->finish($job->outboxId, $ticketId, 'accepted', null, \sprintf(
            'deliver' === $row['route'] ? 'Powiadomienie %s przyjęte przez serwer poczty' : 'Powiadomienie %s przechwycone (adres spoza listy testowej; demo przed uruchomieniem)',
            $template,
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function email(array $row): Email
    {
        $email = new Email()
            ->from(new Address($this->from, 'Tabularius'))
            ->to(new Address(self::s($row['recipient'])))
            ->subject(self::s($row['subject']))
            ->text(self::s($row['body_text']))
            ->html(self::s($row['body_html']));
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', self::s($row['message_id']));
        if (\is_string($row['in_reply_to']) && '' !== $row['in_reply_to']) {
            $headers->addIdHeader('In-Reply-To', $row['in_reply_to']);
            $headers->addIdHeader('References', $row['in_reply_to']);
        }
        // RFC 3834: automatic message, so well-behaved auto-responders do not answer it (no loops).
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        $headers->addTextHeader('X-Transport', 'deliver' === $row['route'] ? 'main' : 'capture');

        return $email;
    }

    private function interrupted(string $id): void
    {
        $row = $this->db->fetchAssociative("UPDATE outbox SET status = 'unknown', error = 'interrupted' WHERE id = ? AND status = 'sending' RETURNING ticket_id, template", [$id]);
        if (false !== $row) {
            $this->tickets->event(self::s($row['ticket_id']), 'system', 'Poczta', \sprintf('Powiadomienie %s: wysyłka przerwana, wynik nieznany (bez ponawiania)', self::s($row['template'])), $this->clock->now());
        }
    }

    private function finish(string $id, string $ticketId, string $status, ?string $error, string $event): void
    {
        $now = $this->clock->now();
        $this->db->executeStatement('UPDATE outbox SET status = ?, error = ?, sent_at = ? WHERE id = ?', [$status, $error, 'accepted' === $status ? TicketStore::ts($now) : null, $id]);
        $this->tickets->event($ticketId, 'system', 'Poczta', $event, $now);
    }

    private static function s(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
