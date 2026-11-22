<?php

declare(strict_types=1);

namespace App\Module\Identity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Account e-mails with generated passwords (ACC-LIFE). Sent directly (not through a stored outbox) so the
 * password never lands in the database, logs or audit. In demo mode they go to the capture transport instead of
 * the recipient (ID-DEMO-5): the public administrator must not send passwords to arbitrary addresses.
 */
final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
        #[Autowire(env: 'PUBLIC_BASE_URL')]
        private readonly string $baseUrl,
    ) {
    }

    public function sendNewAccount(Account $account, string $password): void
    {
        $this->send($account, 'Twoje konto w Tabularius', [
            'Dzień dobry,',
            'administrator założył dla Ciebie konto w Tabularius. Dzięki niemu odbierzesz raporty zamówione e-mailem.',
            'Login: '.$account->email(),
            'Hasło: '.$password,
            'Zaloguj się: '.rtrim($this->baseUrl, '/').'/logowanie',
            'Po zalogowaniu możesz zmienić hasło. Jeśli nie spodziewasz się tej wiadomości, poinformuj administratora.',
        ]);
    }

    public function sendPasswordReset(Account $account, string $password): void
    {
        $this->send($account, 'Nowe hasło do Tabularius', [
            'Dzień dobry,',
            'na Twoją prośbę wygenerowaliśmy nowe hasło. Poprzednie hasło i otwarte sesje przestały działać.',
            'Login: '.$account->email(),
            'Nowe hasło: '.$password,
            'Zaloguj się: '.rtrim($this->baseUrl, '/').'/logowanie',
            'Jeśli to nie Twoja prośba, poinformuj administratora.',
        ]);
    }

    /**
     * @param list<string> $paragraphs
     */
    private function send(Account $account, string $subject, array $paragraphs): void
    {
        $email = (new Email())
            ->from(new Address($this->from, 'Tabularius'))
            ->to(new Address($account->email(), $account->name()))
            ->subject($subject)
            ->text(implode("\n\n", $paragraphs))
            ->html('<p>'.implode('</p><p>', array_map(static fn (string $p): string => htmlspecialchars($p, \ENT_QUOTES | \ENT_HTML5), $paragraphs)).'</p>');
        if ('demo' === $this->mode) {
            $email->getHeaders()->addTextHeader('X-Transport', 'capture');
        }
        $this->mailer->send($email);
    }
}
