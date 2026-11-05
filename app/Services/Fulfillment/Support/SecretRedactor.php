<?php

namespace App\Services\Fulfillment\Support;

/**
 * Removes credentials, and partially masks personal data, from anything about to be logged or shown.
 *
 * Applied to every outbound request/response summary and every stored webhook payload, so that
 * `provider_api_logs` and the admin screens that read it can never leak a password, token or secret —
 * and hold only a masked phone/e-mail for the customer.
 */
final class SecretRedactor
{
    public const MASK = '***';

    /** Any key containing one of these (case-insensitive, punctuation ignored) is fully redacted. */
    private const SECRET_FRAGMENTS = [
        'password', 'passwd', 'secret', 'authorization', 'accesstoken', 'refreshtoken', 'apikey', 'bearer',
        'privatekey', 'credential', 'signature',
    ];

    private const SECRET_EXACT = ['token', 'key', 'pass', 'pwd', 'jwt', 'username'];

    private const PHONE_KEYS = ['phone', 'mobile', 'receiverphone', 'receiverphone2', 'senderphone', 'senderphone2', 'driverphone', 'customerphone'];

    private const EMAIL_KEYS = ['email', 'receiveremail', 'customeremail'];

    public static function redact(mixed $data): mixed
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = is_string($key) ? self::redactValue($key, $value) : self::redact($value);
            }

            return $result;
        }

        return is_string($data) ? self::redactString($data) : $data;
    }

    public static function redactString(string $text): string
    {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer ' . self::MASK, $text) ?? $text;

        // JWT-shaped values (three base64url segments, the first starting "eyJ").
        return preg_replace('/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/', self::MASK, $text) ?? $text;
    }

    public static function truncate(?string $text, int $maxBytes): ?string
    {
        if ($text === null || strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes) . '…[truncated]';
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 3 ? self::MASK : str_repeat('*', strlen($digits) - 3) . substr($digits, -3);
    }

    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return self::MASK;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    private static function redactValue(string $key, mixed $value): mixed
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));

        if (self::isSecretKey($normalized)) {
            return is_scalar($value) || $value === null ? self::MASK : self::MASK;
        }

        if (is_scalar($value) && in_array($normalized, self::PHONE_KEYS, true) && $value !== '') {
            return self::maskPhone((string) $value);
        }

        if (is_string($value) && in_array($normalized, self::EMAIL_KEYS, true) && $value !== '') {
            return self::maskEmail($value);
        }

        return self::redact($value);
    }

    private static function isSecretKey(string $normalized): bool
    {
        // "token", "api_token", "auth_token", "csrf-token"… but not "token_expires_at".
        if (in_array($normalized, self::SECRET_EXACT, true) || str_ends_with($normalized, 'token')) {
            return true;
        }

        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
