<?php

namespace App\Services\Fulfillment\Support;

/**
 * The fingerprint shown beside a dry run's recorded request, so two requests can be told apart without reading both.
 *
 * It is keyed with the application key. The recorded request has its phone number masked on screen; a plain SHA-256 of the real
 * request next to it would let anyone who can read the page recover the number by hashing every candidate (a Saudi mobile has five
 * unknown digits). Without the key the fingerprint tells nothing about what it was made from.
 */
final class RequestFingerprint
{
    /** @param array<string, mixed> $payload */
    public static function of(array $payload): string
    {
        return hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), (string) config('app.key'));
    }
}
