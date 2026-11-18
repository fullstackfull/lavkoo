<?php

namespace App\Services\Marketplace;

use App\Services\Storefront\StorefrontResolver;

/**
 * What the seller app is told about who is signed in (K-17, K-08): the answer of GET api/v3/seller/seller-center/security/me. Login answers a token and
 * nothing else, so without it an app can tell neither an owner from a staff member nor which of its screens a role may open.
 *
 * It describes the caller and nobody else, from the principal the token resolved to, and it adds no rule of its own: what a principal may do is whatever
 * SellerPrincipal::can() says, asked of every permission the platform knows, so the owner holds them all and a staff member exactly what their role grants
 * now. Nothing of a credential or of a contact detail is in it.
 *
 * The auth middleware refuses an issued key on a route that names no permission, and this route names none, so only an owner or a staff member is ever
 * described here; a key is no person.
 */
final class SellerPrincipalProfile
{
    private const OWNER = 'owner';

    private const STAFF = 'staff';

    /** No market is granted to anybody yet (K-09), so every principal works in every market the platform serves. */
    private const MARKET_SCOPE_UNRESTRICTED = 'unrestricted';

    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    /**
     * `allowed_markets` is null, never a list: nothing grants a seller a market yet, and a list that named some would claim a grant that does not exist.
     *
     * @return array{principal_type: string, seller_id: int, staff_id: int|null, role: string|null, permissions: list<string>, market_scope: string, allowed_markets: null, storefronts_enabled: bool}
     */
    public function of(SellerPrincipal $principal): array
    {
        return [
            'principal_type' => $principal->isOwner() ? self::OWNER : self::STAFF,
            'seller_id' => $principal->sellerId(),
            'staff_id' => $principal->staffId(),
            'role' => $principal->staff?->role?->name,
            'permissions' => array_values(array_filter($this->permissions->allKeys(), $principal->can(...))),
            'market_scope' => self::MARKET_SCOPE_UNRESTRICTED,
            'allowed_markets' => null,
            'storefronts_enabled' => StorefrontResolver::fromConfig()->isEnabled(),
        ];
    }
}
