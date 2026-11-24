<?php

declare(strict_types=1);

namespace App\Module\Monitoring;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;

/**
 * Error reports carry no personal data, passwords, tokens or mail contents (operations standard, TASK-015). Applied to
 * every API event (`before_send`) and to every browser event the tunnel forwards:
 * - values under sensitive keys are replaced whole (passwords, tokens, cookies, sessions, addresses, mail texts);
 * - strings are cleaned of e-mail addresses, bearer/JWT tokens, long opaque secrets and SQL parameters;
 * - the user, cookies and request bodies are never sent.
 */
final class Scrubber
{
    public const string FILTERED = '[Filtered]';

    private const string SENSITIVE_KEY = '/pass|secret|token|auth|cookie|session|e-?mail|phone|dsn|csrf|jwt|api[-_]?key|signature|sender|recipient|address|header_block|(?:^|[_.-])(?:text|body|subject|content)(?:$|[_.-])/i';

    /** Identifiers of the event itself, never user data. */
    private const array STRUCTURAL = ['release', 'environment', 'event_id', 'trace_id', 'span_id', 'parent_span_id', 'debug_id', 'debug_meta', 'sdk', 'platform', 'timestamp', 'start_timestamp', 'level', 'logger', 'type', 'mechanism', 'filename', 'abs_path', 'module', 'function', 'lineno', 'colno', 'in_app'];

    /** @var array<string, string> pattern → replacement */
    private const array PATTERNS = [
        // Doctrine/PDO messages repeat the statement's parameters.
        '/with params \[.*?\](?=:|\s|$)/s' => 'with params ['.self::FILTERED.']',
        '/DETAIL:.*$/m' => 'DETAIL: '.self::FILTERED,
        '/[^\s@<>()\[\]"\',;:=\/]+@[^\s@<>()\[\]"\',;:=]+\.[^\s@<>()\[\]"\',;:=]+/u' => self::FILTERED,
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i' => self::FILTERED,
        '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => self::FILTERED,
        '/((?:token|code|password|email|key|secret)=)[^&#\s]+/i' => '$1'.self::FILTERED,
        // Long opaque strings (keys, reset tokens, Message-ID tokens); canonical UUIDs are identifiers and stay.
        '/(?<![A-Za-z0-9_-])(?![0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![A-Za-z0-9_-]))[A-Za-z0-9_-]{32,}(?![A-Za-z0-9_-])/' => self::FILTERED,
    ];

    public function __invoke(Event $event, ?EventHint $hint = null): Event
    {
        $event->setUser(null);
        $request = $event->getRequest();
        unset($request['cookies'], $request['data'], $request['env']);
        $event->setRequest(self::map($request));
        if (null !== $event->getMessage()) {
            $event->setMessage(self::text($event->getMessage()), array_map(static fn (string $p): string => self::text($p), $event->getMessageParams()), null === $event->getMessageFormatted() ? null : self::text($event->getMessageFormatted()));
        }
        $event->setExceptions(array_map(static function (ExceptionDataBag $e): ExceptionDataBag {
            $e->setValue(self::text($e->getValue()));
            foreach ($e->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars(self::map($frame->getVars()));
            }

            return $e;
        }, $event->getExceptions()));
        $event->setExtra(self::map($event->getExtra()));
        $event->setTags(array_map(static fn (string $v): string => self::text($v), $event->getTags()));
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::map($context));
        }
        $event->setBreadcrumb(array_map(static fn (Breadcrumb $b): Breadcrumb => new Breadcrumb(
            $b->getLevel(),
            $b->getType(),
            $b->getCategory(),
            null === $b->getMessage() ? null : self::text($b->getMessage()),
            self::map($b->getMetadata()),
            $b->getTimestamp(),
        ), $event->getBreadcrumbs()));

        return $event;
    }

    /**
     * A browser event (decoded JSON from the tunnel): the same rules, and no user, cookies or request body.
     *
     * @param array<array-key, mixed> $event
     *
     * @return array<array-key, mixed>
     */
    public static function browserEvent(array $event): array
    {
        unset($event['user']);
        if (\is_array($event['request'] ?? null)) {
            unset($event['request']['cookies'], $event['request']['data']);
        }

        return self::array($event);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public static function array(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = self::value($value, \is_string($key) ? $key : null);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function map(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = self::value($value, $key);
        }

        return $out;
    }

    public static function value(mixed $value, ?string $key = null): mixed
    {
        if (null !== $key && \in_array($key, self::STRUCTURAL, true)) {
            return \is_array($value) ? self::array($value) : $value;
        }
        if (null !== $key && 1 === preg_match(self::SENSITIVE_KEY, $key)) {
            return null === $value ? null : self::FILTERED;
        }

        return match (true) {
            \is_array($value) => self::array($value),
            \is_string($value) => self::text($value),
            default => $value,
        };
    }

    public static function text(string $value): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }
}
