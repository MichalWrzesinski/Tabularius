<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 Problem Details with a stable machine-readable `code` (backend standard).
 */
final class Problem
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function response(int $status, string $code, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'code' => $code];
        if (null !== $detail) {
            $body['detail'] = $detail;
        }

        return new JsonResponse($body + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }
}
