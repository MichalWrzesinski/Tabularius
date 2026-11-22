<?php

declare(strict_types=1);

namespace App\Module\Mail;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * MIME → ParsedMessage. The text comes from the first text/plain part, or from the first text/html part converted
 * to text without fetching anything (MAIL-5); attachments are only counted. Automatic messages (auto-replies,
 * bounces, bulk) are recognized from their headers and structure (MAIL-2), never from the body.
 */
final class MessageParser
{
    private readonly MailMimeParser $parser;

    public function __construct(private readonly TextExtractor $text = new TextExtractor())
    {
        $this->parser = new MailMimeParser();
    }

    public function parse(string $raw): ParsedMessage
    {
        $message = $this->parser->parse($raw, false);
        $plain = $message->getTextContent();
        $text = null !== $plain ? $this->text->fromPlain($plain) : $this->text->fromHtml($message->getHtmlContent() ?? '');

        return new ParsedMessage(
            self::messageId($message),
            self::sender($message),
            self::clean($message->getSubject() ?? ''),
            self::headerBlock($raw),
            $text,
            $message->getAttachmentCount(),
            self::automatic($message),
        );
    }

    private static function messageId(IMessage $message): ?string
    {
        $id = trim((string) $message->getHeaderValue('Message-ID'), " <>\t");

        return '' === $id || \strlen($id) > 998 ? null : $id;
    }

    private static function sender(IMessage $message): ?string
    {
        $header = $message->getHeader('From');
        $address = $header instanceof AddressHeader ? $header->getEmail() : null;
        $address = null === $address ? '' : mb_strtolower(trim($address));

        return 1 === preg_match('/^[^@\s<>"]{1,64}@[a-z0-9.-]{1,253}\.[a-z]{2,63}$/', $address) ? $address : null;
    }

    private static function automatic(IMessage $message): bool
    {
        $auto = strtolower(trim((string) $message->getHeaderValue('Auto-Submitted')));
        if ('' !== $auto && 'no' !== $auto) {
            return true;
        }
        $precedence = strtolower(trim((string) $message->getHeaderValue('Precedence')));
        if (\in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }
        // Not X-Auto-Response-Suppress: some Outlook/Exchange setups add it to ordinary messages (a lost request).
        foreach (['X-Autoreply', 'X-Autorespond', 'X-Failed-Recipients'] as $name) {
            if (null !== $message->getHeader($name)) {
                return true;
            }
        }
        if ('<>' === trim((string) $message->getHeader('Return-Path')?->getRawValue())) {
            return true;
        }

        return 'multipart/report' === strtolower((string) $message->getContentType());
    }

    /** Raw header block (until the first empty line), for the trusted operator; bodies are never kept raw. */
    private static function headerBlock(string $raw): string
    {
        $end = strpos($raw, "\r\n\r\n");
        $end = false === $end ? strpos($raw, "\n\n") : $end;

        return mb_substr(false === $end ? $raw : substr($raw, 0, $end), 0, 16000);
    }

    private static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 300);
    }
}
