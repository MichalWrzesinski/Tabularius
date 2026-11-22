<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Shared\Http\Problem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * 401/403 as Problem Details for the SPA (no redirects).
 */
final class ApiEntryPoint implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return Problem::response(401, 'unauthenticated', 'Login required');
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return Problem::response(403, 'forbidden', 'Access denied');
    }
}
