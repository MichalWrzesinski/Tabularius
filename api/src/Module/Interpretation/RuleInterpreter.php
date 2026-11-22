<?php

declare(strict_types=1);

namespace App\Module\Interpretation;

use App\Module\Report\Business\Customer;

/**
 * Rule-based baseline interpreter (brief §10): keyword rules over Polish text, folded to ASCII lower case. It closes
 * the e-mail loop without an LLM and is the baseline the LLM is evaluated against. When unsure it reports issue codes
 * instead of guessing; it never derives a recipient, a scope or a filter from anything but the profile and the
 * owner's own customers.
 */
final class RuleInterpreter implements Interpreter
{
    private const array OPERATIONS = [
        'customer_discount_simulation' => '/\b(rabat\w*|symulac\w*|aktywnosc\w* klient\w*)/',
        'parts_catalog' => '/\b(katalog\w*|cennik\w*|list\w* czesci|czesci producenta)/',
        'sales_report' => '/\b(sprzeda\w*|obrot\w*|faktur\w*)/',
    ];

    /** Requests outside the closed catalog: payroll, HR, deleting or changing data (AC-4). */
    private const string OUT_OF_CATALOG = '/\b(plac(e|owy|owa|owe|owego|owej|y)\b|list\w* plac|wynagrodz\w*|pensj\w*|kadrow\w*|usun\w*|skasuj\w*|wyczysc\w*|drop\b|delete\b|zmien\w* (cen|cene|ceny|rabat|umow)\w*)/';

    private const string RECIPIENT = '/(\bwysl\w* (na|do) (inn|ten|nastepuj)\w*|\binn\w* adres\w*|\bna adres\b|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/';

    /** @var array<int, list<string>> month number => folded forms (nominative, genitive, locative) */
    private const array MONTHS = [
        1 => ['styczen', 'stycznia', 'styczniu'], 2 => ['luty', 'lutego', 'lutym'], 3 => ['marzec', 'marca', 'marcu'],
        4 => ['kwiecien', 'kwietnia', 'kwietniu'], 5 => ['maj', 'maja', 'maju'], 6 => ['czerwiec', 'czerwca', 'czerwcu'],
        7 => ['lipiec', 'lipca', 'lipcu'], 8 => ['sierpien', 'sierpnia', 'sierpniu'], 9 => ['wrzesien', 'wrzesnia', 'wrzesniu'],
        10 => ['pazdziernik', 'pazdziernika', 'pazdzierniku'], 11 => ['listopad', 'listopada', 'listopadzie'],
        12 => ['grudzien', 'grudnia', 'grudniu'],
    ];

