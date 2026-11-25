<?php

namespace App\Services\Storefront;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which per-category shipping costs a storefront charges (H1-14).
 *
 * The same decision as for methods: a cost typed for the Saudi store, in Saudi riyals, must not become Kuwait's cost for that category. So a category cost
 * carries the market it belongs to, and the rule for reading it is `StorefrontMarketRows`, shared with the methods -- an unmarked row is the default
 * storefront's, every other market reads only what names it, and nothing is narrowed while storefronts are off or before the migration.
 *
 * One difference from a method, and it matters for the money: a category cost row has no `status` column, so there is no such thing as one that is
 * switched off. Every row of a market counts as that market's own.
 */
#[Singleton]
final class StorefrontCategoryShippingCosts
{
    public const COLUMN = StorefrontMarketRows::COLUMN;

    private const TABLE = 'category_shipping_costs';

    public function __construct(private readonly StorefrontMarketRows $rows)
    {
    }

    public function applies(): bool
    {
        return $this->rows->applies(self::TABLE);
    }

    /** @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query */
    public function narrow(Builder $query, ?string $country = null): Builder
    {
        return $this->rows->narrow($query, self::TABLE, $country);
    }

    /**
     * Whether this market has category costs of its OWN.
     *
     * What decides whether a market trading in its own currency may be priced `category_wise` at all: its own rows were typed in its own money, and the
     * unmarked rows of the default storefront never were.
     */
    public function marketHasOwnCosts(?string $country = null): bool
    {
        return $this->rows->marketOwnsRows(self::TABLE, $country, activeColumn: null);
    }

    public function stampFor(?string $country = null): ?string
    {
        return $this->rows->stampFor(self::TABLE, $country);
    }

    public function forget(): void
    {
        $this->rows->forget();
    }
}
