<?php

declare(strict_types=1);

namespace App\Module\Shared\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 Problem Details with a stable `code` (backend standard).
 */
final class Problem
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function response(int $status, string $code, string $title, array $extra = []): JsonResponse
    {
        return new JsonResponse(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'code' => $code] + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }
}
