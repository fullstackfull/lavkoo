<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationMarket;
use App\Services\SalesChannels\Support\ChannelAccountCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontMarketRows;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * Whether a marketplace listing, the product it points at and the shelf its stock comes from are all of ONE market (E11-F19; C2-05, C2-12).
 *
 * The stock pool is where this matters. A pool is keyed by a product, a variant and a location, and every linked listing of every opted-in
 * account shares it -- so without this, a Saudi Amazon account could take a share of a KUWAITI product's units and advertise them on the
 * Saudi marketplace, and a listing fed from a Kuwaiti shelf could publish those units to a Saudi channel. Neither is a mistake an operator
 * would see: the figures add up, and only the market is wrong.
 *
 * Which market each of the three belongs to is asked of the one service that already knows:
 *
 *  - the ACCOUNT's is `ChannelAccountCountry`'s, read from the account's own settings (Trendyol names its storefront, noon its country, an
 *    Amazon account its marketplaces). An account whose settings name NO country is not narrowed, which is that class's own recorded rule
 *    and today's behaviour: refusing such an account would stop every installation's publishing the day storefronts are switched on, and
 *    this is said out loud and tested rather than left as a silence. An account that sells where this platform has no catalogue at all is
 *    refused outright, because nothing of ours can be its listing.
 *  - the PRODUCT's and the LOCATION's are `StorefrontMarketRows`' -- an unmarked row is the DEFAULT storefront's, compared byte for byte --
 *    so no second copy of that rule lives here.
 *
 * It is dormant: with `gcc.storefronts.enabled` off, or before the market columns exist, every answer is null and nothing is refused, which
 * is the platform exactly as it is today. The first test of it asserts that.
 */
#[Singleton]
final class ChannelMarketMatch
{
    public const PRODUCT_OF_ANOTHER_MARKET = 'product_of_another_market';

    public const LOCATION_OF_ANOTHER_MARKET = 'location_of_another_market';

    public const ACCOUNT_SELLS_WHERE_WE_HAVE_NO_CATALOGUE = 'account_sells_where_we_have_no_catalogue';

    private const PRODUCTS = 'products';

    public function __construct(
        private readonly ChannelAccountCountry $accountCountry,
        private readonly InventoryLocationMarket $locationMarket,
        private readonly StorefrontMarketRows $rows,
    ) {
    }

    /**
     * Why this listing may take no stock, because of the market it or its stock belongs to.
     *
     * @return string|null null when the three agree, or when nothing here applies
     */
    public function refusal(SalesChannelAccount $account, SalesChannelProductMapping $listing, ?InventoryLocation $location): ?string
    {
        if ($this->accountCountry->sellsWhereWeHaveNoCatalogue($account)) {
            return self::ACCOUNT_SELLS_WHERE_WE_HAVE_NO_CATALOGUE;
        }

        $market = $this->marketOf($account);
        if ($market === null) {
            return null;
        }

        if (!$this->rows->rowIsServedIn(self::PRODUCTS, $this->marketOfProduct(productId: $listing->product_id), $market)) {
            return self::PRODUCT_OF_ANOTHER_MARKET;
        }

        if ($location !== null && !$this->locationMarket->isServedIn($location, $market)) {
            return self::LOCATION_OF_ANOTHER_MARKET;
        }

        return null;
    }

    /** @return string|null the market this account sells in, or null when it says none, or when nothing here applies */
    public function marketOf(SalesChannelAccount $account): ?string
    {
        return $this->rows->applies(self::PRODUCTS) ? $this->accountCountry->of($account) : null;
    }

    /** @return string|null the market the product belongs to, or null when the product is gone or names none */
    private function marketOfProduct(int|string|null $productId): ?string
    {
        if ($productId === null) {
            return null;
        }

        $market = DB::table(self::PRODUCTS)->where('id', $productId)->value(StorefrontCatalog::COLUMN);

        return $market === null ? null : (string) $market;
    }
}
