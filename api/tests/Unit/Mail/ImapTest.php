<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Module\Mail\Imap\ImapConnection;
use App\Module\Mail\Imap\ImapException;
use App\Module\Mail\Imap\ImapMailbox;
use App\Module\Mail\IncomingMessage;
use PHPUnit\Framework\TestCase;

/**
 * The IMAP client against a scripted server: the server's whole transcript is written to one end of a socket pair
 * before the client runs, and the client's commands are read back from it afterwards.
 */
final class ImapTest extends TestCase
{
    /** @var array{0: resource, 1: resource} */
    private array $pair;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        self::assertArrayHasKey(1, $pair);
        $this->pair = [$pair[0], $pair[1]];
    }

    public function testFetchesNewMessagesReadOnlyWithLiteralsAndHeadersOnlyForLargeOnes(): void
    {
        $body = "From: a@example.com\r\nSubject: Hej\r\n\r\nTreść {5}\r\n";
        $this->server(
            "* OK IMAP ready\r\n",
            "A1 OK LOGIN completed\r\n",
            "* 3 EXISTS\r\n* OK [UIDVALIDITY 777] UIDs valid\r\nA2 OK [READ-ONLY] EXAMINE completed\r\n",
            "* SEARCH 41 42 43\r\nA3 OK SEARCH completed\r\n",
            '* 2 FETCH (UID 42 RFC822.SIZE '.\strlen($body)." INTERNALDATE \"15-Nov-2026 09:41:00 +0000\")\r\nA4 OK\r\n",
            '* 2 FETCH (UID 42 BODY[] {'.\strlen($body)."}\r\n$body)\r\nA5 OK FETCH completed\r\n",
            "* 3 FETCH (UID 43 RFC822.SIZE 900000 INTERNALDATE \"15-Nov-2026 09:42:00 +0100\")\r\nA6 OK\r\n",
            "* 3 FETCH (UID 43 BODY[HEADER] {22}\r\nSubject: big\r\n\r\n\r\n\r\n)\r\nA7 OK\r\n",
            "* BYE\r\nA8 OK LOGOUT completed\r\n",
        );
        $mailbox = new ImapMailbox('imaps://tabularius%40gitpush.app:p%40ss%22w@imap.example.com/INBOX')->withConnector(fn () => ImapConnection::fromStream($this->pair[0]));

        $seenValidity = null;
        $messages = iterator_to_array($mailbox->fetch(static function (int $validity) use (&$seenValidity): int {
            $seenValidity = $validity;

            return 41;
        }, 10), false);

        self::assertSame(777, $seenValidity);
        self::assertCount(2, $messages);
        self::assertContainsOnlyInstancesOf(IncomingMessage::class, $messages);
        self::assertSame([42, $body, false, '2026-11-15T09:41:00+00:00'], [$messages[0]->uid, $messages[0]->raw, $messages[0]->headersOnly, $messages[0]->receivedAt->format(\DATE_ATOM)]);
        self::assertSame([43, true, 900000], [$messages[1]->uid, $messages[1]->headersOnly, $messages[1]->size]);
        self::assertSame('imap:tabularius@gitpush.app@imap.example.com/INBOX', $mailbox->name());

        self::assertSame(
            "A1 LOGIN \"tabularius@gitpush.app\" \"p@ss\\\"w\"\r\nA2 EXAMINE \"INBOX\"\r\nA3 UID SEARCH UID 42:*\r\n".
            "A4 UID FETCH 42 (RFC822.SIZE INTERNALDATE)\r\nA5 UID FETCH 42 (BODY.PEEK[])\r\n".
            "A6 UID FETCH 43 (RFC822.SIZE INTERNALDATE)\r\nA7 UID FETCH 43 (BODY.PEEK[HEADER])\r\nA8 LOGOUT\r\n",
            $this->clientSent(),
        );
    }

    public function testRefusedLoginNamesNoCredentials(): void
    {
        $this->server("* OK ready\r\n", "A1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n", "A2 OK\r\n");
        $mailbox = new ImapMailbox('imaps://user:secret-pass@imap.example.com')->withConnector(fn () => ImapConnection::fromStream($this->pair[0]));

        try {
            iterator_to_array($mailbox->fetch(static fn (): int => 0, 10));
            self::fail('expected an exception');
        } catch (ImapException $e) {
            self::assertSame('Mailbox login refused', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testSearchReturningOnlyOlderMessagesYieldsNothing(): void
    {
        $this->server("* OK ready\r\n", "A1 OK\r\n", "* OK [UIDVALIDITY 5]\r\nA2 OK\r\n", "* SEARCH 9\r\nA3 OK\r\n", "A4 OK\r\n");
        $mailbox = new ImapMailbox('imap://u:p@imap.example.com:143/Archiwum%20A')->withConnector(fn () => ImapConnection::fromStream($this->pair[0]));

        self::assertSame([], iterator_to_array($mailbox->fetch(static fn (): int => 9, 10)));
        self::assertStringContainsString("EXAMINE \"Archiwum A\"\r\nA3 UID SEARCH UID 10:*", $this->clientSent());
    }

    public function testProtocolErrors(): void
    {
        $this->server("* BYE go away\r\n");
        $this->expectException(ImapException::class);
        ImapConnection::fromStream($this->pair[0]);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function brokenServers(): iterable
    {
        yield 'refused EXAMINE' => [["* OK\r\n", "A1 OK\r\n", "A2 NO no such folder\r\n", "A3 OK\r\n"], 'The server refused EXAMINE'];
        yield 'no UIDVALIDITY' => [["* OK\r\n", "A1 OK\r\n", "A2 OK\r\n", "A3 OK\r\n"], 'The server did not report UIDVALIDITY'];
        yield 'incomplete FETCH' => [["* OK\r\n", "A1 OK\r\n", "* OK [UIDVALIDITY 1]\r\nA2 OK\r\n", "* SEARCH 2\r\nA3 OK\r\n", "* 1 FETCH (UID 2)\r\nA4 OK\r\n", "A5 OK\r\n"], 'Incomplete FETCH response'];
        yield 'bad INTERNALDATE' => [["* OK\r\n", "A1 OK\r\n", "* OK [UIDVALIDITY 1]\r\nA2 OK\r\n", "* SEARCH 2\r\nA3 OK\r\n", "* 1 FETCH (RFC822.SIZE 3 INTERNALDATE \"yesterday\")\r\nA4 OK\r\n", "A5 OK\r\n"], 'Invalid INTERNALDATE'];
        yield 'no body' => [["* OK\r\n", "A1 OK\r\n", "* OK [UIDVALIDITY 1]\r\nA2 OK\r\n", "* SEARCH 2\r\nA3 OK\r\n", "* 1 FETCH (RFC822.SIZE 3 INTERNALDATE \"15-Nov-2026 09:41:00 +0000\")\r\nA4 OK\r\n", "A5 OK\r\n", "A6 OK\r\n"], 'FETCH returned no body'];
        yield 'closed inside a literal' => [["* OK\r\n", "A1 OK\r\n", "* OK [UIDVALIDITY 1]\r\nA2 OK\r\n", "* SEARCH 2\r\nA3 OK\r\n", "* 1 FETCH (RFC822.SIZE 3 INTERNALDATE \"15-Nov-2026 09:41:00 +0000\")\r\nA4 OK\r\n", "* 1 FETCH (BODY[] {50}\r\nshort"], 'Mailbox connection closed inside a literal'];
        yield 'closed connection' => [["* OK\r\n"], 'Mailbox connection closed'];
    }

    /**
     * @param list<string> $transcript
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenServers')]
    public function testProtocolFailuresBecomeImapExceptions(array $transcript, string $message): void
    {
        $this->server(...$transcript);
        stream_socket_shutdown($this->pair[1], \STREAM_SHUT_WR);
        $mailbox = new ImapMailbox('imaps://u:p@imap.example.com')->withConnector(fn () => ImapConnection::fromStream($this->pair[0]));

        $this->expectExceptionObject(new ImapException($message));
        iterator_to_array($mailbox->fetch(static fn (): int => 0, 10));
    }

    public function testConnectionFailureAndUnsafeStrings(): void
    {
        try {
            ImapConnection::open('127.0.0.1', 1, 'ssl', 2.0);
            self::fail('expected an exception');
        } catch (ImapException $e) {
            self::assertStringStartsWith('Cannot connect to the mailbox server', $e->getMessage());
        }

        $this->server("* OK\r\n");
        $this->expectExceptionObject(new ImapException('Invalid characters in an IMAP string'));
        ImapConnection::fromStream($this->pair[0])->examine("INBOX\r\nA9 DELETE x");
    }

    public function testUnconfiguredAndInvalidDsn(): void
    {
        $none = new ImapMailbox('');
        self::assertFalse($none->configured());
        self::assertSame('none', $none->name());
        self::assertSame([], iterator_to_array($none->fetch(static fn (): int => 0, 1)));

        $this->expectException(\InvalidArgumentException::class);
        new ImapMailbox('pop3://u:p@host');
    }

    private function server(string ...$chunks): void
    {
        fwrite($this->pair[1], implode('', $chunks));
    }

    private function clientSent(): string
    {
        stream_set_blocking($this->pair[1], false);

        return (string) stream_get_contents($this->pair[1]);
    }
}
