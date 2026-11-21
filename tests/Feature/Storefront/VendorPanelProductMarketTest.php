<?php

namespace Tests\Feature\Storefront;

use App\Models\Seller;
use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\TestCase;

/**
 * A product added from the **vendor web panel** lands in the market the shop was granted (K-09, K-04).
 *
 * The panel reaches the same insert as the seller app and carries no grant: `seller_market` is declared on the seller API group,
 * and the middleware resolves a principal the API's token middleware put on the request, which a session request never has. So a
 * seller granted Dubai alone added a product in the panel and it took the column's own default, SA — a market they do not hold.
 *
 * Measured at the service the panel's controller calls with the panel's own guard signed in, which is how the admin side of this
 * column is measured too (AdminProductCountryTest). It is **not** a post to the panel's form: that form is a multipart body with
 * image uploads and a tax tail, and driving it would measure the form rather than the market. The column in the row the service
 * would write is the measurement, and the table default is SA, so a row that says AE can only have got it from the grant.
 */
class VendorPanelProductMarketTest extends TestCase
{
    use BuildsSellerMarketWorld;
    use DatabaseTransactions;
    use GrantsSellerMarkets;

    /** What the products column gives a row that nobody chose a market for. */
    private const TABLE_DEFAULT = 'SA';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
        $this->createTheMarketGrantTable();
        $this->giveProductsTheirStockColumns();
    }

    protected function tearDown(): void
    {
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    public function test_a_product_added_in_the_panel_lands_in_the_shops_granted_market(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $market = $this->marketTheProductWouldTake();

        $this->assertSame('AE', $market, 'the panel saves the product in the market the shop was granted');
        $this->assertNotSame(self::TABLE_DEFAULT, $market, 'and not the column default, which is the market the shop does not hold');
    }

    public function test_a_shop_granted_several_markets_is_told_which_market_is_missing(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');
        $this->grantMarket(sellerId: self::SELLER, market: 'KW');

        $this->actingAsTheShopInThePanel();

        $this->assertSame(
            'name_the_market_this_product_belongs_to_your_account_works_in_more_than_one',
            app(SellerMarketScope::class)->whyItMayNotCreateInAMarket(),
            'two markets and none named is no market, so the create says which one is missing rather than landing on a default',
        );
    }

    public function test_a_shop_with_no_grant_takes_exactly_what_it_always_took(): void
    {
        $this->switchMarketScopesOn();

        $this->assertSame(
            [],
            $this->attributesTheProductWouldTake(),
            'a shop with no grant adds nothing to the insert, so the row is the row it always was',
        );
    }

    public function test_the_switch_being_off_takes_exactly_what_it_always_took(): void
    {
        config([SellerMarketScope::SWITCH => false]);
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->assertSame(
            [],
            $this->attributesTheProductWouldTake(),
            'the switch off reads no row at all, so a granted shop adds nothing either',
        );
    }

    private function marketTheProductWouldTake(): ?string
    {
        return $this->attributesTheProductWouldTake()[StorefrontCatalog::COLUMN] ?? null;
    }

    /** @return array<string, string> */
    private function attributesTheProductWouldTake(): array
    {
        $this->actingAsTheShopInThePanel();

        return app(SellerMarketScope::class)->newProductAttributes();
    }

    /** Signed in on the panel's own guard, which is the only thing a session request gives the insert. */
    private function actingAsTheShopInThePanel(): void
    {
        $this->actingAs(Seller::findOrFail(self::SELLER), 'seller');
    }
}
