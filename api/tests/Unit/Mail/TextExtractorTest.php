<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Module\Mail\TextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextExtractorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plain(): iterable
    {
        yield 'signature' => ["Sprzedaż za październik\n-- \nAnna, tel. 600", 'Sprzedaż za październik'];
        yield 'polish quote header' => ["Za październik.\n\nW dniu 15.11.2026 o 10:45, Tabularius <t@x.pl> napisał:\n> Podaj okres", 'Za październik.'];
        yield 'english quote header' => ["October please\nOn Sun, 15 Nov 2026 at 10:45 Tabularius wrote:\n> period?", 'October please'];
        yield 'outlook header' => ["Bosch\n\nOd: Tabularius <tabularius@gitpush.app>\nWysłano: …", 'Bosch'];
        yield 'quoted lines only' => ["> stara treść\nNowa treść", 'Nowa treść'];
        yield 'from without address stays' => ['Od 1 do 15 listopada: sprzedaż', 'Od 1 do 15 listopada: sprzedaż'];
        yield 'whitespace and controls' => ["  Raport\u{0007}  \t sprzedaży \n\n\n\n Bosch  ", "Raport sprzedaży\n\nBosch"];
    }

    #[DataProvider('plain')]
    public function testPlainText(string $input, string $expected): void
    {
        self::assertSame($expected, new TextExtractor()->fromPlain($input));
    }

    public function testHtmlIsReducedToTextWithoutActiveOrRemoteContent(): void
    {
        $text = new TextExtractor()->fromHtml('<html><head><title>x</title><style>p{}</style></head><body><script>alert(1)</script><p>Wyślij <b>katalog</b></p><img src="https://t.example/p.gif" alt="pixel"><br>Bosch<blockquote>stara wiadomość</blockquote></body></html>');

        self::assertSame("Wyślij katalog\nBosch", $text);
        self::assertSame('', new TextExtractor()->fromHtml('   '));
    }
}
