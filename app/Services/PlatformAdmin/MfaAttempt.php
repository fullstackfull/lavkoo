<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use Illuminate\Http\Request;

/**
 * What a request to the second step sent in its body, reduced to strings of a bounded size and a known shape. Whatever was sent, the lookups and the
 * check of a code that follow run on these, and whether the attempt was well formed is decided only at the end, so a malformed attempt does the work of
 * any other and costs the caller as much.
 *
 * Only the body counts, a form or JSON. The attempt is for a code or for a recovery code, never both and never neither; if it is neither or both, or the
 * one it is for is not the shape of one, it has no method and carries a placeholder to be checked in place of it.
 */
final readonly class MfaAttempt
{
    private const CHALLENGE_SHAPE = '/^lkpc_[A-Za-z0-9]{48}\z/';

    private const CHALLENGE_LIMIT = 64;

    private const CODE_LIMIT = 32;

    private const RECOVERY_CODE_LIMIT = 64;

    private const CODE_SHAPE = '/^[0-9]{6}\z/';

    /** A code and a recovery code that no admin has: what a malformed attempt is checked as. */
    private const PLACEHOLDER_CODE = '000000';

    private const PLACEHOLDER_RECOVERY_CODE = 'aaaaaaaaaa';

    private function __construct(
        public string $challenge,
        public ?MfaMethod $method,
        public string $code,
        public string $recoveryCode,
        public bool $wellFormed,
        public string $ip,
        public ?string $userAgent,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $body = $request->request->all();
        $challenge = self::textOf(value: $body['challenge'] ?? null);
        $code = self::textOf(value: $body['code'] ?? null);
        $recoveryCode = self::textOf(value: $body['recovery_code'] ?? null);

        $digits = $code === null ? null : preg_replace('/\s+/', '', substr($code, 0, self::CODE_LIMIT));
        $normalized = $recoveryCode === null ? null : RecoveryCodes::normalized(typed: substr($recoveryCode, 0, self::RECOVERY_CODE_LIMIT));

        $method = match (true) {
            $code !== null && $recoveryCode === null && $digits !== null && preg_match(self::CODE_SHAPE, $digits) === 1 => MfaMethod::Totp,
            $recoveryCode !== null && $code === null && $normalized !== null => MfaMethod::RecoveryCode,
            default => null,
        };
        $challengeText = substr((string) $challenge, 0, self::CHALLENGE_LIMIT);

        return new self(
            challenge: $challengeText,
            method: $method,
            code: $method === MfaMethod::Totp ? (string) $digits : self::PLACEHOLDER_CODE,
            recoveryCode: $method === MfaMethod::RecoveryCode ? (string) $normalized : self::PLACEHOLDER_RECOVERY_CODE,
            wellFormed: $method !== null && preg_match(self::CHALLENGE_SHAPE, $challengeText) === 1,
            ip: (string) ($request->ip() ?? 'unknown'),
            userAgent: $request->userAgent(),
        );
    }

    /** A short fingerprint of the challenge, for a log line that must not hold it. */
    public function challengeFingerprint(): string
    {
        return substr(hash('sha256', $this->challenge), 0, 12);
    }

    /** The value when it is a string of valid UTF-8, and null for anything else. */
    private static function textOf(mixed $value): ?string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : null;
    }
}
