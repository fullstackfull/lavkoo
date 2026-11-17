<?php

namespace App\Services\PlatformAdmin;

use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signing in as a platform admin: the admin's own e-mail and password, checked against the admins table without starting a session, and an
 * active grant made for that admin row. Whatever is wrong, the caller learns only that it was not accepted, and the work done is the same:
 * one lookup of the admin, one check of the password (against a dummy hash when there is no such admin) and one lookup of the grant.
 */
final class PlatformAdminAuthenticator
{
    private const DUMMY_PASSWORD_LENGTH = 40;

    /** Made once per process, with the same cost as every hash made here, so that an e-mail nobody uses is checked as slowly as one somebody does. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Hasher $hasher,
        private readonly PlatformAdminTokenService $tokens,
        private readonly PlatformAdminAudit $audit,
        private readonly PlatformAdminLoginFailures $failures,
    ) {
    }

    public function authenticate(LoginAttempt $attempt): ?PlatformAdminSession
    {
        $admin = Admin::query()->select(['id', 'name', 'status', 'password', 'created_at'])->where('email', $attempt->email)->first();
        $passwordMatches = $this->hasher->check($attempt->password, $admin === null ? $this->dummyHash() : (string) $admin->getAuthPassword());
        $grant = PlatformAdminGrant::query()->where('admin_id', $admin?->getKey() ?? 0)->first();

        $reason = $this->reasonToRefuse(attempt: $attempt, admin: $admin, passwordMatches: $passwordMatches, grant: $grant);

        if ($reason !== null) {
            $this->failures->afterTheAnswer(reason: $reason, attempt: $attempt, grant: $grant);

            return null;
        }

        return DB::transaction(fn (): PlatformAdminSession => $this->openSession(attempt: $attempt, admin: $admin, grant: $grant));
    }

    private function reasonToRefuse(LoginAttempt $attempt, ?Admin $admin, bool $passwordMatches, ?PlatformAdminGrant $grant): ?string
    {
        return match (true) {
            !$attempt->wellFormed => 'malformed_input',
            $admin === null => 'unknown_admin',
            !$passwordMatches => 'wrong_password',
            (int) $admin->getRawOriginal('status') !== 1 => 'inactive_admin',
            $grant === null => 'no_grant',
            !$grant->isMadeFor(admin: $admin) => 'grant_of_another_admin_row',
            !$grant->isActive() => 'revoked_grant',
            default => null,
        };
    }

    private function openSession(LoginAttempt $attempt, Admin $admin, PlatformAdminGrant $grant): PlatformAdminSession
    {
        $session = $this->tokens->issue(admin: $admin, grant: $grant, deviceName: $attempt->deviceName, ip: $attempt->ip, userAgent: $attempt->userAgent);

        $this->audit->record(
            action: 'auth.login',
            adminId: $session->principal->adminId(),
            tokenId: (int) $session->principal->token->getKey(),
            ip: $attempt->ip,
            userAgent: $attempt->userAgent,
            context: ['device_name' => $attempt->deviceName],
        );

        return $session;
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= $this->hasher->make(Str::random(self::DUMMY_PASSWORD_LENGTH));
    }
}
