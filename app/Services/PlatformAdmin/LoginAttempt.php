<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Http\Request;

/**
 * What a sign-in request sent in its body, reduced to strings of a bounded size. Whatever was sent, the lookups and the password check that
 * follow run on these, and whether the attempt was well formed is decided only at the end, so a malformed attempt does the work of any other.
 *
 * Only the body counts, a form or JSON: a password in an address ends up in the access logs of every proxy it passes. Text that is not valid
 * UTF-8 is not an e-mail or a device name, and is never stored.
 */
final readonly class LoginAttempt
{
    private const EMAIL_LIMIT = 254;

    private const PASSWORD_LIMIT = 1024;

    private const DEVICE_NAME_LIMIT = 100;

    private function __construct(
        public string $email,
        public string $password,
        public string $deviceName,
        public bool $wellFormed,
        public string $ip,
        public ?string $userAgent,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $body = $request->request->all();
        $email = self::textOf($body['email'] ?? null);
        $password = is_string($body['password'] ?? null) ? $body['password'] : null;
        $deviceName = self::textOf($body['device_name'] ?? null);

        $wellFormed = $email !== null && $email !== '' && strlen($email) <= self::EMAIL_LIMIT
            && $password !== null && $password !== '' && strlen($password) <= self::PASSWORD_LIMIT
            && $deviceName !== null && trim($deviceName) !== '' && mb_strlen($deviceName) <= self::DEVICE_NAME_LIMIT;

        return new self(
            email: substr((string) $email, 0, self::EMAIL_LIMIT),
            password: substr((string) $password, 0, self::PASSWORD_LIMIT),
            deviceName: trim((string) $deviceName),
            wellFormed: $wellFormed,
            ip: (string) ($request->ip() ?? 'unknown'),
            userAgent: $request->userAgent(),
        );
    }

    /** The value when it is a string of valid UTF-8, and null for anything else. */
    private static function textOf(mixed $value): ?string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : null;
    }

    /** A short fingerprint of the address, for a log line or a limiter key that must not hold the address itself. */
    public function emailFingerprint(): string
    {
        return substr(hash('sha256', strtolower($this->email)), 0, 12);
    }

    /** The full hash of the address together with where it came from: the key one address from one place is limited by. */
    public function pairFingerprint(): string
    {
        return hash('sha256', strtolower($this->email) . '|' . $this->ip);
    }
}
