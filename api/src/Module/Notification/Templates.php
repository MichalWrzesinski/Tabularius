<?php

declare(strict_types=1);

namespace App\Module\Notification;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backend templates (Polish) for every outgoing message (TKT-CLAR: the model never writes outgoing text). They never
 * contain report data or the request's own text; values are ticket numbers and links built here, and every value is
 * HTML-escaped in the HTML part, which has no remote content (MAIL-4).
 */
final class Templates
{
    private const array REFUSALS = [
        'out_of_catalog' => 'Prośba wykracza poza katalog dostępnych raportów: raport sprzedaży, katalog części producenta i symulacja rabatu dla klienta.',
        'out_of_scope' => 'Prośba dotyczy danych spoza Twojego zakresu, dlatego nie możemy jej zrealizować.',
        'admin_rejected' => 'Prośba została odrzucona przez administratora.',
        'outside_business_scope' => 'Tabularius przygotowuje wyłącznie raporty z katalogu (raport sprzedaży, katalog części producenta, symulacja rabatu) dla Twoich klientów. Nie zmienia ani nie usuwa danych, nie udostępnia haseł ani danych dostępowych i nie tworzy kodu ani innych plików.',
    ];

    public function __construct(
        #[Autowire(env: 'PUBLIC_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
    ) {
    }

    /**
     * @param array{number: string, ticketId: string, fileId?: string, reason?: ?string} $v
     *
     * @return array{subject: string, text: string, html: string}
     */
    public function render(string $template, array $v): array
    {
        $base = rtrim($this->baseUrl, '/');
        [$subject, $paragraphs] = match ($template) {
            'received' => [
                \sprintf('Przyjęliśmy Twoją prośbę (%s)', $v['number']),
                ['Dzień dobry,', \sprintf('przyjęliśmy Twoją prośbę i nadaliśmy jej numer %s. Gdy raport będzie gotowy, wyślemy link do niego.', $v['number'])],
            ],
            'confirmation_request' => [
                \sprintf('Potwierdź prośbę %s', $v['number']),
                [
                    'Dzień dobry,',
                    'otrzymaliśmy prośbę o raport wysłaną z Twojego adresu. Zanim go przygotujemy, zaloguj się i potwierdź, że to Twoja prośba.',
                    'Potwierdź: '.$base.'/moje-raporty/potwierdz/'.$v['ticketId'],
                    'Jeśli to nie Ty, wybierz „To nie ja” — nic nie zostanie przygotowane.',
                ],
            ],
            'report_ready' => [
                \sprintf('Raport gotowy (%s)', $v['number']),
                [
                    'Dzień dobry,',
                    'Twój raport jest gotowy. Ze względów bezpieczeństwa nie dołączamy go do wiadomości: jest dostępny po zalogowaniu przez 7 dni.',
                    'Otwórz raport: '.$base.'/moje-raporty/'.($v['fileId'] ?? ''),
                ],
            ],
            'refused' => [
                \sprintf('Nie możemy zrealizować prośby (%s)', $v['number']),
                ['Dzień dobry,', self::REFUSALS[$v['reason'] ?? ''] ?? 'Prośba nie może zostać zrealizowana.'],
            ],
            default => throw new \InvalidArgumentException('Unknown template '.$template),
        };
        $paragraphs[] = 'demo' === $this->mode
            ? 'Tabularius — wersja demonstracyjna na danych syntetycznych. Nie wysyłaj danych poufnych.'
            : 'Tabularius — wiadomość wysłana automatycznie.';

        return [
            'subject' => $subject,
            'text' => implode("\n\n", $paragraphs),
            'html' => '<!doctype html><html lang="pl"><body>'.implode('', array_map(
                static fn (string $p): string => '<p>'.htmlspecialchars($p, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'</p>',
                $paragraphs,
            )).'</body></html>',
        ];
    }
}
