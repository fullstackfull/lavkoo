<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Exceptions\UnknownFulfillmentSourceException;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\Support\WhatAMarketsOwnGoodsAreFulfilledBy;
use App\Services\Fulfillment\Support\WhatAnUnknownSourceDoes;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What fulfils a market's OWN goods -- the products of that market that name no carrier of their own (C-03).
 *
 * In-house is an implicit NULL on a product, and for the one country this platform has served that is right: the shop and the country
 * are the same thing. For a second country it is a guess dressed as a default, because a Kuwaiti product that names no carrier would be
 * packed by the Saudi shop, and nothing anywhere said what Kuwait's own goods are fulfilled by.
 *
 * `fulfillment.market_own_goods_source` is the answer, and it ships EMPTY: every market keeps the implicit in-house default, a Saudi
 * checkout freezes exactly the rows it freezes today, and -- the first test -- an in-house line makes not one query it did not make
 * before, because the market of a line is only looked up once some market names a source.
 */
class MarketOwnGoodsSourceTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->productsCanCarryAMarket();
    }

    private function productsCanCarryAMarket(): void
    {
        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        }
    }

    /** @param array<string, string> $sources market code => carrier code */
    private function marketsFulfilTheirOwnGoodsWith(array $sources): void
    {
        config(['fulfillment.market_own_goods_source' => $sources]);
    }

    /** The market column is NOT NULL with an empty default, so a product of no market carries the empty string. */
    private function aProductOf(string $market, int $id = 10, ?string $ownSource = null): int
    {
        $this->makeProduct($id);
        DB::table('products')->where('id', $id)->update([
            StorefrontCatalog::COLUMN => $market,
            'fulfillment_provider' => $ownSource,
        ]);

        return $id;
    }

    /**
     * The checkout's own call, with the line's copy of the product carrying only what the checkout puts in it: a product whose copy
     * names no market is read from the row, which is the path a market default has to work on.
     */
    private function capture(int $productId, ?string $ownSource = null, int $detailId = 1, int $orderId = 1): ?ProviderFulfillmentItem
    {
        return app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: $orderId,
            orderDetailId: $detailId,
            product: ['id' => $productId, 'name' => 'Item', 'code' => 'SKU' . $productId, 'fulfillment_provider' => $ownSource, 'variation' => '[]'],
            variant: null,
            quantity: 2,
            unitPrice: 25.0,
            netAmount: 50.0,
            lineTax: 0.0,
            taxModel: 'include',
        );
    }

    public function test_as_it_ships_no_market_names_a_source_and_an_in_house_line_asks_nothing_about_markets(): void
    {
        $this->aProductOf('KW');
        $this->makeOrder(1);

        $this->assertFalse(WhatAMarketsOwnGoodsAreFulfilledBy::anyMarketNamesOne(), 'the map ships empty');
        $this->assertNull(WhatAMarketsOwnGoodsAreFulfilledBy::forMarket('KW'));

        DB::enableQueryLog();
        $item = $this->capture(10);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNull($item, 'an unsourced line is in-house, exactly as today');
        $this->assertSame(0, ProviderFulfillmentItem::count());
        $this->assertSame([], array_values(array_filter(
            array_column($queries, 'query'),
            static fn (string $sql): bool => str_contains($sql, StorefrontCatalog::COLUMN),
        )), 'and nothing asked the products table which market the line is in');
    }

    public function test_a_market_that_names_a_source_has_its_own_goods_frozen_with_it(): void
    {
        $this->aProductOf('KW');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['KW' => ProviderCode::TorodPoint->value]);

        $item = $this->capture(10);

        $this->assertNotNull($item, 'the line is owned by the market\'s own carrier, so it is frozen');
        $this->assertSame(ProviderCode::TorodPoint->value, $item->provider);
        $this->assertSame(ProviderFulfillmentItem::STATUS_UNROUTED, $item->status);
    }

    public function test_a_market_that_names_none_keeps_the_implicit_in_house_default(): void
    {
        $this->aProductOf('SA');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['KW' => ProviderCode::TorodPoint->value]);

        $this->assertNull($this->capture(10), 'Saudi Arabia names no source, so its own goods are the shop\'s, as they always were');
        $this->assertSame(0, ProviderFulfillmentItem::count());
    }

    public function test_a_product_that_names_its_own_carrier_is_not_moved_by_its_markets_default(): void
    {
        $this->aProductOf('KW', ownSource: ProviderCode::M5azn->value);
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['KW' => ProviderCode::TorodPoint->value]);

        $item = $this->capture(10, ProviderCode::M5azn->value);

        $this->assertNotNull($item);
        $this->assertSame(ProviderCode::M5azn->value, $item->provider, 'a product assigned to a carrier was assigned to it on purpose');
    }

    public function test_a_market_is_matched_whatever_case_the_row_or_the_setting_is_written_in(): void
    {
        $this->aProductOf('kw');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['Kw' => ProviderCode::TorodPoint->value]);

        $this->assertSame(ProviderCode::TorodPoint->value, $this->capture(10)?->provider);
    }

    public function test_a_product_of_no_market_is_not_given_another_markets_source(): void
    {
        $this->aProductOf('');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['KW' => ProviderCode::TorodPoint->value]);

        $this->assertNull($this->capture(10), 'a product that names no market is in no market\'s default');
    }

    public function test_a_half_written_entry_names_nothing_and_leaves_the_others_standing(): void
    {
        $this->aProductOf('KW');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['QA' => '', 'KW' => ProviderCode::TorodPoint->value]);

        $this->assertNull(WhatAMarketsOwnGoodsAreFulfilledBy::forMarket('QA'));
        $this->assertSame(ProviderCode::TorodPoint->value, $this->capture(10)?->provider);
    }

    /** A misspelt source is an UNKNOWN source, not in-house: it follows the rule that already answers for one. */
    public function test_a_source_no_carrier_answers_for_follows_the_unknown_source_rule(): void
    {
        $this->aProductOf('KW');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['KW' => 'torod-point']);

        $this->assertSame(WhatAnUnknownSourceDoes::IN_HOUSE_TODAY, WhatAnUnknownSourceDoes::rule());
        $this->assertNull($this->capture(10), 'the default rule is today\'s platform, so a typo cannot stop a checkout');

        config(['fulfillment.unknown_source' => WhatAnUnknownSourceDoes::REFUSE]);
        $this->expectException(UnknownFulfillmentSourceException::class);
        $this->capture(10, detailId: 2);
    }

    /**
     * And the whole way: a line frozen by its market's default is one the real router moves into a real shipment.
     *
     * A snapshotter test alone would not see this, because the consequence of a snapshot is in the router -- it reads
     * `provider_fulfillment_items` and nothing else.
     */
    public function test_the_router_turns_a_markets_own_source_into_a_shipment(): void
    {
        $this->aProductOf('KW');
        $this->makeOrder(1);
        $this->marketsFulfilTheirOwnGoodsWith(['KW' => ProviderCode::TorodPoint->value]);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => 1, 'product_id' => 10, 'qty' => 2, 'price' => 25,
            'product_details' => json_encode(['id' => 10]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->capture(10, detailId: $detailId);

        app(FulfillmentRouter::class)->route(1);

        $shipment = ProviderFulfillment::query()->where('order_id', 1)->first();
        $this->assertNotNull($shipment, 'the market\'s own carrier gets the parcel');
        $this->assertSame(ProviderCode::TorodPoint->value, $shipment->provider);
    }
}
