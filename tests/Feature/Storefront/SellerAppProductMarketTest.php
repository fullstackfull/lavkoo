<?php

namespace Tests\Feature\Storefront;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\TestCase;

/**
 * A product added from the seller app is saved in the market the seller works in, not the table default (K-04, and the half B-09
 * and B-12.1 wait on).
 *
 * The product is added by `POST /api/v3/seller/products/add` with a real bearer token, so the whole chain runs: `seller_api_auth`
 * resolves the principal, `seller_market` resolves the grant from the rows and refuses a market outside it, `seller_can` checks
 * `products.manage`, and the controller writes its own insert. What is asserted is the row the real insert left behind.
 *
 * The products column defaults to SA, which is what every seller product has taken until now, so a test that finds AE in the row
 * can only have got it from the market the seller works in. The world's tables are inferred from the code that reads them and
 * stop short of the digital-catalogue and tax tail the route runs after the insert, so the status is checked for not being a
 * refusal rather than for 200; the row is the measurement.
 */
class SellerAppProductMarketTest extends TestCase
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
        $this->createTheDigitalCatalogueTables();
    }

    protected function tearDown(): void
    {
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    public function test_a_product_added_from_the_app_lands_in_the_market_the_seller_works_in(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $added = $this->sellerApi('post', 'products/add', $this->addProductBody());

        $this->assertNotSame(403, $added->status(), 'a seller adding a product in their own market is not refused');
        $this->assertSame('AE', $this->marketOfTheAddedProduct(), 'the product is saved in the market the seller works in, not the table default');
        $this->assertNotSame(self::TABLE_DEFAULT, $this->marketOfTheAddedProduct(), 'and the table default is not what it took');
    }

    public function test_a_seller_naming_one_of_their_markets_lands_the_product_there(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'KW');

        $added = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => 'KW',
        ])->json('post', '/api/v3/seller/products/add', $this->addProductBody());

        $this->assertNotSame(403, $added->status(), 'a market inside the grant is not refused');
        $this->assertSame('KW', $this->marketOfTheAddedProduct(), 'the product is saved in the market the app named, because the grant holds it');
    }

    public function test_a_market_outside_the_grant_adds_no_product_at_all(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $refused = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => 'AE',
        ])->json('post', '/api/v3/seller/products/add', $this->addProductBody());

        $refused->assertStatus(403);
        $this->assertSame('market_scope', $refused->json('errors.0.code'));
        $this->assertNull($this->marketOfTheAddedProduct(), 'a refused add writes nothing');
    }

    public function test_a_seller_of_several_markets_who_names_none_is_told_which_market_is_missing(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $refused = $this->sellerApi('post', 'products/add', $this->addProductBody());

        $refused->assertStatus(403);
        $this->assertSame(
            ['Name the market this product belongs to: your account works in more than one'],
            $refused->json('errors.' . SellerMarketScope::REQUEST_FIELD) ?? array_column((array) $refused->json('errors'), 'message'),
            'the seller is told what is missing rather than having the product landed in the table default',
        );
        $this->assertNull($this->marketOfTheAddedProduct(), 'and nothing is written while the market is unresolved');
    }

    public function test_a_seller_with_no_grant_adds_exactly_what_they_always_added(): void
    {
        $this->switchMarketScopesOn();

        $added = $this->sellerApi('post', 'products/add', $this->addProductBody());

        $this->assertNotSame(403, $added->status(), 'a seller with no market grant is not refused');
        $this->assertSame(self::TABLE_DEFAULT, $this->marketOfTheAddedProduct(), 'the row keeps the column default, as it always has');
    }

    public function test_the_switch_being_off_adds_exactly_what_it_always_added(): void
    {
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $added = $this->sellerApi('post', 'products/add', $this->addProductBody());

        $this->assertNotSame(403, $added->status(), 'a grant nobody reads refuses nothing');
        $this->assertSame(self::TABLE_DEFAULT, $this->marketOfTheAddedProduct(), 'and a grant nobody reads changes no row');
    }

    private function marketOfTheAddedProduct(): ?string
    {
        $market = DB::table('products')->where('code', 'FROMAPP01')->value('storefront_country');

        return $market === null ? null : (string) $market;
    }
}
