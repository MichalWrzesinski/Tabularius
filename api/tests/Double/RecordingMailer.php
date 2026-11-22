<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Records sent e-mails; optionally fails like an SMTP transport.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(private readonly ?TransportException $failure = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        \assert($message instanceof Email);
        $this->sent[] = $message;
    }

    public function bySubject(string $prefix): ?Email
    {
        foreach ($this->sent as $email) {
            if (str_starts_with((string) $email->getSubject(), $prefix)) {
                return $email;
            }
        }

        return null;
    }
}
