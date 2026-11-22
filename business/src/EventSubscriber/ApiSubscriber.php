<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\ApiException;
use App\Api\Problem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bearer-token check for /v1 (ADR 0002) and Problem Details for API errors.
 * An empty configured token rejects every request (fail closed).
 */
final class ApiSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(env: 'BUSINESS_API_TOKEN')]
        private readonly string $token,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['authenticate', 256],
            KernelEvents::EXCEPTION => 'renderProblem',
        ];
    }

    public function authenticate(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/v1/')) {
            return;
        }
        $header = (string) $request->headers->get('Authorization', '');
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        if ('' === $this->token || '' === $given || !hash_equals($this->token, $given)) {
            $event->setResponse(Problem::response(401, 'unauthorized', 'Missing or invalid API token'));
        }
    }

    public function renderProblem(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/v1/')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof ApiException) {
            $event->setResponse(Problem::response($e->status, $e->errorCode, $e->getMessage()));
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $event->setResponse(Problem::response($status, 404 === $status ? 'not_found' : (405 === $status ? 'method_not_allowed' : 'http_error'), 404 === $status ? 'Not found' : 'Request not allowed'));
        } else {
            $event->setResponse(Problem::response(500, 'internal_error', 'Internal error'));
        }
    }
}
