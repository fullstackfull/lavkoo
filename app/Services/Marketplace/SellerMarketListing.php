<?php

namespace App\Services\Marketplace;

use App\Services\Storefront\StorefrontResolver;

/**
 * The markets the signed-in seller may put a product in, for the app that has to offer the choice (B-09).
 *
 * The seller app has no market concept because it has never been told what the choice is. `SellerMarketScope` already holds every part of
 * the answer -- the grant, the one market a request works in, the refusal when there is no one market -- and `ProductController` already
 * refuses a create that leaves the market unresolved. What was missing is that a seller could only find that refusal out by being refused.
 * So this composes what is already decided and decides nothing of its own; it reads no table the scope does not already read.
 *
 * **What the list means, state by state**, because an app that reads it wrongly offers a seller a market they cannot sell in:
 *
 *  - the grants are NOT in force -- `gcc.seller.market_scopes` off, or its table not migrated, which is every installation today -- so a
 *    new seller product takes the column's own default and nothing else is reachable. The list is the ONE default market, and
 *    `enabled` is false so an app can leave the picker out altogether. This is the Saudi platform, and the answer is the truth about it
 *    rather than an empty list an app would have nothing to show for;
 *  - the grants are in force and the shop holds ROWS: the list is those rows, which `grantOf()` has already intersected with the markets
 *    the platform is configured for, so a market an operator has removed from the configuration is not offered;
 *  - the grants are in force and the shop holds NO row: no row is no limit, so the list is every configured market -- the same reading
 *    `SellerMarketScope` applies everywhere else, never "nothing";
 *  - the grants are in force and the rows grant NOTHING the platform serves: `[]`, and `may_create` is false with the refusal that says
 *    so. An empty list and a refusal, not a silent fallback to the default market, which is a market the seller does not hold.
 *
 * `working_market` is the one market the request itself works in, which is what the app should pre-select: the market the request named, or
 * the only market of the grant when it named none. It is null when the grant spans several markets and the request named none -- which is
 * exactly when `may_create` is false and the refusal asks for one.
 *
 * The refusal is given BOTH as its key and as words, because an app has to branch on one and show the other, and a refusal that surfaces as
 * a bare key is a code shown to a seller trying to find out what to do.
 */
final class SellerMarketListing
{
    public function __construct(
        private readonly SellerMarketScope $markets,
    ) {
    }

    /**
     * @return array{enabled: bool, default_market: string, working_market: ?string, markets: list<array{country: string, currency: string, active: bool, is_default: bool}>, may_create: bool, refusal: ?string, refusal_message: ?string}
     */
    public function payload(): array
    {
        $resolver = StorefrontResolver::fromConfig();
        $inForce = $this->markets->enabled();
        $refusal = $inForce ? $this->markets->whyItMayNotCreateInAMarket() : null;
        $default = $resolver->defaultCountry();
        $reachable = array_column($resolver->activeStorefronts(), 'country');

        return [
            'enabled' => $inForce,
            'default_market' => $default,
            'working_market' => $inForce ? $this->markets->workingMarket() : null,
            'markets' => array_map(
                static fn (string $country): array => [
                    'country' => $country,
                    'currency' => $resolver->currencyOf($country),
                    // Whether the market is serving visitors. A seller prepares a catalogue before a storefront opens (the same reason
                    // StaffCountryScope::configuredCountries() lists every market, open or not), so a market that is not yet active is
                    // offered and said to be inactive rather than left out.
                    'active' => in_array($country, $reachable, true),
                    'is_default' => $country === $default,
                ],
                $this->offered(resolver: $resolver),
            ),
            'may_create' => $refusal === null,
            'refusal' => $refusal,
            'refusal_message' => $refusal === null ? null : translate($refusal),
        ];
    }

    /**
     * The markets a new product of this seller could be put in.
     *
     * @return list<string>
     */
    private function offered(StorefrontResolver $resolver): array
    {
        if (!$this->markets->enabled()) {
            return [$resolver->defaultCountry()];
        }

        return $this->markets->boundGrant() ?? $this->markets->configuredMarkets();
    }
}
