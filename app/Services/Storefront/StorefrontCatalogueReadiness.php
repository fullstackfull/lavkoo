<?php

namespace App\Services\Storefront;

use App\Models\Product;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a storefront has anything a customer of that market could buy (I2-12).
 *
 * The preflight asked a market about its legal texts, its currency, its selling company, its tax and its gateway, and nothing about
 * whether it has a catalogue. A market opened with no product of its own answers every page with an empty list: the legal texts are
 * published, the currency is decided, the gateway works, and the storefront sells nothing. That is a market an operator means to open
 * next week, opened today, and the only thing that reports it is a customer.
 *
 * **The question is asked the way the STOREFRONT asks it, not with a count of rows.** A product is counted only when it belongs to this
 * market AND `Product::active()` lets it through -- the same scope the catalogue, the search and the home page use, which also answers
 * for the business mode, the brand setting and the digital-product setting. A count of `products` rows would call a market ready whose
 * every product is switched off, out of a brand that is switched off, or of a type this installation does not sell.
 *
 * **A market before the catalogue migration has run is ready, and that is the Saudi platform of today.** While `products` has no
 * storefront column there is one catalogue for the whole platform, so the default market's products are every market's and the question
 * has no per-market answer to give; refusing then would refuse the one market that is actually selling.
 *
 * **IT ANSWERS TRUE UNTIL IT IS SWITCHED ON** (`gcc.storefronts.sale_gate.needs_a_catalogue`, which ships false), for the same reason
 * {@see \App\Services\Payments\StorefrontPaymentReadiness} does: what "ready to sell" includes beyond the legal texts is the owner's open
 * decision on D1-16, and a market must not be refused by a prerequisite nobody has been asked about. With it off this costs one config
 * read and asks the database nothing.
 */
#[Singleton]
final class StorefrontCatalogueReadiness
{
    public const NOT_ASKED = 'not_asked';

    public const ONE_CATALOGUE_FOR_THE_PLATFORM = 'the_catalogue_is_not_kept_per_storefront_yet_so_every_market_has_the_one_catalogue';

    public const READY = 'ready';

    public const NO_SELLABLE_PRODUCT = 'no_product_of_this_storefront_is_one_a_customer_of_it_could_buy';

    /** Whether this is asked of a market at all. */
    public function isAsked(): bool
    {
        return (bool) config('gcc.storefronts.sale_gate.needs_a_catalogue', false);
    }

    public function isReady(string $country): bool
    {
        return $this->reasonFor(country: $country) !== self::NO_SELLABLE_PRODUCT;
    }

    /** Why this market is or is not ready, in words an operator can act on. */
    public function reasonFor(string $country): string
    {
        if (!$this->isAsked()) {
            return self::NOT_ASKED;
        }

        if (!StorefrontCatalog::columnReady()) {
            return self::ONE_CATALOGUE_FOR_THE_PLATFORM;
        }

        return $this->sellableProductsOf(country: $country)->exists() ? self::READY : self::NO_SELLABLE_PRODUCT;
    }

    /** How many products of this market a customer of it could buy, for a report that wants the figure rather than the answer. */
    public function sellableProductCountOf(string $country): ?int
    {
        return StorefrontCatalog::columnReady() ? $this->sellableProductsOf(country: $country)->count() : null;
    }

    /**
     * Without the global scopes, and that is load-bearing rather than tidy: `Product` carries `StaffCountryProductScope`, so an employee
     * restricted to one market would be answered "no sellable product" about a market full of them -- and a readiness report that says a
     * market has no catalogue because of who is reading it is worse than no report. `Product` has no soft deletes, so nothing hidden comes
     * back with them off, and the eager loads the other scope adds are of no use to a count.
     */
    private function sellableProductsOf(string $country): Builder
    {
        return Product::query()
            ->withoutGlobalScopes()
            ->where(StorefrontCatalog::COLUMN, strtoupper(trim($country)))
            ->active();
    }
}
