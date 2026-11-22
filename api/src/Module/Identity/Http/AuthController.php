<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Account;
use App\Module\Identity\AccountService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    /** Handled by the json_login authenticator; reached only for a malformed request. */
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return new JsonResponse(['type' => 'about:blank', 'title' => 'Invalid login request', 'status' => 400, 'code' => 'bad_request'], 400, ['Content-Type' => 'application/problem+json']);
    }

    /** Handled by the firewall's logout listener. */
    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the firewall');
    }

    /**
     * Session probe for the SPA: 200 with `me: null` when nobody is logged in (no 401 noise on public pages).
     */
    #[Route('/session', name: 'api_session', methods: ['GET'])]
    public function session(#[CurrentUser] ?Account $account): JsonResponse
    {
        return new JsonResponse(['me' => null === $account ? null : MeView::of($account)]);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] Account $account): JsonResponse
    {
        return new JsonResponse(MeView::of($account));
    }

    #[Route('/password', name: 'api_password_change', methods: ['POST'])]
    public function changePassword(Request $request, #[CurrentUser] Account $account): Response
    {
        $body = self::json($request);
        $this->accounts->changePassword($account, self::str($body, 'currentPassword'), self::str($body, 'newPassword'), $request->getSession()->getId());

        return new Response(null, 204);
    }

    /**
     * Always 202, the same for unknown addresses; the reset runs after the response is sent (no timing hint).
     */
    #[Route('/password-reset', name: 'api_password_reset', methods: ['POST'])]
    public function requestReset(Request $request, EventDispatcherInterface $dispatcher): JsonResponse
    {
        $email = self::str(self::json($request), 'email');
        $dispatcher->addListener(KernelEvents::TERMINATE, function () use ($email): void {
            $this->accounts->resetPassword($email);
        });

        return new JsonResponse(['status' => 'accepted'], 202);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON');
        }

        return \is_array($body) ? $body : throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Expected a JSON object');
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function str(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? $value : throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Missing field '.$key);
    }
}
