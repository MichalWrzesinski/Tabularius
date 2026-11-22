<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Module\Identity\Account;
use App\Module\Identity\AccountMailer;
use App\Module\Identity\Role;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class AccountMailerTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];

    private function mailer(string $mode): AccountMailer
    {
        $transport = $this->createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (RawMessage $message, ?Envelope $envelope = null): void {
            \assert($message instanceof Email);
            $this->sent[] = $message;
        });

        return new AccountMailer($transport, $mode, 'tabularius@gitpush.app', 'https://tabularius.example/');
    }

    private static function account(): Account
    {
        return new Account('anna@example.com', 'Anna <b>K</b>', Role::Rep, 'PH-02', 'x', new \DateTimeImmutable());
    }

    public function testDemoModeSendsAccountMailToTheCaptureTransport(): void
    {
        $this->mailer('demo')->sendNewAccount(self::account(), 'Abc123defGhi4567');

        self::assertSame('capture', $this->sent[0]->getHeaders()->getHeaderBody('X-Transport'));
    }

    public function testProductionModeDeliversToTheEmployee(): void
    {
        $this->mailer('production')->sendPasswordReset(self::account(), 'Abc123defGhi4567');
        $mail = $this->sent[0];

        self::assertFalse($mail->getHeaders()->has('X-Transport'));
        self::assertSame('anna@example.com', $mail->getTo()[0]->getAddress());
        self::assertSame('tabularius@gitpush.app', $mail->getFrom()[0]->getAddress());
        self::assertSame('Nowe hasło do Tabularius', $mail->getSubject());
        self::assertStringContainsString('Nowe hasło: Abc123defGhi4567', (string) $mail->getTextBody());
        self::assertStringContainsString('https://tabularius.example/logowanie', (string) $mail->getTextBody());
    }

    public function testHtmlPartIsEscaped(): void
    {
        $account = new Account('x@example.com', 'X', Role::Rep, 'PH-02', 'x', new \DateTimeImmutable());
        $this->mailer('production')->sendNewAccount($account, '<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', (string) $this->sent[0]->getHtmlBody());
        self::assertStringContainsString('&lt;script&gt;', (string) $this->sent[0]->getHtmlBody());
    }
}
