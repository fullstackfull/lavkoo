<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\ChannelMarketMatch;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Container\Attributes\Singleton;

/**
 * Which market a stock pool belongs to, and who may take units out of it (M-656, M-745).
 *
 * A pool is one physical stock as the channels are offered it, and its key was `(location, product, variant)` -- three things that name no
 * country. The product row names a market and the shelf names a market, but the pool named neither, so the row the single writer divides
 * on could not be attributed to one, two countries' accounts offering one SKU met in it, and the division written onto each listing
 * (`allocation.pool`) said nothing about whose stock had been shared out.
 *
 * **The pool's market is the PRODUCT's market**, because the product row is what the stock belongs to on this platform -- that is the
 * design the whole six-market catalogue rests on, and it is why some four hundred stock and price call sites needed no change at all. The
 * shelf's market is not asked again here: whether a listing may be fed from a shelf of another market is {@see ChannelMarketMatch}'s
 * question and is answered there, for the listing, with a reason an operator can read.
 *
 * `''` is the DEFAULT storefront's pool, and every pool this platform has today is one. That is `StorefrontMarketRows`' rule for every
 * market-owning table -- a row that names no market is the default storefront's -- so a product explicitly marked `SA` and a product marked
 * nothing share one pool rather than splitting one shelf's units into two, which is what makes switching this on safe.
 *
 * **Who may take from a pool is a narrower question than who may hold a listing, and deliberately so.** `ChannelMarketMatch` lets an
 * account whose settings name NO country through, because refusing it would stop every installation's publishing the day storefronts are
 * switched on -- and that is right for a pool of the default market, which is every pool that exists today. A pool of ANOTHER market has
 * no such history: nothing published from it before this, so an account that cannot say it sells in that market is not given units of it.
 * Those units are then counted as an outsider's rather than freed (see PoolOutsiders), which is the safe direction.
 *
 * Dormant three times over: while `gcc.storefronts.enabled` is off, before the products carry a market, and before the pool table keys by
 * one, every pool is `''` and every account is admitted -- the platform exactly as it is today. The first test of it asserts that.
 */
#[Singleton]
final class PoolMarket
{
    private const POOLS = 'inventory_pools';

    private const PRODUCTS = 'products';

    public function __construct(
        private readonly StorefrontMarketRows $rows,
        private readonly ChannelMarketMatch $markets,
    ) {
    }

    /** Whether the pool table keys its rows by a market at all. A fact about the database, which a writer needs as much as a reader. */
    public function keyedByMarket(): bool
    {
        return $this->rows->tableHasTheColumn(self::POOLS);
    }

    /** Whether a pool's market is worked out at all: the switch, the products' own market column, and the pool table's key. */
    public function applies(): bool
    {
        return $this->keyedByMarket() && $this->rows->applies(self::PRODUCTS);
    }

    /** The market the pools of this product belong to, or `''` for the default storefront's -- which is every pool that exists today. */
    public function ofProduct(int|string|null $productId): string
    {
        if (!$this->applies() || $productId === null) {
            return PoolKey::DEFAULT_MARKET;
        }

        $market = $this->rows->marketKeyOf(self::PRODUCTS, $productId)[StorefrontMarketRows::COLUMN] ?? null;

        return $market === null || $market === $this->rows->defaultCountry() ? PoolKey::DEFAULT_MARKET : $market;
    }

    /** Whether this account may be given units of a pool of this market. */
    public function admits(SalesChannelAccount $account, string $poolMarket): bool
    {
        return $poolMarket === PoolKey::DEFAULT_MARKET || $this->markets->marketOf($account) === $poolMarket;
    }
}
