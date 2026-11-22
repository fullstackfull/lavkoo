<?php

namespace App\Services\PlatformAdmin;

use App\Enums\PlatformAdmin\MfaMethod;
use App\Models\PlatformAdminMfaFactor;
use Illuminate\Support\Str;

/**
 * The check of a code, or of a recovery code, against the second factor of an admin, and the using up of what was right.
 *
 * It must be called with the factor locked, inside a transaction, because it reads the step of the last code accepted (or the recovery codes that were
 * used) and writes the new one: two requests with the same code would otherwise both see it unused. It is called exactly once for every attempt that gets
 * as far as a check, with no factor when there is nothing to check against (a challenge nobody was given, an admin who may not sign in): the same
 * codes are then computed against a secret and a list of hashes that belong to nobody, so that a refusal costs the same whatever the reason for it.
 *
 * Every comparison of a secret is made with hash_equals, over every candidate, whether or not one matched.
 */
class PlatformAdminMfaVerifier
{
    private const DUMMY_RECOVERY_HASHES = RecoveryCodes::COUNT;

    /** Made once per process. */
    private static ?string $dummySecret = null;

    /** @var list<array{hash: string, used_at: null}>|null */
    private static ?array $dummyRecoveryCodes = null;

    public function useCodeOf(MfaAttempt $attempt, ?PlatformAdminMfaFactor $factor): MfaVerdict
    {
        return $attempt->method === MfaMethod::RecoveryCode
            ? $this->useRecoveryCode(attempt: $attempt, factor: $factor)
            : $this->useTotp(attempt: $attempt, factor: $factor);
    }

    private function useTotp(MfaAttempt $attempt, ?PlatformAdminMfaFactor $factor): MfaVerdict
    {
        $secret = $factor?->secretBytes();
        $step = Totp::matchingStep(secret: $secret ?? $this->dummySecret(), code: $attempt->code, timestamp: now()->timestamp);

        if ($attempt->method !== MfaMethod::Totp) {
            return MfaVerdict::refused(reason: MfaVerdict::MALFORMED_INPUT);
        }

        if ($factor === null || $secret === null) {
            return MfaVerdict::refused(reason: MfaVerdict::UNUSABLE_FACTOR);
        }

        if ($step === null) {
            return MfaVerdict::refused(reason: MfaVerdict::WRONG_CODE);
        }

        if ($factor->last_used_step !== null && $step <= $factor->last_used_step) {
            return MfaVerdict::refused(reason: MfaVerdict::REPLAYED_CODE);
        }

        $factor->forceFill(['last_used_step' => $step])->save();

        return MfaVerdict::accepted(method: MfaMethod::Totp, recoveryCodesLeft: $factor->recoveryCodesLeft());
    }

    private function useRecoveryCode(MfaAttempt $attempt, ?PlatformAdminMfaFactor $factor): MfaVerdict
    {
        $candidate = RecoveryCodes::hash(normalized: $attempt->recoveryCode);
        $codes = $factor === null ? $this->dummyRecoveryCodes() : array_values((array) $factor->recovery_codes);
        $matched = null;

        foreach ($codes as $index => $stored) {
            if (hash_equals((string) ($stored['hash'] ?? ''), $candidate)) {
                $matched = $index;
            }
        }

        if ($factor === null) {
            return MfaVerdict::refused(reason: MfaVerdict::UNUSABLE_FACTOR);
        }

        if ($matched === null) {
            return MfaVerdict::refused(reason: MfaVerdict::WRONG_CODE);
        }

        if (($codes[$matched]['used_at'] ?? null) !== null) {
            return MfaVerdict::refused(reason: MfaVerdict::REPLAYED_CODE);
        }

        $codes[$matched]['used_at'] = now()->utc()->toIso8601ZuluString();
        $factor->forceFill(['recovery_codes' => $codes])->save();

        return MfaVerdict::accepted(method: MfaMethod::RecoveryCode, recoveryCodesLeft: $factor->recoveryCodesLeft());
    }

    private function dummySecret(): string
    {
        return self::$dummySecret ??= Totp::newSecret();
    }

    /** @return list<array{hash: string, used_at: null}> */
    private function dummyRecoveryCodes(): array
    {
        return self::$dummyRecoveryCodes ??= array_map(
            static fn (): array => ['hash' => hash('sha256', Str::random(RecoveryCodes::LENGTH)), 'used_at' => null],
            range(1, self::DUMMY_RECOVERY_HASHES),
        );
    }
}
