<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminMfaRefusedException;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Models\PlatformAdminMfaFactor;
use Illuminate\Support\Facades\DB;

/**
 * Enrolling, activating, resetting and looking at the second factor of an admin. It is called only by the console commands an operator runs on the
 * server: nothing a request can reach makes, widens or removes a factor. Every change is audited with the operator who made it, and no line of the trail,
 * no message and nothing returned for display after the enrolment carries a secret or a code.
 */
final class PlatformAdminMfaEnrolment
{
    private const DEFAULT_ISSUER = 'Platform admin';

    private const FACTOR_UNREADABLE = 'unreadable_secret';

    private const WRONG_CODE = 'wrong_code';

    public function __construct(
        private readonly PlatformAdminMfaFactors $factors,
        private readonly PlatformAdminTokenService $tokens,
        private readonly PlatformAdminMfaChallenges $challenges,
        private readonly PlatformAdminAudit $audit,
    ) {
    }

    /**
     * Makes a pending factor for an admin who holds an active grant. With `$replace` an existing one, pending or active, is removed first and the admin's
     * sessions and challenges end; without it an existing one is a refusal and nothing changes.
     *
     * @throws PlatformAdminMfaRefusedException
     */
    public function enrol(int $adminId, bool $replace): EnrolledFactor
    {
        $admin = $this->activeAdminOf(adminId: $adminId);
        $grant = $this->activeGrantOf(admin: $admin);
        $secret = Totp::newSecret();
        $codes = RecoveryCodes::generate();

        DB::transaction(function () use ($admin, $grant, $secret, $codes, $replace): void {
            $adminId = (int) $admin->getKey();
            $existing = $this->factors->forAdmin(adminId: $adminId, lock: true);

            if ($existing !== null && !$replace) {
                throw new PlatformAdminMfaRefusedException(sprintf(
                    'Admin %d already has a second factor (%s). To make a new one run this with --replace: the one that exists is removed, the admin\'s sessions end, and they cannot sign in until the new one is confirmed.',
                    $adminId,
                    $existing->isConfirmed() ? 'active' : 'pending',
                ));
            }

            $context = ['replaced' => $existing !== null];
            if ($existing !== null) {
                $existing->delete();
                $context += ['tokens_revoked' => $this->tokens->revokeAllFor(adminId: $adminId), 'challenges_ended' => $this->challenges->endAllFor(adminId: $adminId)];
            }

            PlatformAdminMfaFactor::create([
                'admin_id' => $adminId,
                'grant_id' => $grant->getKey(),
                'admin_fingerprint' => PlatformAdminGrant::fingerprintOf(admin: $admin),
                'secret' => Base32::encode(bytes: $secret),
                'recovery_codes' => array_map(static fn (string $code): array => ['hash' => RecoveryCodes::hash(normalized: (string) RecoveryCodes::normalized(typed: $code)), 'used_at' => null], $codes),
            ]);

            $this->audit->record(action: 'mfa.enrolled', adminId: $adminId, context: ConsoleOperator::auditContext(extra: $context));
        });

        $base32 = Base32::encode(bytes: $secret);

        return new EnrolledFactor(
            secret: $base32,
            uri: Totp::otpauthUri(base32Secret: $base32, account: (string) ($admin->email ?: 'admin-' . $admin->getKey()), issuer: (string) (config('app.name') ?: self::DEFAULT_ISSUER)),
            recoveryCodes: $codes,
        );
    }

    /**
     * Activates a pending factor with the code the authenticator app shows for it, and marks the step of that code as used so that it cannot sign anybody in.
     * A code that is not accepted is audited, and changes nothing.
     *
     * @throws PlatformAdminMfaRefusedException
     */
    public function confirm(int $adminId, string $code): void
    {
        $admin = Admin::query()->select(['id', 'created_at'])->find($adminId) ?? throw new PlatformAdminMfaRefusedException("Admin {$adminId} does not exist.");

        $failure = DB::transaction(fn (): ?string => $this->activateWith(admin: $admin, code: $code));

        if ($failure !== null) {
            $this->audit->record(action: 'mfa.confirm_failed', adminId: $adminId, context: ConsoleOperator::auditContext(extra: ['reason' => $failure]));

            throw new PlatformAdminMfaRefusedException(match ($failure) {
                self::FACTOR_UNREADABLE => "The secret of admin {$adminId} cannot be read with this application key. Run gcc:platform-admin:mfa:enroll {$adminId} --replace.",
                default => 'That is not the code the authenticator app shows for this secret now. Nothing was changed.',
            });
        }
    }

    /**
     * Removes the factor, pending or active, and ends every session and challenge of the admin. The grant stays.
     *
     * @return array{was: string, tokens_revoked: int, challenges_ended: int}
     *
     * @throws PlatformAdminMfaRefusedException
     */
    public function reset(int $adminId): array
    {
        return DB::transaction(function () use ($adminId): array {
            $factor = $this->factors->forAdmin(adminId: $adminId, lock: true) ?? throw new PlatformAdminMfaRefusedException("Admin {$adminId} has no second factor.");
            $factor->delete();

            $result = [
                'was' => $factor->isConfirmed() ? 'confirmed' : 'pending',
                'tokens_revoked' => $this->tokens->revokeAllFor(adminId: $adminId),
                'challenges_ended' => $this->challenges->endAllFor(adminId: $adminId),
            ];
            $this->audit->record(action: 'mfa.reset', adminId: $adminId, context: ConsoleOperator::auditContext(extra: $result));

            return $result;
        });
    }

