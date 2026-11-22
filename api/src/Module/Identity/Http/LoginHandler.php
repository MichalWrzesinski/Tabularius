<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Account;
use App\Module\Identity\AccountService;
use App\Module\Shared\Http\Problem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * JSON login result. The same 401 for unknown, wrong-password, blocked and deleted accounts (no enumeration);
 * 429 after too many attempts.
 */
final class LoginHandler implements AuthenticationSuccessHandlerInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $account = $token->getUser();
        \assert($account instanceof Account);
        $this->accounts->recordLogin($account);

        return new JsonResponse(MeView::of($account));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return Problem::response(429, 'too_many_attempts', 'Too many login attempts, try again later');
        }

        return Problem::response(401, 'invalid_credentials', 'Invalid e-mail or password');
    }
}
