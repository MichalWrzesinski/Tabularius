<?php

declare(strict_types=1);

namespace App\Module\Mail\Imap;

/**
 * Minimal IMAP4rev1 client for the commands ingestion needs (RFC 3501): LOGIN, EXAMINE (read-only), UID SEARCH,
 * UID FETCH and LOGOUT. TLS is mandatory (implicit TLS or STARTTLS, certificate verified). Literals ({n}) are read
 * byte-exact. Credentials never appear in exceptions or logs.
 */
final class ImapConnection
{
    private int $tag = 0;

    /**
     * @param resource $stream
     */
    private function __construct(private $stream)
    {
    }

    public static function open(string $host, int $port, string $security, float $timeout = 30.0): self
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $scheme = 'ssl' === $security ? 'ssl' : 'tcp';
        $stream = @stream_socket_client("$scheme://$host:$port", $errno, $error, $timeout, \STREAM_CLIENT_CONNECT, $context);
        if (false === $stream) {
            throw new ImapException(\sprintf('Cannot connect to the mailbox server (%d)', $errno));
        }
        stream_set_timeout($stream, (int) $timeout);
        $connection = new self($stream);
        $connection->greeting();
        if ('starttls' === $security) {
            $connection->command('STARTTLS');
            if (true !== @stream_socket_enable_crypto($stream, true, \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new ImapException('STARTTLS negotiation failed');
            }
        }

        return $connection;
    }

    /**
     * @param resource $stream an already connected stream (tests)
     */
    public static function fromStream($stream): self
    {
        $connection = new self($stream);
        $connection->greeting();

        return $connection;
    }

    public function login(string $user, string $password): void
    {
        try {
            $this->command('LOGIN '.self::quote($user).' '.self::quote($password));
        } catch (ImapException $e) {
            // A refusal is reported as such; transport errors keep their own message. Neither names the credentials.
            throw str_starts_with($e->getMessage(), 'The server refused') ? new ImapException('Mailbox login refused') : $e;
        }
    }

    /** Opens the folder read-only and returns its UIDVALIDITY. */
    public function examine(string $folder): int
    {
        $response = $this->command('EXAMINE '.self::quote($folder));
        foreach ($response['lines'] as $line) {
            if (1 === preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                return (int) $m[1];
            }
        }

        throw new ImapException('The server did not report UIDVALIDITY');
    }

    /**
     * UIDs greater than `$after`, ascending (`n:*` also returns the last message, so it is filtered).
     *
     * @return list<int>
     */
    public function uidsAfter(int $after): array
    {
        $uids = [];
        foreach ($this->command(\sprintf('UID SEARCH UID %d:*', $after + 1))['lines'] as $line) {
            if (1 === preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $uid) {
                    if (ctype_digit($uid) && (int) $uid > $after) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        sort($uids);

        return array_values(array_unique($uids));
    }

    /**
     * @return array{size: int, internalDate: \DateTimeImmutable}
     */
    public function meta(int $uid): array
    {
        $line = implode(' ', $this->command(\sprintf('UID FETCH %d (RFC822.SIZE INTERNALDATE)', $uid))['lines']);
        if (1 !== preg_match('/RFC822\.SIZE (\d+)/i', $line, $size) || 1 !== preg_match('/INTERNALDATE "([^"]+)"/i', $line, $date)) {
            throw new ImapException('Incomplete FETCH response');
        }
        $internal = \DateTimeImmutable::createFromFormat('d-M-Y H:i:s O', trim($date[1]));
        if (false === $internal) {
            throw new ImapException('Invalid INTERNALDATE');
        }

        return ['size' => (int) $size[1], 'internalDate' => $internal];
    }

    /** The whole message, or only its header (`$headersOnly`), without setting \Seen (BODY.PEEK). */
    public function body(int $uid, bool $headersOnly): string
    {
        $literals = $this->command(\sprintf('UID FETCH %d (BODY.PEEK[%s])', $uid, $headersOnly ? 'HEADER' : ''))['literals'];

        return $literals[0] ?? throw new ImapException('FETCH returned no body');
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (ImapException) {
            // closing anyway
        }
        fclose($this->stream);
    }

    /**
     * @return array{lines: list<string>, literals: list<string>}
     */
    private function command(string $command): array
    {
        $tag = 'A'.++$this->tag;
        $this->write("$tag $command\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            while (1 === preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->readBytes((int) $m[1]);
                $line .= ' '.$this->readLine();
            }
            if (str_starts_with($line, "$tag ")) {
                if (1 !== preg_match('/^'.$tag.' OK\b/i', $line)) {
                    throw new ImapException(\sprintf('The server refused %s', strtok($command, ' ')));
                }

                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = $line;
        }
    }

    private function greeting(): void
    {
        $line = $this->readLine();
        if (1 !== preg_match('/^\* (OK|PREAUTH)\b/i', $line)) {
            throw new ImapException('Unexpected server greeting');
        }
    }

    private function write(string $data): void
    {
        if (false === @fwrite($this->stream, $data)) {
            throw new ImapException('Cannot write to the mailbox server');
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->stream, 65536);
        if (false === $line) {
            throw new ImapException(stream_get_meta_data($this->stream)['timed_out'] ? 'Mailbox server timed out' : 'Mailbox connection closed');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (\strlen($data) < $length) {
            $chunk = fread($this->stream, max(1, min(65536, $length - \strlen($data))));
            if (false === $chunk || '' === $chunk) {
                throw new ImapException('Mailbox connection closed inside a literal');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function quote(string $value): string
    {
        if (1 === preg_match('/[\r\n\x00]/', $value)) {
            throw new ImapException('Invalid characters in an IMAP string');
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
