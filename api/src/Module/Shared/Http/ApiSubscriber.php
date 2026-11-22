<?php

declare(strict_types=1);

namespace App\Module\Shared\Http;

use App\Module\Shared\Refusal;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF defence for the cookie session (ADR 0003): every state-changing /api request must carry the custom header
 * `X-Tabularius-Request: 1` (a cross-site form cannot set it; CORS is not enabled) and must not be cross-site
 * according to Sec-Fetch-Site. Also renders API errors as Problem Details.
 */
final class ApiSubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'X-Tabularius-Request';

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the firewall (priority 8) so login and logout are covered too.
            KernelEvents::REQUEST => ['checkCsrf', 16],
            KernelEvents::EXCEPTION => ['renderProblem', -16],
        ];
    }

    public function checkCsrf(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || $request->isMethodSafe()) {
            return;
        }
        $site = $request->headers->get('Sec-Fetch-Site');
        if ('1' !== $request->headers->get(self::HEADER) || (null !== $site && !\in_array($site, ['same-origin', 'none'], true))) {
            $event->setResponse(Problem::response(403, 'csrf', 'Request rejected (missing same-origin request header)'));
        }
    }

    public function renderProblem(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/') || $event->hasResponse()) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof Refusal) {
            $event->setResponse(Problem::response($e->status, $e->reason, 'Request refused'));
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                400 => 'bad_request',
                404 => 'not_found',
                405 => 'method_not_allowed',
                default => 'http_error',
            };
            $event->setResponse(Problem::response($status, $code, 'Request not processed'));
        } else {
            $event->setResponse(Problem::response(500, 'internal_error', 'Internal error'));
        }
    }
}
