<?php

declare(strict_types=1);

namespace App\Tests\Functional\Monitoring;

use App\Module\Monitoring\Http\MonitoringController;
use App\Module\Monitoring\Scrubber;
use App\Tests\Functional\Identity\IdentityTestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * TASK-015: dashboard errors reach Sentry only through the API's tunnel, scrubbed, and only to the configured project.
 */
final class MonitoringTest extends IdentityTestCase
{
    private const string DSN = 'https://publickey@o1.ingest.sentry.io/4242';

    /** @var list<array{url: string, body: string, headers: list<string>}> */
    private array $forwarded = [];

    public function testWithoutADsnMonitoringIsOff(): void
    {
        self::assertSame(404, $this->api('GET', '/api/monitoring/config'));
        self::assertSame(404, $this->post($this->envelope()));
    }

    public function testTheApiSdkScrubsEveryEventAndSendsNoPersonalData(): void
    {
        $options = self::getContainer()->get(HubInterface::class)->getClient()?->getOptions();
        self::assertNotNull($options);
        self::assertInstanceOf(Scrubber::class, $options->getBeforeSendCallback());
        self::assertFalse($options->shouldSendDefaultPii());
        self::assertNull($options->getDsn(), 'off without SENTRY_DSN');
    }

    public function testConfigTellsTheDashboardWhatToUse(): void
    {
        $this->enable();
        self::assertSame(200, $this->api('GET', '/api/monitoring/config'));
        self::assertSame(['enabled' => true, 'dsn' => self::DSN, 'release' => str_repeat('c', 40), 'environment' => 'staging'], $this->body());
    }

    public function testABrowserEventIsScrubbedAndForwardedToTheConfiguredProject(): void
    {
        $this->enable();
        self::assertSame(200, $this->post($this->envelope(extra: "\n".'{"type":"session"}'."\n".'{"sid":"x"}')));

        self::assertCount(1, $this->forwarded);
        self::assertSame('https://o1.ingest.sentry.io/api/4242/envelope/', $this->forwarded[0]['url']);
        self::assertContains('X-Sentry-Auth: Sentry sentry_version=7, sentry_client=tabularius-tunnel/1.0, sentry_key=publickey', $this->forwarded[0]['headers']);
        $lines = explode("\n", $this->forwarded[0]['body']);
        self::assertSame(['event_id' => 'e1', 'dsn' => self::DSN], json_decode($lines[0], true));
        self::assertSame(['type' => 'event', 'length' => \strlen($lines[2])], json_decode($lines[1], true), 'only the event is forwarded, not the session');
        self::assertStringNotContainsString('example.com', $this->forwarded[0]['body']);
        self::assertStringNotContainsString('1.2.3.4', $this->forwarded[0]['body']);
        self::assertStringContainsString('TypeError', $this->forwarded[0]['body']);
    }

    public function testInvalidEnvelopesAreRejected(): void
    {
        $this->enable();
        self::assertSame(400, $this->post('not json'));
        self::assertSame(400, $this->post($this->envelope(dsn: 'https://other@o1.ingest.sentry.io/1')), 'another project');
        self::assertSame(400, $this->post('{"dsn":"'.self::DSN.'"}'."\n".'{"type":"event","length":9999}'."\n{}"));
        self::assertSame(413, $this->post($this->envelope(extra: "\n".'{"type":"attachment"}'."\n".str_repeat('x', 210_000))));
        self::assertSame(204, $this->post('{"dsn":"'.self::DSN.'"}'."\n".'{"type":"client_report"}'."\n{}"));
        self::assertSame(403, $this->api('POST', '/api/monitoring/envelope', csrf: false), 'same-origin header required');
        self::assertSame([], $this->forwarded);
    }

    public function testTheTunnelIsRateLimited(): void
    {
        $this->enable();
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(200, $this->post($this->envelope()));
        }
        self::assertSame(429, $this->post($this->envelope()));
        self::assertCount(30, $this->forwarded);
    }

    private function enable(): void
    {
        $this->client->disableReboot();
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->forwarded[] = ['url' => $url, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : '', 'headers' => self::strings($options['headers'] ?? [])];

            return new MockResponse('{}', ['http_code' => 200]);
        });
        self::getContainer()->set(MonitoringController::class, new MonitoringController($http, new NullLogger(), self::DSN, str_repeat('c', 40), 'staging'));
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function envelope(string $dsn = self::DSN, string $extra = ''): string
    {
        $event = json_encode(['event_id' => 'e1', 'user' => ['ip_address' => '1.2.3.4'], 'exception' => ['values' => [['type' => 'TypeError', 'value' => 'jan@example.com failed']]]], \JSON_THROW_ON_ERROR);

        return json_encode(['event_id' => 'e1', 'dsn' => $dsn], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n".'{"type":"event"}'."\n".$event.$extra;
    }

    private function post(string $raw): int
    {
        $this->client->request('POST', '/api/monitoring/envelope', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_X_TABULARIUS_REQUEST' => '1'], content: $raw);

        return $this->client->getResponse()->getStatusCode();
    }
}
