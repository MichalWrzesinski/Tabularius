<?php

declare(strict_types=1);

namespace App\Module\Interpretation\Guard;

/**
 * First layer of the business-scope guard (ADR 0005): refuses instructions outside the business concept before any
 * interpreter (rules or LLM) sees them. Tabularius only prepares reports from a closed catalog for the requester's
 * own scope; it never modifies or deletes data, reveals credentials, generates code or other files, takes new
 * instructions, or touches other people's data.
 *
 * The text is normalized against evasion first (NFKC, zero-width characters, diacritics, Cyrillic/Greek look-alikes,
 * letter-by-letter spacing, digit-for-letter substitution, URL and Base64 encoding). Matching errs on the side of
 * refusal; legitimate wording is protected by a test corpus.
 */
final class RequestScreen
{
    public const array CATEGORIES = ['data_modification', 'credential_access', 'code_generation', 'file_generation', 'system_manipulation', 'foreign_data'];

    /** @var array<string, list<string>> category => patterns over the folded text (ASCII, lower case, single spaces) */
    private const array RULES = [
        'system_manipulation' => [
            '/\b(z?ignoruj|olej|pomin|zapomnij|porzuc)\w* (wszystk\w* |poprzedni\w* |swoj\w* |dotychczasow\w* |powyzsz\w* )*(instrukcj|polecen|zasad|regul|ogranicze|wytyczn|prompt)/',
            '/\bignore (all |any |the |your |previous |prior |above |earlier )*(instruction|rule|prompt|guideline|restriction)/',
            '/\b(forget|disregard|override) (all |any |the |your |previous |prior |everything)/',
            '/\b(system\w* prompt|prompt\w* systemow|instrukcj\w* systemow|twoj\w* instrukcj|your instructions|initial instructions)/',
            '/\b(jestes teraz|od teraz jestes|udawaj|wciel sie|odegraj|you are now|act as|pretend to be|role ?play)\b/',
            '/\b(developer mode|tryb (deweloper|programist|administrator|serwis)\w*|jailbreak|dan mode|sudo mode|god mode)\b/',
            '/\b(nowe|nowa|kolejne) (instrukcj|polecen|zasad)\w*|\bnew (instruction|rule)s?\b/',
            '/\bjestem (admin|administrator|szef|kierownik|prezes|wlasciciel|programist|deweloper)\w*/',
            '/\bi am (the |an |a )?(admin|administrator|owner|developer|ceo)\b/',
        ],
        'data_modification' => [
            '/\b(usun|usunac|usuncie|skasuj|kasuj|wykasuj|wyczysc|wymaz|zniszcz|nadpisz|zmodyfikuj|zaktualizuj|edytuj|wyzeruj|zablokuj|odblokuj)\w*/',
            '/\bzmien\w* (\w+ ){0,2}(cen|rabat|limit|uprawnien|dan|baz|hasl|rekord|faktur|klient|status|umow|konto|kont|uzytkownik|przypisan|marz)\w*/',
            '/\b(dodaj|utworz|zaloz|stworz)\w* (\w+ ){0,2}(klient|faktur|rekord|uzytkownik|kont|uprawnien|rabat|cen|zamowieni|admin)\w*/',
            '/\b(delete|remove|erase|wipe|truncate|drop|update|insert|modify|overwrite|alter|destroy)\b/',
        ],
        'credential_access' => [
            '/\b(hasl|haslo|password|passwd|passphrase|credential|uwierzytelni)\w*/',
            '/\b(token|sekret|secret)\w*/',
            '/\b(klucz|klucze|key)\w* (api|dostep|prywatn|ssh|szyfr|tajn)\w*|\bapi ?key\b|\bprivate key\b/',
            '/\bdane (do )?logowania\b|\blogin (i|oraz) hasl|(^| )\.env\b|\benv\b|\bssh\b/',
        ],
        'code_generation' => [
            '/\b(napisz|wygeneruj|stworz|utworz|przygotuj|podaj|wyslij|daj|zrob|generate|write|create|produce|give)\w* (\w+ ){0,3}(kod|skrypt|program|aplikacj|funkcj|makr|zapytani\w* sql|sql|query|script|code|macro|function|regex)\w*/',
            '/\b(select\b.{1,80}\bfrom|drop table|insert into|union select|import os|subprocess)/',
            '/(<script|<\?php|<iframe|javascript:|exec\(|eval\(|rm -rf|\$\(|`)/',
            '/\b(python|php|javascript|bash|powershell|vba)\b/',
        ],
        'file_generation' => [
            '/\b(wygeneruj|stworz|utworz|przygotuj|wyslij|zrob|zapisz|generate|create|make|save|export|eksport)\w* (\w+ ){0,4}(pdf|xlsx|xls|docx|doc|zip|rar|exe|bat|sh|py|js|php|html|json|xml|txt|pptx)\b/',
            '/\bplik\w* (\w+ ){0,2}(pdf|xlsx|xls|docx|doc|zip|rar|exe|bat|sh|py|js|php|html|json|xml|pptx)\b/',
        ],
        'foreign_data' => [
            '/\b(plac(e|owy|owa|owe|owego|owej|y)\b|list\w* plac|wynagrodz|pensj|kadr|pesel|dane osobow|adres\w* (domow|zamieszkan)|stan\w* zdrowia)\w*/',
            '/\b(wszystk\w*|cal\w*) (\w+ ){0,1}(klient|handlow|pracown|uzytkownik|przedstawiciel|kont|baz|dan)\w* (w |z |calej )?(firm|system|baz)\w*/',
            '/\b(inn\w*|cudz\w*|pozostal\w*) (\w+ ){0,1}(handlow|przedstawiciel|pracown|uzytkownik|oddzial|region)\w*/',
            '/\b(wszystkich|kazdego) (handlowc|przedstawiciel|pracownik|uzytkownik)\w*|\blista (uzytkownik|kont|pracownik|handlowc)\w*/',
            '/\bph-\d{2}\b/',
            '/\b(all|every|other) (customers|users|employees|sales ?reps|representatives|accounts)\b|\bpayroll\b|\bsalar(y|ies)\b/',
            '/\bcala baz\w*|\bcalej baz\w*|\bwhole database\b|\bentire database\b/',
        ],
    ];

