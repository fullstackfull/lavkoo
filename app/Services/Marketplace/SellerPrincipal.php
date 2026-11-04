<?php

namespace App\Services\Marketplace;

use App\Models\Seller;
use App\Models\SellerApiKey;
use App\Models\SellerStaff;

/**
 * Who is acting, and on whose shop.
 *
 * Every seller endpoint scopes its work to a shop. Until now that shop and the person asking were
 * the same thing, because only an owner could hold an API token — which is also why a staff member
 * could not use the seller app at all, and why anyone holding an owner's token got owner rights with
 * none of the permission matrix applied.
 *
 * Separating the two is the whole point: `sellerId` stays the shop, so every existing query that
 * scopes on it keeps meaning exactly what it meant; `staffId` and `permissions` say what this
 * particular person may do inside it.
 */
final class SellerPrincipal
{
    /**
     * @param  array<int, string>  $permissions  empty for an owner, who is not permission-checked
     */
    private function __construct(
        public readonly Seller $seller,
        public readonly ?SellerStaff $staff,
        public readonly array $permissions,
        public readonly ?SellerApiKey $apiKey = null,
    ) {
    }

    /** The shop owner: their own account, and no permission they do not have. */
    public static function owner(Seller $seller): self
    {
        return new self($seller, null, []);
    }

    /**
     * A key the seller issued to something that is not a person.
     *
     * Held to its scopes rather than to the owner's authority, which is the whole reason a key is
     * worth having: an integration that only reads orders should cost the seller their order list
     * if it leaks, not their payouts. So this is deliberately *not* an owner — every `isOwner()`
     * check that grants everything has to keep refusing it.
     *
     * @param  array<int, string>  $scopes
     */
    public static function integration(Seller $seller, SellerApiKey $apiKey, array $scopes): self
    {
        return new self($seller, null, array_values(array_unique($scopes)), $apiKey);
    }

    /**
     * A staff member acting inside their employer's shop.
     *
     * @param  array<int, string>  $permissions
     */
    public static function staff(Seller $seller, SellerStaff $staff, array $permissions): self
    {
        return new self($seller, $staff, array_values(array_unique($permissions)));
    }

    /** The shop. Not the person — this is what every query scopes on. */
    public function sellerId(): int
    {
        return (int) $this->seller->id;
    }

    public function isOwner(): bool
    {
        return $this->staff === null && $this->apiKey === null;
    }

    public function apiKeyId(): ?int
    {
        return $this->apiKey?->id;
    }

    public function staffId(): ?int
    {
        return $this->staff?->id;
    }

    /**
     * May this principal do that?
     *
     * An owner may do anything in their own shop — there is no role above them to grant it. A staff
     * member may do only what their role lists, which is why a role with no permissions is a real
     * and useful thing: an account that can sign in and see nothing.
     */
    public function can(string $permission): bool
    {
        return $this->isOwner() || in_array($permission, $this->permissions, true);
    }

    /**
     * The id to record against something this principal did, and what kind of id it is.
     *
     * The two belong together: read separately they were written as a seller id labelled as staff
     * the moment a key did the work, because a key is neither the owner nor a person.
     */
    public function actorId(): int
    {
        return $this->apiKey?->id ?? $this->staff?->id ?? $this->sellerId();
    }

    public function actorType(): string
    {
        if ($this->apiKey !== null) {
            return 'seller_api_key';
        }

        return $this->staff === null ? 'seller' : 'seller_staff';
    }

    /** For an audit line: who did this, in whose shop. */
    public function actorLabel(): string
    {
        if ($this->apiKey !== null) {
            return "{$this->apiKey->name} [{$this->apiKey->prefix}]";
        }

        return $this->isOwner()
            ? trim("{$this->seller->f_name} {$this->seller->l_name}")
            : "{$this->staff->name} ({$this->seller->f_name})";
    }
}
