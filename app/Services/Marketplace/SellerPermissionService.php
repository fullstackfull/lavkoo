<?php

namespace App\Services\Marketplace;

use App\Models\Seller;
use App\Models\SellerApiKey;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use Illuminate\Support\Facades\Schema;

/**
 * Seller staff roles & permissions (Phase 3, Stage A — Seller Center).
 *
 * This owns the permission *catalog* (the vendor capabilities a role can grant) and the resolver that
 * answers "does this staff member have permission X?". That resolver is the seam the future staff-login
 * enforcement will call — it is built and tested now so the deferred guard only has to invoke it, not
 * define the model.
 *
 * The seller (the owner account) is intentionally not represented as a staff row; the owner implicitly
 * holds every permission, and `staffCan()` speaks only to delegated staff.
 */
class SellerPermissionService
{
    /**
     * The permission catalog, grouped for the management UI. Keys are the stable identifiers a role
     * stores and enforcement will check; the labels are translatable.
     *
     * @return array<string, array<int, string>>
     */
    public function catalog(): array
    {
        return [
            'products' => ['products.view', 'products.manage'],
            'orders' => ['orders.view', 'orders.manage'],
            'inventory' => ['inventory.manage'],
            'promotions' => ['promotions.manage'],
            'finance' => ['finance.view', 'payouts.request'],
            'reviews' => ['reviews.view'],
            'settings' => ['shop_settings.manage', 'staff.manage'],
        ];
    }

    /** Every valid permission key, flat. */
    public function allKeys(): array
    {
        return array_values(array_merge(...array_values($this->catalog())));
    }

    /** Keep only keys that exist in the catalog — so a role can never store an unknown permission. */
    public function sanitize(array $permissions): array
    {
        $valid = $this->allKeys();

        return array_values(array_intersect(array_values($permissions), $valid));
    }

    public function rolesFor(int|string $sellerId)
    {
        if (!Schema::hasTable('seller_roles')) {
            return collect();
        }

        return SellerRole::where('seller_id', $sellerId)->orderBy('name')->get();
    }

    public function staffFor(int|string $sellerId)
    {
        if (!Schema::hasTable('seller_staff')) {
            return collect();
        }

        return SellerStaff::with('role')->where('seller_id', $sellerId)->orderBy('name')->get();
    }

    /** Does a role grant a permission? A role counts as active unless explicitly set otherwise
     *  (a freshly-created model may not carry the persisted 'active' default yet). */
    public function roleHas(?SellerRole $role, string $permission): bool
    {
        if ($role === null) {
            return false;
        }

        $active = ($role->status ?? SellerRole::STATUS_ACTIVE) === SellerRole::STATUS_ACTIVE;

        return $active && $role->grants($permission);
    }

    /**
     * Who is holding this bearer token, and what may they do?
     *
     * Tried in order: the shop owner, then their staff. A staff member resolves to their employer's
     * shop, so `sellerId()` keeps meaning exactly what it meant before staff could hold a token at
     * all — every existing query that scopes on it is unaffected.
     *
     * An inactive staff member, one whose employer is no longer approved, or one whose role has been
     * switched off resolves to nothing: the safe answer for an authentication check.
     */
    public function principalFor(string $token): ?SellerPrincipal
    {
        $seller = Seller::approved()->where('auth_token', $token)->first();
        if ($seller) {
            return SellerPrincipal::owner($seller);
        }

        if (!Schema::hasTable('seller_staff')) {
            return null;
        }

        $staff = SellerStaff::with('role')->where('auth_token', $token)->first();
        if (!$staff || $staff->status !== SellerStaff::STATUS_ACTIVE) {
            return null;
        }

        $employer = Seller::approved()->find($staff->seller_id);
        if (!$employer) {
            return null;
        }

        return SellerPrincipal::staff($employer, $staff, $this->permissionsOf($staff));
    }

    /**
     * Rebuild a principal from what a stored record remembers about who created it.
     *
     * Deferred work — a queued bulk job, a rule the scheduler picks up hours later — has no request
     * and no token, only a shop id and possibly a staff id. It still has to run as somebody, and it
     * still has to stop running if that somebody has since lost the right to act: a suspended shop,
     * a deactivated employee, a revoked role. Resolving it fresh at run time is what makes that
     * true, rather than trusting a decision made when the work was queued.
     */
    public function principalForSeller(
        int|string $sellerId,
        int|string|null $staffId = null,
        int|string|null $apiKeyId = null,
    ): ?SellerPrincipal {
        $seller = Seller::approved()->find($sellerId);

        if (!$seller) {
            return null;
        }

        // A key first, because a key principal has no staff id and "no staff id" used to mean
        // "the owner" — which quietly promoted every rule and bulk job a key created to full
        // owner authority, and kept it running after the key was revoked.
        if ($apiKeyId !== null) {
            return $this->principalForApiKey($seller, $apiKeyId);
        }

        if ($staffId === null) {
            return SellerPrincipal::owner($seller);
        }

        if (!Schema::hasTable('seller_staff')) {
            return null;
        }

        $staff = SellerStaff::with('role')
            ->where('id', $staffId)
            ->where('seller_id', $seller->id)
            ->where('status', SellerStaff::STATUS_ACTIVE)
            ->first();

        if (!$staff) {
            return null;
        }

        return SellerPrincipal::staff($seller, $staff, $this->permissionsOf($staff));
    }

    /**
     * Rebuild an integration principal from the key that created some deferred work.
     *
     * Returns null when the key has been revoked or has expired, which is what makes revocation
     * stop the work rather than merely stop the next request — the same property a deactivated
     * staff member already had.
     */
    private function principalForApiKey(Seller $seller, int|string $apiKeyId): ?SellerPrincipal
    {
        if (!Schema::hasTable('seller_api_keys')) {
            return null;
        }

        $key = SellerApiKey::where('id', $apiKeyId)->where('seller_id', $seller->id)->first();

        if (!$key || !$key->isUsable()) {
            return null;
        }

        return SellerPrincipal::integration($seller, $key, $this->sanitize($key->scopes ?? []));
    }

    /**
     * What a staff member's role grants them right now.
     *
     * Read at request time rather than stored on the token, so revoking a permission takes effect on
     * the member's next request instead of whenever they next sign in.
     *
     * @return array<int, string>
     */
    public function permissionsOf(SellerStaff $staff): array
    {
        $role = $staff->role;

        if ($role === null || ($role->status ?? SellerRole::STATUS_ACTIVE) !== SellerRole::STATUS_ACTIVE) {
            return [];
        }

        return $this->sanitize(is_array($role->permissions) ? $role->permissions : []);
    }

    /**
     * Can a staff member perform an action? Resolves through their assigned role. An inactive staff
     * member, or one with no role, can do nothing — the safe default for a permission check.
     */
    public function staffCan(int|string $staffId, string $permission): bool
    {
        if (!Schema::hasTable('seller_staff')) {
            return false;
        }

        $staff = SellerStaff::with('role')->find($staffId);
        if (!$staff || $staff->status !== SellerStaff::STATUS_ACTIVE) {
            return false;
        }

        return $this->roleHas($staff->role, $permission);
    }
}
