<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Http\JsonResponse;

/**
 * The shape of every answer of the platform admin API: `{"data": ..., "meta": ...}` for what it gives and `{"errors": [{"code", "message"}]}` for
 * what it refuses. The code is the contract; the message is plain English for a person reading a log, and is never translated.
 */
final class PlatformAdminResponses
{
    /**
     * @param array<array-key, mixed> $meta
     */
    public static function data(mixed $data, array $meta = []): JsonResponse
    {
        return response()->json($meta === [] ? ['data' => $data] : ['data' => $data, 'meta' => $meta]);
    }

    /** @param array<string, mixed> $extra more members of the error, such as the codes a client could have sent */
    public static function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $message] + $extra]], $status);
    }

    /** The one refusal for every failure to authenticate, so that nothing tells which check failed or whether an account exists. */
    public static function unauthenticated(): JsonResponse
    {
        return self::error(code: 'platform_admin_auth', message: 'Authentication failed.', status: 401);
    }

    public static function throttled(int $retryAfterSeconds): JsonResponse
    {
        return self::error(code: 'platform_admin_throttled', message: 'Too many attempts. Try again later.', status: 429)->header('Retry-After', (string) $retryAfterSeconds);
    }

    public static function unavailable(): JsonResponse
    {
        return self::error(code: 'platform_admin_unavailable', message: 'The platform admin API is not available yet.', status: 503);
    }
}