    private const array QUARTERS = ['1' => 1, '2' => 2, '3' => 3, '4' => 4, 'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'pierwszy' => 1, 'drugi' => 2, 'trzeci' => 3, 'czwarty' => 4];

    /** Words too common to identify a customer on their own. */
    private const array WEAK_WORDS = ['auto', 'serwis', 'warsztat', 'hurtownia', 'firma', 'sklep', 'motors', 'pod', 'sp', 'z', 'o', 'oo'];

    public function version(): string
    {
        return 'baseline:rules-v1';
    }

    public function interpret(string $text, InterpretationContext $context): InterpretedPlan
    {
        $t = self::fold($text);
        $issues = [];
        if (1 === preg_match(self::RECIPIENT, $t)) {
            $issues[] = 'recipient_change_requested';
        }

        $operations = [];
        foreach (self::OPERATIONS as $operation => $pattern) {
            if (1 === preg_match($pattern, $t)) {
                $operations[] = $operation;
            }
        }
        if (\in_array('customer_discount_simulation', $operations, true)) {
            $operations = array_values(array_diff($operations, ['sales_report'])); // a simulation is based on sales
        }
        $outOfCatalog = 1 === preg_match(self::OUT_OF_CATALOG, $t);

        if ($outOfCatalog && [] === $operations) {
            return new InterpretedPlan('out_of_catalog', null, [], [...$issues, 'out_of_catalog']);
        }
        if ($outOfCatalog || \count($operations) > 1) {
            return new InterpretedPlan('multiple_operations', null, [], [...$issues, 'multiple_operations']);
        }
        if ([] === $operations) {
            return new InterpretedPlan('unclear', null, [], [...$issues, 'unknown_operation']);
        }

        $operation = $operations[0];
        [$parameters, $found] = match ($operation) {
            'parts_catalog' => $this->catalog($t, $context),
            'customer_discount_simulation' => $this->discount($t, $context),
            default => $this->sales($t, $context),
        };

        return new InterpretedPlan('operation', $operation, $parameters, [...$issues, ...$found]);
    }

    /**
     * @return array{array<string, mixed>, list<string>}
     */
    private function sales(string $t, InterpretationContext $c): array
    {
        $issues = [];
        $parameters = [];
        $this->period($t, $c, $parameters, $issues);
        [$customers, $customerIssue] = $this->customers($t, $c);
        if (null !== $customerIssue) {
            $issues[] = $customerIssue;
        }
        $parameters['customers'] = [] === $customers ? 'all_assigned' : $customers;
        $manufacturers = $this->manufacturers($t, $c);
        if (\count($manufacturers) > 1) {
            $issues[] = 'ambiguous_manufacturer';
        } elseif (1 === \count($manufacturers)) {
            $parameters['manufacturer'] = $manufacturers[0];
        }
        $categories = array_values(array_filter($c->categories, static fn (string $category): bool => 1 === preg_match('/\b'.preg_quote(self::stem($category), '/').'/', $t)));
        if (\count($categories) > 1) {
            $issues[] = 'ambiguous_category';
        } elseif (1 === \count($categories)) {
            $parameters['category'] = $categories[0];
        }

        return [$parameters, $issues];
    }

    /**
     * @return array{array<string, mixed>, list<string>}
     */
    private function catalog(string $t, InterpretationContext $c): array
    {
        $manufacturers = $this->manufacturers($t, $c);

        return match (\count($manufacturers)) {
            0 => [[], ['missing_manufacturer']],
            1 => [['manufacturer' => $manufacturers[0]], []],
            default => [[], ['ambiguous_manufacturer']],
        };
    }

    /**
     * @return array{array<string, mixed>, list<string>}
     */
    private function discount(string $t, InterpretationContext $c): array
    {
        $issues = [];
        $parameters = [];
        [$customers, $customerIssue] = $this->customers($t, $c);
        if (null !== $customerIssue) {
            $issues[] = $customerIssue;
        } elseif (1 === \count($customers)) {
            $parameters['customer'] = $customers[0];
        } else {
            $issues[] = [] === $customers ? 'missing_customer' : 'ambiguous_customer';
        }
        $this->period($t, $c, $parameters, $issues);

        return [$parameters, $issues];
    }

    /**
     * Period expressions of the closed set (reports.md); the backend resolves them against the reference date.
     *
     * @param array<string, mixed> $parameters
     * @param list<string>         $issues
     */
    private function period(string $t, InterpretationContext $c, array &$parameters, array &$issues): void
    {
        $found = [];
        $year = 1 === preg_match('/\b(20\d\d)\b/', $t, $y) ? (int) $y[1] : null;
        $withYear = static fn (array $p): array => null === $year ? $p : $p + ['year' => $year];

        if (null !== ($range = self::explicitRange($t, $c->reference))) {
            $found['explicit'] = ['type' => 'explicit', 'start' => $range[0], 'end' => $range[1]];
        } else {
            $months = [];
            foreach (self::MONTHS as $number => $forms) {
                if (1 === preg_match('/\b('.implode('|', $forms).')\b/', $t)) {
                    $months[] = $number;
                }
            }
            if (\count($months) > 1) {
                $issues[] = 'ambiguous_period';

                return;
            }
            if (1 === \count($months)) {
                $found['month'] = $withYear(['type' => 'month', 'month' => $months[0]]);
            }
            if (1 === preg_match('/\b(?:q([1-4])|([1-4]|i{1,3}|iv|pierwszy|drugi|trzeci|czwarty)\s+kwartal\w*|kwartal\w*\s+([1-4]|i{1,3}|iv))\b/', $t, $q)) {
                $key = implode('', \array_slice($q, 1)); // exactly one of the alternatives matched
                $found['quarter'] = $withYear(['type' => 'quarter', 'quarter' => self::QUARTERS[$key] ?? 1]);
            }
        }
        $relative = [
            'yesterday' => '/\bwczoraj\b/',
            'previous_week' => '/\b(zeszl|poprzedni|ostatni|minion|ubiegl)\w* tydz\w*/',
            'previous_month' => '/\b(zeszl|poprzedni|ostatni|minion|ubiegl)\w* miesi\w*/',
            'current_month_to_date' => '/\b(ten|tym|biezac\w*|obecn\w*) miesi\w*/',
            'previous_year' => '/\b(zeszl|poprzedni|minion|ubiegl)\w* rok\w*|\bw zeszlym roku\b/',
            'current_year_to_date' => '/\bod poczatku roku\b/',
        ];
        foreach ($relative as $type => $pattern) {
            if (1 === preg_match($pattern, $t)) {
                $found[$type] = ['type' => $type];
            }
        }
        if (1 === preg_match('/\bostatni\w* (\d{1,3}) dni\b/', $t, $n)) {
            unset($found['previous_month']);
            $found['last_n_days'] = ['type' => 'last_n_days', 'n' => (int) $n[1]];
        }
        if ([] === $found && null !== $year && 1 === preg_match('/\b(za|rok|w|caly)\s+(rok\s+)?'.$year.'\b/', $t)) {
            $found['year'] = ['type' => 'year', 'year' => $year];
        }

        if (1 === \count($found)) {
            $parameters['period'] = array_values($found)[0];
        } else {
            $issues[] = [] === $found ? 'missing_period' : 'ambiguous_period';
        }
    }

    /**
     * „od 1 do 15 listopada [2026]”, „od 1 października do 15 listopada”, „2026-10-01 – 2026-10-15”, „1.10.2026-15.10.2026”.
     *
     * @return array{string, string}|null
     */
    private static function explicitRange(string $t, \DateTimeImmutable $reference): ?array
    {
        if (1 === preg_match('/\b(20\d\d-\d\d-\d\d)\s*(?:-|do)\s*(20\d\d-\d\d-\d\d)\b/', $t, $m)) {
            return [$m[1], $m[2]];
        }
        if (1 === preg_match('/\b(\d{1,2})\.(\d{1,2})(?:\.(20\d\d))?\s*(?:-|do)\s*(\d{1,2})\.(\d{1,2})\.(20\d\d)\b/', $t, $m)) {
            $startYear = '' !== $m[3] ? (int) $m[3] : (int) $m[6];

            return [\sprintf('%04d-%02d-%02d', $startYear, $m[2], $m[1]), \sprintf('%04d-%02d-%02d', $m[6], $m[5], $m[4])];
        }
        $month = '('.implode('|', array_merge(...array_values(self::MONTHS))).')';
        if (1 === preg_match('/\bod (\d{1,2})(?:\s+'.$month.')? do (\d{1,2})\s+'.$month.'(?:\s+(20\d\d))?\b/', $t, $m)) {
            $endMonth = self::monthNumber($m[4]);
            $startMonth = '' !== $m[2] ? self::monthNumber($m[2]) : $endMonth;
            $year = isset($m[5]) ? (int) $m[5] : (int) $reference->format('Y');

            return [\sprintf('%04d-%02d-%02d', $year, $startMonth, $m[1]), \sprintf('%04d-%02d-%02d', $year, $endMonth, $m[3])];
        }

        return null;
    }

    private static function monthNumber(string $form): int
    {
        foreach (self::MONTHS as $number => $forms) {
            if (\in_array($form, $forms, true)) {
                return $number;
            }
        }

        return 1;
    }

    /**
     * The owner's customers referred to by code or name. A name matches by its full form, by its first two words, or
     * by one distinctive word; a word matching several customers is ambiguous. „dla klienta X” with no match is an
     * unknown customer (never silently widened to all customers).
     *
     * @return array{list<string>, ?string}
     */
    private function customers(string $t, InterpretationContext $c): array
    {
        $codes = [];
        if (preg_match_all('/\b(k-\d{3})\b/', $t, $m) > 0) {
            foreach ($m[1] as $code) {
                $codes[] = strtoupper($code); // a code outside the scope is refused later, not dropped here
            }
        }
        $words = ' '.(string) preg_replace('/[^a-z0-9]+/', ' ', $t).' ';
        $full = [];
        $partial = [];
        foreach ($c->customers as $customer) {
            $tokens = self::nameTokens($customer);
            if ([] === $tokens) {
                continue;
            }
            if (str_contains($words, ' '.implode(' ', $tokens).' ') || (\count($tokens) >= 2 && str_contains($words, ' '.$tokens[0].' '.$tokens[1].' '))) {
                $full[] = $customer->code;
            }
            // Single words of every customer, including fully matched ones: „alfa” in „Alfa-Tech” must not add Alfa Motors.
            foreach ($tokens as $token) {
                if (\strlen($token) >= 4 && !\in_array($token, self::WEAK_WORDS, true) && str_contains($words, ' '.$token.' ')) {
                    $partial[$token][] = $customer->code;
                }
            }
        }
        $byName = $full;
        foreach ($partial as $matches) {
            if ([] !== array_intersect($matches, $full)) {
                continue; // the word belongs to a name already matched in full
            }
            if (\count($matches) > 1) {
                return [[], 'ambiguous_customer'];
            }
            $byName[] = $matches[0];
        }
        $all = array_values(array_unique([...$codes, ...$byName]));
        if ([] === $all && 1 === preg_match('/\b(klienta|klientowi|firmy)\b/', $t)) {
            return [[], 'unknown_customer'];
        }

        return [$all, null];
    }

    /**
     * @return list<string>
     */
    private function manufacturers(string $t, InterpretationContext $c): array
    {
        $words = ' '.(string) preg_replace('/[^a-z0-9+]+/', ' ', $t).' ';
        $found = [];
        foreach ($c->manufacturers as $manufacturer) {
            foreach ([$manufacturer->name, ...$manufacturer->aliases] as $name) {
                $key = trim((string) preg_replace('/[^a-z0-9+]+/', ' ', self::fold($name)));
                if ('' !== $key && str_contains($words, ' '.$key.' ')) {
                    $found[] = $manufacturer->code;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private static function nameTokens(Customer $customer): array
    {
        $name = (string) preg_replace('/\bsp\.?\s*z\s*o\.?\s*o\.?/', ' ', self::fold($customer->name));
        $tokens = array_values(array_filter(explode(' ', trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name))), static fn (string $w): bool => '' !== $w && !ctype_digit($w)));

        return $tokens;
    }

    /** Category stem for inflected forms: „Hamulce” → „hamulc” (hamulców, hamulcach…). */
    private static function stem(string $category): string
    {
        return (string) preg_replace('/[aeiouy]+$/', '', self::fold($category));
    }

    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', '–' => '-', '—' => '-']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
