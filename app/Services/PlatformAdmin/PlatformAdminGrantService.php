<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use Illuminate\Support\Facades\DB;

/**
 * Making, narrowing and ending the right to use the platform admin API. It is called only by the console commands an operator runs on the
 * server: nothing a request can reach creates or widens a grant. Every change is audited.
 */
final class PlatformAdminGrantService
{
    private const SOURCE = 'console';

    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly PlatformAdminTokenService $tokens,
        private readonly PlatformAdminMfaChallenges $challenges,
        private readonly PlatformAdminAudit $audit,
    ) {
    }

    /**
     * @param list<string>|null $countries the codes as the operator typed them, or null for every market (the platform owner)
     *
     * @throws PlatformAdminGrantRefusedException
     */
    public function grant(int $adminId, ?array $countries, int $level): PlatformAdminGrant
    {
        $admin = $this->activeAdminOf(adminId: $adminId);
        $this->requireKnownLevel(level: $level);
        $scope = $this->scopeOf(countries: $countries);

        return DB::transaction(fn (): PlatformAdminGrant => $this->writeGrant(admin: $admin, scope: $scope, level: $level));
    }

    /**
     * Ends the grant and every session of the admin.
     *
     * @return array{tokens_revoked: int, already_revoked: bool}
     *
     * @throws PlatformAdminGrantRefusedException
     */
    public function revoke(int $adminId): array
    {
        return DB::transaction(function () use ($adminId): array {
            $grant = $this->lockedGrantOf(adminId: $adminId);

            if ($grant === null) {
                throw new PlatformAdminGrantRefusedException("Admin {$adminId} has no grant.");
            }

            $alreadyRevoked = $grant->revoked_at !== null;
            if (!$alreadyRevoked) {
                $grant->forceFill(['revoked_at' => now()])->save();
            }

            $result = ['tokens_revoked' => $this->tokens->revokeAllFor(adminId: $adminId), 'already_revoked' => $alreadyRevoked];
            $this->challenges->endAllFor(adminId: $adminId);
            $this->audit->record(action: 'grant.revoked', adminId: $adminId, context: ['source' => self::SOURCE] + $result);

            return $result;
        });
    }

    /** Ends every session of the admin and leaves the grant, so the admin can sign in again. Returns how many sessions it ended. */
    public function revokeTokens(int $adminId): int
    {
        $revoked = $this->tokens->revokeAllFor(adminId: $adminId);
        $this->challenges->endAllFor(adminId: $adminId);
        $this->audit->record(action: 'tokens.revoked', adminId: $adminId, context: ['source' => self::SOURCE, 'tokens_revoked' => $revoked]);

        return $revoked;
    }

    /**
     * A grant that was made for another row with this id (the admin was deleted, without a foreign key to take its grant with it, and another
     * row came to carry the id) is made over to the row that carries it now, which is what the operator asked for; the sessions of the old row end.
     *
     * @param list<string>|null $scope
     */
    private function writeGrant(Admin $admin, ?array $scope, int $level): PlatformAdminGrant
    {
        $adminId = (int) $admin->getKey();
        $grant = $this->lockedGrantOf(adminId: $adminId);
        $before = $grant === null ? null : ['level' => $grant->level, 'countries' => $grant->countryList(), 'revoked' => $grant->revoked_at !== null];
        $reactivating = $grant !== null && $grant->revoked_at !== null;
        $replacedRow = $grant !== null && !$grant->isMadeFor(admin: $admin);

        $grant ??= new PlatformAdminGrant(['admin_id' => $adminId]);
        $grant->fill(['admin_fingerprint' => PlatformAdminGrant::fingerprintOf(admin: $admin), 'level' => $level, 'countries' => $scope, 'revoked_at' => null]);
        if (!$grant->exists || $reactivating || $replacedRow) {
            $grant->granted_at = now();
        }
        $grant->save();

        $context = ['source' => self::SOURCE, 'before' => $before, 'after' => ['level' => $level, 'countries' => $scope]];
        if ($reactivating || $replacedRow) {
            $context['tokens_revoked'] = $this->tokens->revokeAllFor(adminId: $adminId);
            $this->challenges->endAllFor(adminId: $adminId);
        }
        if ($replacedRow) {
            $context['replaced_admin_row'] = true;
        }

        $this->audit->record(action: $this->actionOf(before: $before), adminId: $adminId, context: $context);

        return $grant;
    }

    /** @param array{level: int, countries: ?list<string>, revoked: bool}|null $before */
    private function actionOf(?array $before): string
    {
        return match (true) {
            $before === null => 'grant.created',
            $before['revoked'] => 'grant.reactivated',
            default => 'grant.updated',
        };
    }

    private function lockedGrantOf(int $adminId): ?PlatformAdminGrant
    {
        return PlatformAdminGrant::query()->where('admin_id', $adminId)->lockForUpdate()->first();
    }

    private function activeAdminOf(int $adminId): Admin
    {
        $admin = Admin::query()->select(['id', 'status', 'created_at'])->find($adminId);

        if ($admin === null) {
            throw new PlatformAdminGrantRefusedException("Admin {$adminId} does not exist.");
        }

        if ((int) $admin->getRawOriginal('status') !== 1) {
            throw new PlatformAdminGrantRefusedException("Admin {$adminId} is not active: its status must be exactly 1, for the first admin too.");
        }

        return $admin;
    }

    private function requireKnownLevel(int $level): void
    {
        if ($level !== PlatformAdminGrant::LEVEL_MONITOR) {
            throw new PlatformAdminGrantRefusedException("Only level 1 (monitor) exists; level {$level} is not available.");
        }
    }

    /**
     * @param list<string>|null $countries
     *
     * @return list<string>|null the configured codes the operator named, in the order of the configuration; null for every market
     */
    private function scopeOf(?array $countries): ?array
    {
        if ($countries === null) {
            return null;
        }

        $named = array_values(array_unique(array_filter(array_map(static fn (string $code): string => strtoupper(trim($code)), $countries), static fn (string $code): bool => $code !== '')));

        if ($named === []) {
            throw new PlatformAdminGrantRefusedException('The country list has no country code in it. Leave --countries out to grant every market.');
        }

        $known = $this->markets->codes();
        $unknown = array_values(array_diff($named, $known));

        if ($unknown !== []) {
            throw new PlatformAdminGrantRefusedException(sprintf('Unknown country code%s: %s. Known codes: %s.', count($unknown) > 1 ? 's' : '', implode(', ', $unknown), implode(', ', $known)));
        }

        return array_values(array_intersect($known, $named));
    }
}
