<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which shipping methods a storefront lists and charges (H1-14).
 *
 * The platform's decision is that local shipping rules belong to a storefront and its selling company and are NEVER inherited by another: a method made
 * for the Saudi store must not quietly become Kuwait's delivery option at a Saudi price in Saudi riyals. So a method carries the market it belongs to, and
 * this is where the shipping-method vocabulary for that lives.
 *
 * The RULE itself -- that an unmarked row is the default storefront's, that every other market reads only what names it, that the market is compared byte
 * for byte, and that none of it applies while storefronts are off or before the migration -- is `StorefrontMarketRows`, shared with every other table that
 * has rows of a market. Two copies of that reasoning is how the two tables would come to disagree.
 *
 * It is dormant: with storefronts off, or before the migration, this narrows nothing at all and every listing is the listing it has always been, by the
 * same query, in the same order. That is what the first test of this class asserts.
 */
#[Singleton]
final class StorefrontShippingMethods
{
    public const COLUMN = StorefrontMarketRows::COLUMN;

    private const TABLE = 'shipping_methods';

    public function __construct(private readonly StorefrontMarketRows $rows)
    {
    }

    /** Whether a shipping method can carry a market at all, and whether anything should read it. */
    public function applies(): bool
    {
        return $this->rows->applies(self::TABLE);
    }

    /**
     * Narrows a query of shipping methods to the storefront given, or to the one the request is being served from.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     */
    public function narrow(Builder $query, ?string $country = null): Builder
    {
        return $this->rows->narrow($query, self::TABLE, $country);
    }

    /**
     * Whether this market has shipping methods of its OWN -- methods that name it, not the unmarked methods of the default storefront.
     *
     * This is what decides whether a market with its own currency may charge a method at all. The unmarked methods are deliberately not counted: they are
     * the default storefront's, typed in the platform's money, and charging them in another currency is the mistake the whole override exists to prevent.
     * A method that is switched off is not a method this market can charge.
     */
    public function marketHasOwnMethods(?string $country = null): bool
    {
        return $this->rows->marketOwnsRows(self::TABLE, $country);
    }

    /** The market a method is given when one is made in a storefront, or null while this does not apply. */
    public function stampFor(?string $country = null): ?string
    {
        return $this->rows->stampFor(self::TABLE, $country);
    }

    public function forget(): void
    {
        $this->rows->forget();
    }
}
