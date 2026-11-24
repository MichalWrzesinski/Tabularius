<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Module\Monitoring\Scrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

final class ScrubberTest extends TestCase
{
    public function testAnApiEventLeavesWithoutPersonalDataOrSecrets(): void
    {
        $event = Event::createEvent();
        $event->setUser(UserDataBag::createFromUserIdentifier('jan'));
        $event->setRequest(['url' => 'https://tabularius.gitpush.app/api/v1/auth/password-reset?token=abc123&x=1', 'cookies' => ['PHPSESSID' => 's'], 'data' => ['password' => 'p'], 'headers' => ['Authorization' => 'Basic dXNlcjpwYXNz', 'User-Agent' => 'Firefox']]);
        $event->setMessage('Mail from jan.kowalski@example.com rejected');
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('An exception occurred while executing a query: SQLSTATE[23505] with params ["jan@example.com", "$argon2id$v=19$m=65536"]: duplicate'))]);
        $event->setExtra(['monolog.context' => ['text_body' => 'Usuń bazę', 'ticket' => '01a20981-f433-739c-bcdd-47e1b1120d6b', 'key' => 'sk-proj-'.str_repeat('a', 40)]]);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'mail', 'Sent to anna@example.com', ['recipient' => 'anna@example.com', 'subject' => 'Raport gotowy'])]);
        $event->setTags(['token' => 'Bearer eyJhbGciOi.eyJzdWIiOi.c2lnbmF0dXJl']);

        $out = (new Scrubber())($event);
        $json = json_encode([$out->getUser(), $out->getRequest(), $out->getMessage(), $out->getExceptions()[0]->getValue(), $out->getExtra(), $out->getBreadcrumbs()[0]->getMessage(), $out->getBreadcrumbs()[0]->getMetadata(), $out->getTags()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        foreach (['@example.com', 'abc123', 'PHPSESSID', 'dXNlcjpwYXNz', 'argon2', 'Usuń bazę', 'sk-proj', 'eyJhbGciOi', 'Raport gotowy', '"password"'] as $secret) {
            self::assertStringNotContainsString($secret, $json, $secret);
        }
        self::assertNull($out->getUser());
        self::assertStringContainsString('01a20981-f433-739c-bcdd-47e1b1120d6b', $json, 'identifiers stay for diagnosis');
        self::assertStringContainsString('SQLSTATE[23505]', $json);
        self::assertStringContainsString('Firefox', $json);
    }

    public function testABrowserEventDropsTheUserAndKeepsStructure(): void
    {
        $out = Scrubber::browserEvent([
            'event_id' => str_repeat('a', 32),
            'release' => str_repeat('f', 40),
            'user' => ['ip_address' => '1.2.3.4'],
            'request' => ['url' => 'https://x/moje-raporty?email=jan@example.com', 'cookies' => 'a=b'],
            'exception' => ['values' => [['type' => 'TypeError', 'value' => 'jan@example.com is not a function', 'stacktrace' => ['frames' => [['filename' => 'app.js', 'lineno' => 3]]]]]],
        ]);

        self::assertArrayNotHasKey('user', $out);
        self::assertSame(str_repeat('a', 32), $out['event_id']);
        self::assertSame(str_repeat('f', 40), $out['release']);
        self::assertSame(['url' => 'https://x/moje-raporty?email='.Scrubber::FILTERED], $out['request']);
        $json = json_encode($out, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('example.com', $json);
        self::assertStringContainsString('"lineno":3', $json);
    }
}
