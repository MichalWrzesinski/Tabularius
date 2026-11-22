<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Module\Identity\AccountException;
use App\Module\Shared\Http\ApiSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, int, string}>
     */
    public static function errors(): iterable
    {
        yield 'account refusal' => [new AccountException('email_taken', 409), 409, 'email_taken'];
        yield 'not found' => [new NotFoundHttpException(), 404, 'not_found'];
        yield 'method' => [new MethodNotAllowedHttpException(['GET']), 405, 'method_not_allowed'];
        yield 'other http' => [new TooManyRequestsHttpException(), 429, 'http_error'];
        yield 'unexpected' => [new \LogicException('secret detail'), 500, 'internal_error'];
    }

    #[DataProvider('errors')]
    public function testRendersProblemsWithoutInternalDetails(\Throwable $error, int $status, string $code): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/x'), HttpKernelInterface::MAIN_REQUEST, $error);

        (new ApiSubscriber())->renderProblem($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('"code":"'.$code.'"', (string) $response->getContent());
        self::assertStringNotContainsString('secret detail', (string) $response->getContent());
    }

    public function testLeavesNonApiErrorsToTheFramework(): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/other'), HttpKernelInterface::MAIN_REQUEST, new \LogicException('x'));

        (new ApiSubscriber())->renderProblem($event);

        self::assertNull($event->getResponse());
    }
}
