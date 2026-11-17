<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Http\Request;

/**
 * What a sign-in request sent, reduced to strings of a bounded size. Whatever was sent, the lookups and the password check that follow run
 * on these, and whether the attempt was well formed is decided only at the end, so a malformed attempt does the work of any other.
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
        $email = $request->input('email');
        $password = $request->input('password');
        $deviceName = $request->input('device_name');

        $wellFormed = is_string($email) && $email !== '' && strlen($email) <= self::EMAIL_LIMIT
            && is_string($password) && $password !== '' && strlen($password) <= self::PASSWORD_LIMIT
            && is_string($deviceName) && trim($deviceName) !== '' && mb_strlen($deviceName) <= self::DEVICE_NAME_LIMIT;

        return new self(
            email: is_string($email) ? substr($email, 0, self::EMAIL_LIMIT) : '',
            password: is_string($password) ? substr($password, 0, self::PASSWORD_LIMIT) : '',
            deviceName: is_string($deviceName) ? trim($deviceName) : '',
            wellFormed: $wellFormed,
            ip: (string) ($request->ip() ?? 'unknown'),
            userAgent: $request->userAgent(),
        );
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
