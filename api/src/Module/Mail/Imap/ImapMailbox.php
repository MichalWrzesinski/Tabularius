<?php

declare(strict_types=1);

namespace App\Module\Mail\Imap;

use App\Module\Mail\IncomingMessage;
use App\Module\Mail\MailboxSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The real mailbox over IMAP, configured by MAILBOX_DSN (`imaps://user:password@host:993/INBOX`, or `imap://…` with
 * STARTTLS; URL-encode special characters). Empty DSN = not configured (local stack and CI use `app:mail:inject`).
 * Messages above the size limit are fetched as headers only.
 */
final class ImapMailbox implements MailboxSource
{
    /** @var array{host: string, port: int, security: string, user: string, password: string, folder: string}|null */
    private readonly ?array $config;

    /** @var (\Closure(string, int, string): ImapConnection)|null */
    private ?\Closure $connector = null;

    public function __construct(
        #[Autowire(env: 'MAILBOX_DSN')]
        string $dsn,
        #[Autowire(env: 'int:MAIL_MAX_BYTES')]
        private readonly int $maxBytes = 262144,
    ) {
        $this->config = self::parse($dsn);
    }

    /**
     * @param \Closure(string, int, string): ImapConnection $connector (tests)
     */
    public function withConnector(\Closure $connector): self
    {
        $clone = clone $this;
        $clone->connector = $connector;

        return $clone;
    }

    public function configured(): bool
    {
        return null !== $this->config;
    }

    public function name(): string
    {
        return null === $this->config ? 'none' : \sprintf('imap:%s@%s/%s', $this->config['user'], $this->config['host'], $this->config['folder']);
    }

    public function fetch(\Closure $lastUid, int $limit): iterable
    {
        if (null === $this->config) {
            return;
        }
        $c = $this->config;
        $imap = null === $this->connector ? ImapConnection::open($c['host'], $c['port'], $c['security']) : ($this->connector)($c['host'], $c['port'], $c['security']);
        try {
            $imap->login($c['user'], $c['password']);
            $uidValidity = $imap->examine($c['folder']);
            foreach (\array_slice($imap->uidsAfter($lastUid($uidValidity)), 0, $limit) as $uid) {
                $meta = $imap->meta($uid);
                $headersOnly = $meta['size'] > $this->maxBytes;
                yield new IncomingMessage($this->name(), $uidValidity, $uid, $imap->body($uid, $headersOnly), $meta['size'], $meta['internalDate'], $headersOnly);
            }
        } finally {
            $imap->logout();
        }
    }

    /**
     * @return array{host: string, port: int, security: string, user: string, password: string, folder: string}|null
     */
    private static function parse(string $dsn): ?array
    {
        if ('' === trim($dsn)) {
            return null;
        }
        $parts = parse_url($dsn);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? null) : null;
        if (!\is_array($parts) || !\in_array($scheme, ['imap', 'imaps'], true) || !isset($parts['host'], $parts['user'], $parts['pass'])) {
            throw new \InvalidArgumentException('MAILBOX_DSN must look like imaps://user:password@host:993/INBOX');
        }
        $folder = trim(rawurldecode($parts['path'] ?? ''), '/');

        return [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? ('imaps' === $scheme ? 993 : 143),
            'security' => 'imaps' === $scheme ? 'ssl' : 'starttls',
            'user' => rawurldecode($parts['user']),
            'password' => rawurldecode($parts['pass']),
            'folder' => '' === $folder ? 'INBOX' : $folder,
        ];
    }
}
