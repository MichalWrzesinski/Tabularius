<?php

declare(strict_types=1);

namespace App\Module\Mail;

/**
 * The new part of a message as plain text (tickets.md: quoted history is stripped before interpretation). HTML is
 * reduced to text with the DOM, never rendered and never fetched; scripts, styles and images are dropped.
 */
final class TextExtractor
{
    /** Lines that introduce quoted history in common clients (Polish and English). */
    private const array QUOTE_HEADERS = [
        '/^W dniu .{4,120}(napisał|napisała|napisał\(a\)|pisze):?\s*$/iu',
        '/^On .{4,160} wrote:\s*$/iu',
        '/^-{2,}\s*(Original Message|Wiadomość oryginalna|Oryginalna wiadomość)\s*-{2,}\s*$/iu',
        '/^(From|Od):\s.*@.*$/u', // a quoted header block (Outlook style) starts with the original sender's address
    ];

    public function fromPlain(string $text): string
    {
        $lines = preg_split('/\R/u', str_replace("\u{FEFF}", '', $text)) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trimmed = rtrim($line);
            if ('-- ' === $line || '--' === $trimmed) {
                break; // signature
            }
            foreach (self::QUOTE_HEADERS as $pattern) {
                if (1 === preg_match($pattern, trim($trimmed))) {
                    break 2;
                }
            }
            if (str_starts_with(ltrim($trimmed), '>')) {
                continue;
            }
            $kept[] = $trimmed;
        }

        return self::normalize(implode("\n", $kept));
    }

    public function fromHtml(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach (['script', 'style', 'head', 'img', 'blockquote'] as $tag) {
            $nodes = iterator_to_array($document->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (['br', 'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3'] as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->insertBefore($document->createTextNode("\n"), $node);
            }
        }

        return $this->fromPlain($document->textContent);
    }

    private static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $text = (string) preg_replace("/[ \t\u{00A0}]+/u", ' ', $text);
        $text = (string) preg_replace("/ *\n */", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
