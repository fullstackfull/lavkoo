<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\PoolMarket;
use App\Services\Storefront\StorefrontMarketRows;

/**
 * Which pool: a product, or one variant of it, at one location, in one market.
 *
 * The market is the last part of the identity and the newest (M-656, M-745): a pool is one physical stock as the channels are offered it,
 * and without it two countries' accounts offering one SKU met in one row. `''` is the DEFAULT storefront's pool, which is every pool this
 * platform has today -- so the key of a pool that exists now is unchanged, in its columns and in its text, and {@see PoolMarket} answers
 * `''` for every product until storefronts are switched on.
 *
 * **The text is what is persisted, so its shape for an existing pool is fixed.** `allocation.pool` on each listing is written by the
 * division and read back by `PoolListings` and `PoolOutsiders` -- the second of which decides whether units already on a channel are
 * counted as taken, so a key that stopped matching its own stored string would free units that are still on sale. The market is therefore
 * APPENDED, and only when it is not the default one: every string written before this still names the pool it named.
 *
 * `columns()` is the identity as the three columns that `inventory_pools`, `channel_stock_events` and `inventory_reservations` all share.
 * The market belongs to the pool table alone, so it is `poolColumns()` that carries it -- and only once the migration has added it.
 */
final readonly class PoolKey
{
    /** The pool of the DEFAULT storefront. Every pool made before a market could be named is one of these, and so is every pool of the default market. */
    public const DEFAULT_MARKET = '';

    public const MARKET_COLUMN = StorefrontMarketRows::COLUMN;

    public function __construct(
        public int $locationId,
        public int $productId,
        public string $variantKey = '',
        public string $market = self::DEFAULT_MARKET,
    ) {
    }

    public static function ofListing(SalesChannelProductMapping $listing, int $locationId): self
    {
        return new self(
            locationId: $locationId,
            productId: (int) $listing->product_id,
            variantKey: (string) $listing->variant_key,
            market: app(PoolMarket::class)->ofProduct($listing->product_id),
        );
    }

    /** @return array{location_id: int, product_id: int, variant_key: string} */
    public function columns(): array
    {
        return ['location_id' => $this->locationId, 'product_id' => $this->productId, 'variant_key' => $this->variantKey];
    }

    /**
     * The identity as the pool table's own key: the three columns, and the market while that table keys by it.
     *
     * @return array<string, int|string>
     */
    public function poolColumns(): array
    {
        return app(PoolMarket::class)->keyedByMarket()
            ? [self::MARKET_COLUMN => $this->market] + $this->columns()
            : $this->columns();
    }

    /** @return list<string> the pool table's unique key as it stands, for an upsert that has to name it */
    public static function poolUniqueBy(): array
    {
        return array_keys((new self(locationId: 0, productId: 0))->poolColumns());
    }

    public function __toString(): string
    {
        return $this->locationId . ':' . $this->productId . ':' . $this->variantKey
            . ($this->market === self::DEFAULT_MARKET ? '' : '@' . $this->market);
    }
}
