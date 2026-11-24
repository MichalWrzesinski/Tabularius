<?php

declare(strict_types=1);

namespace App\Module\Monitoring\Http;

use App\Module\Monitoring\Scrubber;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dashboard error reporting (TASK-015). The browser never talks to Sentry: its events come here, are scrubbed like the
 * API's own ({@see Scrubber}) and forwarded to the DSN configured on the server, so the CSP stays `connect-src 'self'`.
 * Without a DSN monitoring is off and both endpoints answer 404.
 */
final class MonitoringController
{
    private const int MAX_BYTES = 200_000;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'SENTRY_DSN')]
        private readonly string $dsn,
        #[Autowire(env: 'SENTRY_RELEASE')]
        private readonly string $release,
        #[Autowire(env: 'SENTRY_ENVIRONMENT')]
        private readonly string $environment,
    ) {
    }

    #[Route('/api/monitoring/config', name: 'monitoring_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        if (null === $this->target()) {
            return new JsonResponse(['enabled' => false], 404);
        }

        return new JsonResponse(['enabled' => true, 'dsn' => $this->dsn, 'release' => $this->release, 'environment' => $this->environment]);
    }

    #[Route('/api/monitoring/envelope', name: 'monitoring_envelope', methods: ['POST'])]
    public function envelope(Request $request, RateLimiterFactoryInterface $monitoringTunnelLimiter): Response
    {
        $target = $this->target();
        if (null === $target) {
            return new Response('', 404);
        }
        if (!$monitoringTunnelLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('', 429);
        }
        $raw = (string) $request->getContent();
        if (\strlen($raw) > self::MAX_BYTES) {
            return new Response('', 413);
        }
        $events = $this->events($raw);
        if (null === $events) {
            return new Response('', 400);
        }
        if ([] === $events) {
            return new Response('', 204); // sessions, client reports: nothing worth forwarding
        }

        $body = '';
        foreach ($events as $event) {
            $payload = json_encode(Scrubber::browserEvent($event), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $body .= json_encode(['event_id' => $event['event_id'] ?? null, 'dsn' => $this->dsn], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n"
                .json_encode(['type' => 'event', 'length' => \strlen($payload)], \JSON_THROW_ON_ERROR)."\n".$payload."\n";
        }
        try {
            $status = $this->http->request('POST', $target['url'], [
                'headers' => [
                    'Content-Type' => 'application/x-sentry-envelope',
                    'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=tabularius-tunnel/1.0, sentry_key='.$target['key'],
                ],
                'body' => $body,
                'timeout' => 5,
            ])->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Browser error report not forwarded', ['exception_class' => $e::class]);

            return new Response('', 502);
        }

        return new Response('', $status < 300 ? 200 : 502);
    }

    /** @return array{url: string, key: string}|null the configured project's envelope endpoint */
    private function target(): ?array
    {
        $parts = parse_url(trim($this->dsn));
        if (!\is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || !isset($parts['host'], $parts['user'], $parts['path'])
            || 1 !== preg_match('#^/(\d+)$#', $parts['path'], $m)) {
            return null;
        }

        return ['url' => \sprintf('https://%s%s/api/%s/envelope/', $parts['host'], isset($parts['port']) ? ':'.$parts['port'] : '', $m[1]), 'key' => $parts['user']];
    }

    /**
     * Event items of an envelope for the configured project; null when the envelope is malformed or for another one.
     *
     * @return list<array<array-key, mixed>>|null
     */
    private function events(string $raw): ?array
    {
        $newline = strpos($raw, "\n");
        $header = json_decode(false === $newline ? $raw : substr($raw, 0, $newline), true);
        if (!\is_array($header) || ($header['dsn'] ?? null) !== $this->dsn) {
            return null;
        }
        $events = [];
        $offset = false === $newline ? \strlen($raw) : $newline + 1;
        while ($offset < \strlen($raw)) {
            $end = strpos($raw, "\n", $offset);
            $item = json_decode(substr($raw, $offset, false === $end ? null : $end - $offset), true);
            if (!\is_array($item) || !\is_string($item['type'] ?? null)) {
                return null;
            }
            $offset = false === $end ? \strlen($raw) : $end + 1;
            if (isset($item['length'])) {
                if (!\is_int($item['length']) || $item['length'] < 0 || $offset + $item['length'] > \strlen($raw)) {
                    return null;
                }
                $payload = substr($raw, $offset, $item['length']);
                $offset += $item['length'] + 1;
            } else {
                $end = strpos($raw, "\n", $offset);
                $payload = substr($raw, $offset, false === $end ? null : $end - $offset);
                $offset = false === $end ? \strlen($raw) : $end + 1;
            }
            if ('event' === $item['type']) {
                $event = json_decode($payload, true);
                if (!\is_array($event)) {
                    return null;
                }
                $events[] = $event;
            }
        }

        return $events;
    }
}
