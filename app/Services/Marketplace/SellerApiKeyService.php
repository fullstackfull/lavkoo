<?php

namespace App\Services\Marketplace;

use App\Models\Seller;
use App\Models\SellerApiKey;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issuing, checking and revoking a shop's API keys.
 *
 * A key is shown exactly once. There is no endpoint that returns it again, and no column that holds
 * it — only a hash and the prefix that says which row to check. That is inconvenient by design: a
 * key a seller can look up whenever they like is a key anybody who reaches their account can look
 * up too, and the whole reason to issue one instead of sharing a login is to limit the blast radius.
 *
 * The prefix does the work a plain scan would otherwise do. Without it, verifying a key would mean
 * hashing the candidate against every key in the table; with it, one row is fetched and one hash is
 * compared, which is what makes checking a key on every request affordable.
 */
class SellerApiKeyService
{
    /** Says what this is at a glance, in a log or a leaked file, so it can be revoked on sight. */
    private const TOKEN_PREFIX = 'sk_seller_';

    private const PREFIX_LENGTH = 6;
    private const SECRET_LENGTH = 40;

    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Issue a key, and return the plaintext with it — the only time it exists.
     *
     * Scopes are narrowed to what the person issuing the key actually holds. A staff member who may
     * read orders cannot mint a key that moves money; that would make key creation a way around the
     * permission model rather than an expression of it.
     *
     * @return array{key: SellerApiKey, plaintext: string}
     */
    public function issue(SellerPrincipal $principal, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $prefix = $this->uniquePrefix();
        $plaintext = self::TOKEN_PREFIX . $prefix . '_' . Str::random(self::SECRET_LENGTH);

        $key = SellerApiKey::create([
            'seller_id' => $principal->sellerId(),
            'created_by_staff_id' => $principal->staffId(),
            'name' => $name,
            'prefix' => $prefix,
            'token_hash' => Hash::make($plaintext),
            'scopes' => $this->grantable($principal, $scopes),
            'expires_at' => $expiresAt,
        ]);

        $this->audit->record(
            action: 'seller.api_key_issued',
            subject: ['type' => 'seller_api_key', 'id' => $key->id],
            after: ['name' => $key->name, 'prefix' => $key->prefix, 'scopes' => $key->scopes],
            context: ['seller_id' => $principal->sellerId(), 'actor' => $principal->actorLabel()],
        );

        return ['key' => $key, 'plaintext' => $plaintext];
    }

    /**
     * Who is holding this key, if it is one.
     *
     * Returns null for anything that is not a key of ours, so the caller can fall through to the
     * other credentials without having to know the format.
     */
    public function resolve(string $candidate): ?SellerPrincipal
    {
        if (!str_starts_with($candidate, self::TOKEN_PREFIX)) {
            return null;
        }

        $prefix = substr($candidate, strlen(self::TOKEN_PREFIX), self::PREFIX_LENGTH);
        $key = SellerApiKey::where('prefix', $prefix)->first();

        if (!$key || !$key->isUsable() || !Hash::check($candidate, $key->token_hash)) {
            return null;
        }

        // The shop's own standing is checked every time, exactly as it is for a login token. A key
        // issued while a shop was approved must stop working the moment it is not.
        $seller = Seller::approved()->find($key->seller_id);

        if (!$seller) {
            return null;
        }

        return SellerPrincipal::integration($seller, $key, $this->permissions->sanitize($key->scopes ?? []));
    }

    /**
     * Note that a key was used.
     *
     * Deliberately not part of `resolve()`: a seller looking at "last used two months ago" is
     * deciding whether to revoke it, and that answer has to come from real traffic rather than from
     * somebody opening the key list.
     */
    public function touch(SellerApiKey $key, ?string $ip): void
    {
        $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
    }

    public function revoke(SellerApiKey $key, SellerPrincipal $principal): void
    {
        if ($key->revoked_at !== null) {
            return;
        }

        $key->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(
            action: 'seller.api_key_revoked',
            subject: ['type' => 'seller_api_key', 'id' => $key->id],
            before: ['name' => $key->name, 'prefix' => $key->prefix],
            context: ['seller_id' => $key->seller_id, 'actor' => $principal->actorLabel()],
        );
    }

    /**
     * The scopes this principal is actually able to hand out.
     *
     * @return array<int, string>
     */
    private function grantable(SellerPrincipal $principal, array $requested): array
    {
        $valid = $this->permissions->sanitize($requested);

        if ($principal->isOwner()) {
            return $valid;
        }

        return array_values(array_filter($valid, fn (string $scope) => $principal->can($scope)));
    }

    private function uniquePrefix(): string
    {
        do {
            $prefix = Str::lower(Str::random(self::PREFIX_LENGTH));
        } while (SellerApiKey::where('prefix', $prefix)->exists());

        return $prefix;
    }
}