    /** Long, unambiguous keywords also searched with every separator removed („u-s-u-ń”, „h a s ł o”). */
    private const array COMPACT = [
        'skasuj' => 'data_modification', 'wyczysc' => 'data_modification', 'usunbaz' => 'data_modification', 'dropdatabase' => 'data_modification', 'droptable' => 'data_modification', 'truncate' => 'data_modification',
        'haslo' => 'credential_access', 'hasla' => 'credential_access', 'password' => 'credential_access', 'apikey' => 'credential_access',
        'zignoruj' => 'system_manipulation', 'ignoreprevious' => 'system_manipulation', 'ignoreall' => 'system_manipulation', 'systemprompt' => 'system_manipulation', 'jailbreak' => 'system_manipulation',
        'wynagrodz' => 'foreign_data', 'payroll' => 'foreign_data',
    ];

    private const array LOOKALIKES = [
        'а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's', 'ԁ' => 'd', 'ӏ' => 'l', 'ᴀ' => 'a',
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'η' => 'n', 'ι' => 'i', 'κ' => 'k', 'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'ω' => 'w',
    ];

    private const array LEET = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i'];

    /** The refusal category, or null when the text stays within the business concept. */
    public function screen(string $text): ?string
    {
        foreach ($this->variants($text) as $variant) {
            foreach (self::RULES as $category => $patterns) {
                foreach ($patterns as $pattern) {
                    if (1 === preg_match($pattern, $variant['folded'])) {
                        return $category;
                    }
                }
            }
            foreach (self::COMPACT as $keyword => $category) {
                if (str_contains($variant['compact'], $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * The text and anything hidden in it (URL-encoded or Base64 fragments), each normalized.
     *
     * @return list<array{folded: string, compact: string}>
     */
    private function variants(string $text): array
    {
        $texts = [$text, rawurldecode($text)];
        if (preg_match_all('/[A-Za-z0-9+\/]{16,}={0,2}/', $text, $m) > 0) {
            foreach ($m[0] as $candidate) {
                $decoded = base64_decode($candidate, true);
                if (false !== $decoded && mb_check_encoding($decoded, 'UTF-8') && 1 === preg_match('/^[\P{C}\s]+$/u', $decoded)) {
                    $texts[] = $decoded;
                }
            }
        }

        return array_map(self::normalize(...), array_values(array_unique($texts)));
    }

    /**
     * @return array{folded: string, compact: string}
     */
    public static function normalize(string $text): array
    {
        $nfkc = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $text = \is_string($nfkc) ? $nfkc : $text;
        $text = (string) preg_replace('/[\p{Cf}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
        $text = mb_strtolower($text);
        $text = strtr($text, self::LOOKALIKES);
        $text = strtr($text, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        $nfd = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $decomposed = \is_string($nfd) ? $nfd : $text;
        $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        // „u s u ń”, „h.a.s.ł.o”: single letters joined by one separator become one word.
        $text = (string) preg_replace_callback('/\b(?:\pL[\s.\-_*·]){2,}\pL\b/u', static fn (array $m): string => (string) preg_replace('/[\s.\-_*·]/u', '', $m[0]), $text);
        $folded = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\pL\pN@.\-<>?()\/ ]+/u', ' ', $text)));
        $compact = (string) preg_replace('/[^a-z]/', '', strtr($text, self::LEET));

        return ['folded' => $folded, 'compact' => $compact];
    }
}
