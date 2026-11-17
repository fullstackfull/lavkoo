<?php

namespace App\Services\PlatformAdmin;

use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Making, narrowing and ending the right to use the platform admin API. It is called only by the console commands an operator runs on the
 * server: nothing a request can reach creates or widens a grant. Every change is audited.
 */
final class PlatformAdminGrantService
{
    public function __construct(
        private readonly PlatformMarkets $markets,
        private readonly PlatformAdminTokenService $tokens,
        private readonly PlatformAdminMfaChallenges $challenges,
        private readonly PlatformAdminAudit $audit,
    ) {
    }

    /**
     * Makes the whole grant of an admin: what it leaves out is the default (every market, every capability, no end).
     *
     * @param list<string>|null $countries the codes as the operator typed them, or null for every market (the platform owner)
     * @param list<string>|null $capabilities the names as the operator typed them, or null for every capability that exists
     * @param CarbonInterface|null $expiresAt the moment the grant ends, or null for no end
     *
     * @throws PlatformAdminGrantRefusedException
     */
    public function grant(int $adminId, ?array $countries, int $level, ?array $capabilities = null, ?CarbonInterface $expiresAt = null): PlatformAdminGrant
    {
        $admin = $this->activeAdminOf(adminId: $adminId);
        $this->requireKnownLevel(level: $level);
        $scope = $this->scopeOf(countries: $countries);
        $included = $this->capabilitiesOf(capabilities: $capabilities);
        $this->requireAnEndInTheFuture(expiresAt: $expiresAt);

        return DB::transaction(
            fn (): PlatformAdminGrant => $this->writeGrant(admin: $admin, scope: $scope, level: $level, capabilities: $included, expiresAt: $expiresAt),
            attempts: PlatformAdminAudit::DEADLOCK_ATTEMPTS,
        );
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
            $this->audit->record(action: 'grant.revoked', adminId: $adminId, context: ConsoleOperator::auditContext(extra: $result));

            return $result;
        }, attempts: PlatformAdminAudit::DEADLOCK_ATTEMPTS);
    }

    /** Ends every session of the admin and leaves the grant, so the admin can sign in again. Returns how many sessions it ended. */
    public function revokeTokens(int $adminId): int
    {
        $revoked = $this->tokens->revokeAllFor(adminId: $adminId);
        $this->challenges->endAllFor(adminId: $adminId);
        $this->audit->record(action: 'tokens.revoked', adminId: $adminId, context: ConsoleOperator::auditContext(extra: ['tokens_revoked' => $revoked]));

        return $revoked;
    }

    /**
     * A grant that was made for another row with this id (the admin was deleted, without a foreign key to take its grant with it, and another
     * row came to carry the id) is made over to the row that carries it now, which is what the operator asked for; the sessions of the old row end.
     *
     * A grant that was revoked, or that has ended, is given back: the sessions it had are ended first, so that one that was left over does not come back to life.
     *
     * @param list<string>|null $scope
     * @param list<string>|null $capabilities
     */
    private function writeGrant(Admin $admin, ?array $scope, int $level, ?array $capabilities, ?CarbonInterface $expiresAt): PlatformAdminGrant
    {
        $adminId = (int) $admin->getKey();
        $grant = $this->lockedGrantOf(adminId: $adminId);
        $before = $grant === null ? null : $this->describedBefore(grant: $grant);
        $reactivating = $grant !== null && ($grant->revoked_at !== null || $grant->hasExpired());
        $replacedRow = $grant !== null && !$grant->isMadeFor(admin: $admin);

        $grant ??= new PlatformAdminGrant(['admin_id' => $adminId]);
        $grant->fill([
            'admin_fingerprint' => PlatformAdminGrant::fingerprintOf(admin: $admin),
            'level' => $level,
            'countries' => $scope,
            'capabilities' => $capabilities,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);
        if (!$grant->exists || $reactivating || $replacedRow) {
            $grant->granted_at = now();
        }
        $grant->save();

        $context = ['before' => $before, 'after' => ['level' => $level, 'countries' => $scope, 'capabilities' => $capabilities, 'expires_at' => PlatformAdminPresenter::timestamp(moment: $expiresAt)]];
        if ($reactivating || $replacedRow) {
            $context['tokens_revoked'] = $this->tokens->revokeAllFor(adminId: $adminId);
            $this->challenges->endAllFor(adminId: $adminId);
        }
        if ($replacedRow) {
            $context['replaced_admin_row'] = true;
        }

        $this->audit->record(action: $this->actionOf(grant: $before === null ? null : $grant, reactivating: $reactivating), adminId: $adminId, context: ConsoleOperator::auditContext(extra: $context));

        return $grant;
    }

    /**
     * What a grant was before it was changed, as the audit line says it: what it includes is the list it names, or null when it names none (every one).
     *
     * @return array{level: int, countries: ?list<string>, capabilities: ?list<string>, expires_at: ?string, revoked: bool}
     */
    private function describedBefore(PlatformAdminGrant $grant): array
    {
        return [
            'level' => $grant->level,
            'countries' => $grant->countryList(),
            'capabilities' => ($grant->getAttributes()['capabilities'] ?? null) === null ? null : $grant->capabilityList(),
            'expires_at' => PlatformAdminPresenter::timestamp(moment: $grant->expires_at),
            'revoked' => $grant->revoked_at !== null,
        ];
    }

    private function actionOf(?PlatformAdminGrant $grant, bool $reactivating): string
    {
        return match (true) {
            $grant === null => 'grant.created',
            $reactivating => 'grant.reactivated',
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
     * @param list<string>|null $capabilities
     *
     * @return list<string>|null the capabilities the operator named, in their own order, once each; null when none are named, which is every one
     *
     * @throws PlatformAdminGrantRefusedException
     */
    private function capabilitiesOf(?array $capabilities): ?array
    {
        if ($capabilities === null) {
            return null;
        }

        $named = array_values(array_unique(array_filter(array_map(static fn (string $name): string => strtolower(trim($name)), $capabilities), static fn (string $name): bool => $name !== '')));

        if ($named === []) {
            throw new PlatformAdminGrantRefusedException('The capability list has no capability name in it. Known: ' . implode(', ', PlatformAdminGrant::CAPABILITIES) . '. Leave --capabilities out to include every one.');
        }

        $unknown = array_values(array_diff($named, PlatformAdminGrant::CAPABILITIES));

        if ($unknown !== []) {
            throw new PlatformAdminGrantRefusedException(sprintf('Unknown capabilit%s: %s. Known: %s.', count($unknown) > 1 ? 'ies' : 'y', implode(', ', $unknown), implode(', ', PlatformAdminGrant::CAPABILITIES)));
        }

        return array_values(array_intersect(PlatformAdminGrant::CAPABILITIES, $named));
    }

    /** @throws PlatformAdminGrantRefusedException */
    private function requireAnEndInTheFuture(?CarbonInterface $expiresAt): void
    {
        if ($expiresAt !== null && !$expiresAt->isFuture()) {
            throw new PlatformAdminGrantRefusedException('That day has passed, so a grant that ends on it would be over before it began.');
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
