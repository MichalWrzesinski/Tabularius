<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Module\Mail\MessageParser;
use PHPUnit\Framework\TestCase;

final class MessageParserTest extends TestCase
{
    public function testPlainRequest(): void
    {
        $m = new MessageParser()->parse(self::fixture('plain-request'));

        self::assertSame('req-001@example.com', $m->messageId);
        self::assertSame('anna.kowalska@example.com', $m->sender, 'normalized to lower case');
        self::assertSame('Sprzedaż za październik', $m->subject);
        self::assertSame("Dzień dobry,\n\nproszę o zestawienie sprzedaży za październik dla moich klientów.\n\nDziękuję,\nAnna", $m->text);
        self::assertFalse($m->automatic);
        self::assertStringStartsWith('From: Anna Kowalska', $m->headerBlock);
        self::assertStringNotContainsString('proszę', $m->headerBlock);
    }

    public function testAttachmentsAreCountedNotRead(): void
    {
        $m = new MessageParser()->parse(self::fixture('with-attachment'));

        self::assertSame(1, $m->attachmentCount);
        self::assertSame('Proszę o katalog Bosch, szczegóły w załączniku.', $m->text);
    }

    public function testAutomaticMessagesAreRecognizedFromHeaders(): void
    {
        $parser = new MessageParser();
        self::assertTrue($parser->parse(self::fixture('auto-reply'))->automatic);
        self::assertTrue($parser->parse(self::fixture('bounce'))->automatic);
        foreach (["Precedence: bulk\r\n", "X-Autoreply: yes\r\n", "Return-Path: <>\r\n", "Auto-Submitted: auto-generated\r\n"] as $header) {
            self::assertTrue($parser->parse($header."From: a@example.com\r\nSubject: x\r\n\r\ntext")->automatic, trim($header));
        }
        self::assertFalse($parser->parse("Auto-Submitted: no\r\nFrom: a@example.com\r\n\r\ntext")->automatic);
        self::assertFalse($parser->parse("X-Auto-Response-Suppress: All\r\nFrom: a@example.com\r\n\r\nRaport sprzedaży")->automatic, 'set by some Outlook setups on ordinary mail');
    }

    public function testMissingOrInvalidIdentifiers(): void
    {
        $m = new MessageParser()->parse("From: \"Spoof\" <not an address>\r\nSubject: \x01Hej\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nTreść");

        self::assertNull($m->messageId);
        self::assertNull($m->sender);
        self::assertSame('Hej', $m->subject);
        self::assertSame('Treść', $m->text);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/mail/'.$name.'.eml');
    }
}
