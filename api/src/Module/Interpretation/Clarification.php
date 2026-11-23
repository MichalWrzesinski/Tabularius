<?php

declare(strict_types=1);

namespace App\Module\Interpretation;

use App\Module\Report\Profile\IntegrationProfile;

/**
 * Clarification questions (TKT-CLAR) built from issue codes only: the model chooses codes, never the outgoing text.
 * Choices come from trusted context in the requester's scope (their customers, the manufacturers, the catalog) and
 * example dates from the reference date. Never report data, never the request's own text.
 */
final class Clarification
{
    public const int MAX_ROUNDS = 3;

    private const int MAX_CHOICES = 15;

    private const array MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /** Issue code → [group, the sentence that explains it]. One question per group. */
    private const array ISSUES = [
        'missing_period' => ['period', 'Za jaki okres przygotować raport?'],
        'ambiguous_period' => ['period', 'Podano więcej niż jeden okres. Wybierz jeden okres (inne raporty wyślij osobnymi wiadomościami).'],
        'invalid_period' => ['period', 'Nie rozpoznaliśmy okresu.'],
        'period_includes_today' => ['period', 'Okres nie może obejmować dnia dzisiejszego ani przyszłości.'],
        'no_completed_period' => ['period', 'Wskazany okres jeszcze się nie zakończył.'],
        'period_too_early' => ['period', 'Wskazany okres jest wcześniejszy niż dostępne dane.'],
        'period_too_long' => ['period', 'Okres jest za długi (najwyżej 366 dni).'],
        'start_after_end' => ['period', 'Początek okresu jest późniejszy niż jego koniec.'],
        'missing_customer' => ['customer', 'Którego klienta dotyczy prośba?'],
        'ambiguous_customer' => ['customer', 'Nazwa pasuje do więcej niż jednego Twojego klienta. Którego wybrać?'],
        'unknown_customer' => ['customer', 'Nie znaleźliśmy takiego klienta wśród Twoich klientów.'],
        'invalid_customers' => ['customer', 'Nie rozpoznaliśmy klientów.'],
        'missing_manufacturer' => ['manufacturer', 'Którego producenta dotyczy katalog?'],
        'ambiguous_manufacturer' => ['manufacturer', 'Nazwa pasuje do więcej niż jednego producenta. Którego wybrać?'],
        'unknown_manufacturer' => ['manufacturer', 'Nie znaleźliśmy takiego producenta.'],
        'ambiguous_category' => ['category', 'Której kategorii części dotyczy prośba?'],
        'unknown_category' => ['category', 'Nie znaleźliśmy takiej kategorii części.'],
        'multiple_operations' => ['operation', 'Prośba dotyczy kilku raportów naraz. Wybierz jeden (kolejne wyślij osobnymi wiadomościami).'],
        'unknown_operation' => ['operation', 'Nie rozpoznaliśmy, jakiego raportu potrzebujesz.'],
    ];

    public function __construct(private readonly IntegrationProfile $profile)
    {
    }

    /** Whether a code can be answered by the requester (otherwise the ticket goes to the administrator). */
    public static function answerable(string $code): bool
    {
        return isset(self::ISSUES[$code]);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public function questions(array $codes, InterpretationContext $context): array
    {
        $groups = [];
        foreach ($codes as $code) {
            if (isset(self::ISSUES[$code])) {
                [$group, $sentence] = self::ISSUES[$code];
                $groups[$group] ??= [];
                $groups[$group][] = $sentence;
            }
        }
        $out = [];
        foreach ($groups as $group => $sentences) {
            $out[] = implode(' ', array_values(array_unique($sentences))).' '.$this->choices($group, $context);
        }

        return [] === $out ? ['Uzupełnij, proszę, szczegóły prośby: jaki raport, dla kogo i za jaki okres.'] : $out;
    }

    private function choices(string $group, InterpretationContext $context): string
    {
        return match ($group) {
            'period' => $this->periodExamples($context->reference),
            'customer' => 'Twoi klienci: '.self::list(array_map(static fn ($c): string => $c->name, array_values(array_filter($context->customers, static fn ($c): bool => $c->active)))).'.',
            'manufacturer' => 'Dostępni producenci: '.self::list(array_map(static fn ($m): string => $m->name, $context->manufacturers)).'.',
            'category' => 'Kategorie: '.self::list($context->categories).'.',
            default => 'Dostępne raporty: '.self::list(array_map(static fn ($o): string => mb_strtolower($o->label), array_values($this->profile->operations))).'.',
        };
    }

    private function periodExamples(\DateTimeImmutable $reference): string
    {
        $yesterday = $reference->modify('-1 day');
        $previous = $reference->modify('first day of previous month');
        $start = $reference->modify('first day of this month');
        $range = $start < $yesterday
            ? \sprintf(', „od %s do %s”', $start->format('j.m.Y'), $yesterday->format('j.m.Y'))
            : '';

        return \sprintf(
            'Na przykład: „za %s %s”, „za ostatni tydzień”%s. Okres musi się kończyć najpóźniej %s.',
            self::MONTHS[(int) $previous->format('n')],
            $previous->format('Y'),
            $range,
            $yesterday->format('j.m.Y'),
        );
    }

    /**
     * @param list<string> $items
     */
    private static function list(array $items): string
    {
        if ([] === $items) {
            return '—';
        }
        $shown = \array_slice($items, 0, self::MAX_CHOICES);
        $more = \count($items) - \count($shown);

        return implode(', ', $shown).($more > 0 ? \sprintf(' i %d innych', $more) : '');
    }
}