    /**
     * The state of the factor of every admin who holds a grant, or of one admin, and how many of their open sessions passed it and how many did not.
     * Never a secret, a code or a hash.
     *
     * @return list<array{admin_id: int, factor: string, since: ?string, codes_left: ?int, secret: ?string, sessions_with: int, sessions_without: int}>
     *
     * @throws PlatformAdminMfaRefusedException
     */
    public function status(?int $adminId): array
    {
        $grants = PlatformAdminGrant::query()->when($adminId !== null, fn ($query) => $query->where('admin_id', $adminId))->orderBy('admin_id')->get();

        if ($adminId !== null && $grants->isEmpty()) {
            throw new PlatformAdminMfaRefusedException("Admin {$adminId} has no grant.");
        }

        $rows = $grants->map(fn (PlatformAdminGrant $grant): array => $this->statusOf(adminId: (int) $grant->admin_id))->values()->all();
        $this->audit->record(action: 'mfa.status', adminId: $adminId, context: ConsoleOperator::auditContext());

        return $rows;
    }

    /** @return array{admin_id: int, factor: string, since: ?string, codes_left: ?int, secret: ?string, sessions_with: int, sessions_without: int} */
    private function statusOf(int $adminId): array
    {
        $factor = $this->factors->forAdmin(adminId: $adminId);
        $admin = Admin::query()->select(['id', 'created_at'])->find($adminId);
        $sessions = $this->tokens->openSessionsOf(adminId: $adminId);
        $withMfa = $sessions->filter(static fn ($session): bool => $session->mfaMethod() !== null)->count();

        return [
            'admin_id' => $adminId,
            'factor' => match (true) {
                $factor === null => 'none',
                $admin === null || !$factor->isMadeFor(admin: $admin) => 'other_row',
                $factor->isConfirmed() => 'active',
                default => 'pending',
            },
            'since' => $factor?->confirmed_at?->toDateTimeString(),
            'codes_left' => $factor?->recoveryCodesLeft(),
            'secret' => $factor === null ? null : ($factor->secretBytes() === null ? 'unreadable' : 'readable'),
            'sessions_with' => $withMfa,
            'sessions_without' => $sessions->count() - $withMfa,
        ];
    }

    /**
     * Activates the pending factor of the admin if the code is right, in the transaction that holds its row, and audits it. Returns why it did not, or null.
     *
     * @throws PlatformAdminMfaRefusedException when there is nothing to activate, which leaves no line, because nothing was tried
     */
    private function activateWith(Admin $admin, string $code): ?string
    {
        $adminId = (int) $admin->getKey();
        $factor = $this->factors->forAdmin(adminId: $adminId, lock: true) ?? throw new PlatformAdminMfaRefusedException("Admin {$adminId} has no second factor. Run gcc:platform-admin:mfa:enroll {$adminId} first.");

        if ($factor->isConfirmed()) {
            throw new PlatformAdminMfaRefusedException("The second factor of admin {$adminId} is already active.");
        }

        if (!$factor->isMadeFor(admin: $admin)) {
            throw new PlatformAdminMfaRefusedException("The pending factor of admin {$adminId} was made for another admin row that carried this id. Run gcc:platform-admin:mfa:enroll {$adminId} --replace.");
        }

        $secret = $factor->secretBytes();
        if ($secret === null) {
            return self::FACTOR_UNREADABLE;
        }

        $step = Totp::matchingStep(secret: $secret, code: preg_replace('/\s+/', '', $code) ?? '', timestamp: now()->timestamp);
        if ($step === null) {
            return self::WRONG_CODE;
        }

        $factor->forceFill(['confirmed_at' => now(), 'last_used_step' => $step])->save();
        $this->audit->record(action: 'mfa.confirmed', adminId: $adminId, context: ConsoleOperator::auditContext());

        return null;
    }

    /** @throws PlatformAdminMfaRefusedException */
    private function activeAdminOf(int $adminId): Admin
    {
        $admin = Admin::query()->select(['id', 'email', 'status', 'created_at'])->find($adminId);

        if ($admin === null) {
            throw new PlatformAdminMfaRefusedException("Admin {$adminId} does not exist.");
        }

        if ((int) $admin->getRawOriginal('status') !== 1) {
            throw new PlatformAdminMfaRefusedException("Admin {$adminId} is not active: its status must be exactly 1, for the first admin too.");
        }

        return $admin;
    }

    /** @throws PlatformAdminMfaRefusedException */
    private function activeGrantOf(Admin $admin): PlatformAdminGrant
    {
        $grant = PlatformAdminGrant::query()->where('admin_id', $admin->getKey())->first();

        if ($grant === null || !$grant->isActive() || !$grant->isMadeFor(admin: $admin)) {
            throw new PlatformAdminMfaRefusedException(sprintf('Admin %1$d has no active grant. Run gcc:platform-admin:grant %1$d first.', $admin->getKey()));
        }

        return $grant;
    }
}
