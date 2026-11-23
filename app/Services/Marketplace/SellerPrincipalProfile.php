<?php

namespace App\Services\Marketplace;

use App\Services\Storefront\StorefrontResolver;

/**
 * What the seller app is told about who is signed in (K-17, K-08, K-09): the answer of GET api/v3/seller/seller-center/security/me. Login answers a token and
 * nothing else, so without it an app can tell neither an owner from a staff member nor which of its screens a role may open.
 *
 * It describes the caller and nobody else, from the principal the token resolved to, and it adds no rule of its own: what a principal may do is whatever
 * SellerPrincipal::can() says, asked of every permission the platform knows, so the owner holds them all and a staff member exactly what their role grants
 * now. Nothing of a credential or of a contact detail is in it.
 *
 * **The token carries the markets** (K-09): `allowed_markets` is the grant as `seller_market_scopes` records it, and `permissions_by_market` what the caller
 * may do in each of them. The app reads it to know which markets to offer and which screens to draw — and nothing more: the server checks every request
 * against the same rows, so an app that offers a market it was not granted gets 403 from `seller_market` rather than data.
 *
 * The auth middleware refuses an issued key on a route that names no permission, and this route names none, so only an owner or a staff member is ever
 * described here; a key is no person.
 */
final class SellerPrincipalProfile
{
    private const OWNER = 'owner';

    private const STAFF = 'staff';

    /** No market is granted to this principal, so they work in every market the platform serves. */
    private const MARKET_SCOPE_UNRESTRICTED = 'unrestricted';

    /** Some markets are granted, and only those: a request naming any other is refused with no data. */
    private const MARKET_SCOPE_LIMITED = 'limited';

    public function __construct(
        private readonly SellerPermissionService $permissions,
        private readonly SellerMarketScope $markets,
    ) {
    }

    /**
     * `allowed_markets` is null when no grant exists, never an empty list: a list that named none would read as a grant of nothing rather than as no grant.
     *
     * `permissions_by_market` is present only while a grant is, so a principal with no grant is answered the eight keys of before, in the same order, byte
     * for byte — which is every principal while the switch is off. The app reads the ninth key when, and only when, `market_scope` is `limited`.
     *
     * @return array{principal_type: string, seller_id: int, staff_id: int|null, role: string|null, permissions: list<string>, market_scope: string, allowed_markets: list<string>|null, storefronts_enabled: bool}
     */
    public function of(SellerPrincipal $principal): array
    {
        $grant = $this->markets->enabled() ? $this->markets->grantOf($principal) : null;

        $profile = [
            'principal_type' => $principal->isOwner() ? self::OWNER : self::STAFF,
            'seller_id' => $principal->sellerId(),
            'staff_id' => $principal->staffId(),
            'role' => $principal->staff?->role?->name,
            'permissions' => array_values(array_filter($this->permissions->allKeys(), $principal->can(...))),
            'market_scope' => $grant === null ? self::MARKET_SCOPE_UNRESTRICTED : self::MARKET_SCOPE_LIMITED,
            'allowed_markets' => $grant,
            'storefronts_enabled' => StorefrontResolver::fromConfig()->isEnabled(),
        ];

        if ($grant === null) {
            return $profile;
        }

        return array_slice($profile, 0, 7, preserve_keys: true)
            + ['permissions_by_market' => $this->permissionsByMarket(principal: $principal, grant: $grant)]
            + array_slice($profile, 7, preserve_keys: true);
    }

    /**
     * What the caller may do in each granted market, keyed by market code, in the order of the grant.
     *
     * The owner holds every permission in every market they were granted, because there is no role above them; a staff member holds what the role of that
     * market grants, which is the role on their staff record unless the grant names another one.
     *
     * @param  list<string>  $grant
     * @return array<string, list<string>>
     */
    private function permissionsByMarket(SellerPrincipal $principal, array $grant): array
    {
        $byMarket = [];

        foreach ($grant as $market) {
            $inMarket = $principal->withPermissions($this->markets->permissionsIn(principal: $principal, market: $market));
            $byMarket[$market] = array_values(array_filter($this->permissions->allKeys(), $inMarket->can(...)));
        }

        return $byMarket;
    }
}
