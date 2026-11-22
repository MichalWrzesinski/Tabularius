<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\ApiSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiSubscriberTest extends TestCase
{
    public function testEmptyConfiguredTokenRejectsEveryRequest(): void
    {
        $request = Request::create('/v1/meta', server: ['HTTP_AUTHORIZATION' => 'Bearer ']);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        (new ApiSubscriber(''))->authenticate($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    public function testUnexpectedErrorsBecomeProblemsWithoutDetails(): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/v1/meta'), HttpKernelInterface::MAIN_REQUEST, new \LogicException('secret detail'));

        (new ApiSubscriber('t'))->renderProblem($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
        self::assertStringNotContainsString('secret detail', (string) $event->getResponse()->getContent());
    }

    public function testNonApiPathsAreLeftAlone(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new RequestEvent($kernel, Request::create('/health'), HttpKernelInterface::MAIN_REQUEST);
        $exception = new ExceptionEvent($kernel, Request::create('/health'), HttpKernelInterface::MAIN_REQUEST, new \LogicException('x'));
        $subscriber = new ApiSubscriber('t');

        $subscriber->authenticate($request);
        $subscriber->renderProblem($exception);

        self::assertNull($request->getResponse());
        self::assertNull($exception->getResponse());
    }
}
