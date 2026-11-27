<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GrantsSellerMarkets;

/**
 * The vendor web panel READS what the shop was granted and no other market (K-09, M2-03).
 *
 * The panel's writes were already held to the grant: a new product takes the shop's one market, and a shop granted several is told to name
 * one (VendorPanelProductMarketTest). Its reads were not. `seller_market` is declared on the seller API group and its middleware resolves a
 * principal the API's token middleware put on the request, which a SESSION request never has, so every list, picker, report and
 * lookup-by-id of `app/Http/Controllers/Vendor/` answered across every market. A seller granted Dubai alone could open, price, discount,
 * switch off and barcode every product the shop held in Riyadh, and read every Riyadh order.
 *
 * Measured through the real panel routes with the real middleware on, because the hole was in the middleware and not in a service: a test
 * that called a service would have passed before the fix.
 *
 * The first section is the dormancy: with the switch off, and for a shop with no grant row, every one of these screens answers exactly what
 * it answered before -- the same status code, the same redirect, the same content.
 */
class VendorPanelMarketReadsTest extends VendorPanelTestCase
{
    use GrantsSellerMarkets;

    /** The market the shop is granted in the tests that grant one, and the market its own products are put in. */
    private const GRANTED_MARKET = 'AE';

    /** The market the shop is never granted: its products are the ones the panel must not reach. */
    private const OTHER_MARKET = 'SA';

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        $this->createTheMarketGrantTable();
        StorefrontCatalog::flush();
        $this->putProductInMarket(productId: self::OWN_PRODUCT, market: self::OTHER_MARKET);
    }

    protected function tearDown(): void
    {
        $this->dropOnlyTheMarketGrantTable();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function putProductInMarket(int $productId, string $market): void
    {
        DB::table('products')->where('id', $productId)->update([StorefrontCatalog::COLUMN => $market]);
    }

    private function enforceTheGrants(): void
    {
        config([SellerMarketScope::SWITCH => true]);
    }

    /** The shop is granted one market, and its own product is in the other: every screen that reaches it is a screen that crossed a market. */
    private function grantTheMarketTheProductIsNotIn(): void
    {
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
    }

    /** A staff member of the OTHER shop, which `insertStaffMember()` cannot make: its rows always belong to the shop under test. */
    private function insertStaffOfTheOtherShop(): int
    {
        return (int) DB::table('seller_staff')->insertGetId([
            'seller_id' => self::OTHER_SELLER, 'seller_role_id' => null, 'name' => 'another shops staff',
            'email' => 'staff@beta.test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function statusOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('status');
    }

    /** @return array<string, array{string}> the panel's own read-by-id screens of a product, by the address the browser asks for */
    public static function theProductScreensOfThePanel(): array
    {
        return [
            'the product page' => ['/vendor/products/view/' . self::OWN_PRODUCT],
            'the barcode page' => ['/vendor/products/barcode/' . self::OWN_PRODUCT],
        ];
    }

    // ---- as it ships: the panel is the panel it always was ---------------------------------------------------------------------------

    #[DataProvider('theProductScreensOfThePanel')]
    public function test_with_the_switch_off_every_screen_answers_exactly_what_it_answered_before(string $uri): void
    {
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);

        $response = $this->signInAs(self::SELLER)->get($uri);

        $response->assertOk()->assertSee('Own product');
    }

    public function test_with_the_switch_off_nothing_is_bound_to_the_request_at_all(): void
    {
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
        $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT)->assertOk();

        $this->assertNull(
            app(SellerMarketScope::class)->panelGrant(),
            'null is "this request is not narrowed" and [] is "it may see nothing": a dormant switch must answer the first',
        );
    }

    #[DataProvider('theProductScreensOfThePanel')]
    public function test_a_shop_with_no_grant_row_is_unrestricted_so_turning_the_switch_on_changes_nothing(string $uri): void
    {
        $this->enforceTheGrants();

        $this->signInAs(self::SELLER)->get($uri)->assertOk()->assertSee('Own product');
        $this->assertNull(app(SellerMarketScope::class)->panelGrant(), 'no row is no limit, exactly as it is on the seller API');
    }

    public function test_it_is_inert_before_the_grant_table_exists(): void
    {
        $this->enforceTheGrants();
        $this->dropOnlyTheMarketGrantTable();

        $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT)->assertOk()->assertSee('Own product');
    }

    // ---- with a grant: the panel reads one market ------------------------------------------------------------------------------------

    #[DataProvider('theProductScreensOfThePanel')]
    public function test_a_granted_shop_cannot_open_its_own_product_of_another_market(string $uri): void
    {
        $this->grantTheMarketTheProductIsNotIn();

        $response = $this->signInAs(self::SELLER)->get($uri);

        $response->assertRedirect(route('vendor.products.list', ['type' => 'all']));
        $response->assertDontSee('Own product');
    }

    #[DataProvider('theProductScreensOfThePanel')]
    public function test_a_granted_shop_still_opens_its_product_of_the_market_it_holds(string $uri): void
    {
        $this->grantTheMarketTheProductIsNotIn();
        $this->putProductInMarket(productId: self::OWN_PRODUCT, market: self::GRANTED_MARKET);

        $this->signInAs(self::SELLER)->get($uri)->assertOk()->assertSee('Own product');
    }

    public function test_the_grant_is_bound_to_the_panel_request_and_is_what_the_rows_hold(): void
    {
        $this->grantTheMarketTheProductIsNotIn();

        $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame([self::GRANTED_MARKET], app(SellerMarketScope::class)->panelGrant());
    }

    public function test_a_write_that_reaches_a_product_by_id_cannot_change_one_of_another_market_either(): void
    {
        $this->grantTheMarketTheProductIsNotIn();

        $this->signInAs(self::SELLER)
            ->postJson('/vendor/products/status-update', ['id' => self::OWN_PRODUCT, 'status' => 0])
            ->assertOk()->assertJson(['success' => 0]);

        $this->assertSame(1, $this->statusOf(self::OWN_PRODUCT), 'the lookup by id is narrowed, so the update never found a product');
    }

    public function test_a_shop_granted_a_market_the_platform_no_longer_serves_reads_nothing_rather_than_everything(): void
    {
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: 'ZZ');
        $this->putProductInMarket(productId: self::OWN_PRODUCT, market: self::GRANTED_MARKET);

        $response = $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame([], app(SellerMarketScope::class)->panelGrant(), 'a row naming no configured market grants nothing');
        $response->assertRedirect(route('vendor.products.list', ['type' => 'all']));
    }

    // ---- the panel's orders ------------------------------------------------------------------------------------------------------------

    /**
     * An order carries no market column of its own on this platform until the snapshot migration has run, and an order written before it
     * carries no record at all. What an order's market IS stays OrderStorefront's rule and is not restated by the panel: a grant that holds
     * the default market holds the orders with no record, because the whole platform reads such an order as the default market's.
     */
    private function insertPanelOrder(int $orderId, ?string $market): void
    {
        DB::table('orders')->insert([
            'id' => $orderId, 'customer_id' => 1, 'order_status' => 'confirmed', 'payment_status' => 'unpaid',
            'seller_is' => 'seller', 'seller_id' => self::SELLER, 'order_amount' => 10, 'paid_amount' => 0,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ] + ($market === null ? [] : ['storefront_country' => $market]));
    }

    /** @return list<int> the orders a bound panel request reaches, through the global scope every order query of the panel goes through */
    private function ordersThePanelReaches(): array
    {
        return Order::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function buildTheThreeOrdersOfTwoMarkets(): void
    {
        $this->insertPanelOrder(orderId: 900001, market: self::GRANTED_MARKET);
        $this->insertPanelOrder(orderId: 900002, market: self::OTHER_MARKET);
        $this->insertPanelOrder(orderId: 900003, market: null);
    }

    public function test_with_the_switch_off_the_panel_reaches_every_order_it_always_reached(): void
    {
        $this->buildTheThreeOrdersOfTwoMarkets();
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
        $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame([900001, 900002, 900003], $this->ordersThePanelReaches());
    }

    public function test_a_granted_shop_reaches_only_the_orders_of_the_market_it_holds(): void
    {
        $this->buildTheThreeOrdersOfTwoMarkets();
        $this->grantTheMarketTheProductIsNotIn();
        $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame([900001], $this->ordersThePanelReaches(), 'and not the other market, and not the order that recorded none');
    }

    public function test_a_grant_of_the_default_market_also_holds_the_orders_that_recorded_no_market(): void
    {
        $this->buildTheThreeOrdersOfTwoMarkets();
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: self::OTHER_MARKET);
        $this->signInAs(self::SELLER)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame(
            [900002, 900003],
            $this->ordersThePanelReaches(),
            'the platform reads an order with no record as the default market, and the panel does not invent a second rule',
        );
    }

    // ---- a staff member of the panel is held under the shop ----------------------------------------------------------------------------

    public function test_a_staff_member_of_the_panel_is_held_to_their_own_rows_under_the_shops(): void
    {
        $this->buildStaffTables();
        $staffId = $this->insertStaffMember(name: 'productsview', permissions: ['products.view']);
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
        $this->grantMarket(sellerId: self::SELLER, market: self::OTHER_MARKET);
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET, staffId: $staffId);

        $response = $this->signInAsStaff($staffId)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame(
            [self::GRANTED_MARKET],
            app(SellerMarketScope::class)->panelGrant(),
            'the panel signs a staff member in as their employer, so the shop was all the grant used to see',
        );
        $response->assertRedirect(route('vendor.products.list', ['type' => 'all']));
    }

    public function test_a_staff_member_with_no_row_of_their_own_takes_the_shops_grant_and_never_more(): void
    {
        $this->buildStaffTables();
        $staffId = $this->insertStaffMember(name: 'productsview', permissions: ['products.view']);
        $this->grantTheMarketTheProductIsNotIn();

        $this->signInAsStaff($staffId)->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $this->assertSame([self::GRANTED_MARKET], app(SellerMarketScope::class)->panelGrant());
    }

    public function test_a_staff_id_in_the_session_that_belongs_to_another_shop_never_reaches_the_grant_at_all(): void
    {
        $this->buildStaffTables();
        $staffId = $this->insertStaffOfTheOtherShop();
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
        $this->grantMarket(sellerId: self::OTHER_SELLER, market: self::OTHER_MARKET, staffId: $staffId);

        $response = $this->signInAs(self::SELLER)->withSession(['seller_staff_id' => $staffId])
            ->get('/vendor/products/view/' . self::OWN_PRODUCT);

        $response->assertRedirect(route('vendor.staff-auth.login'));
        $this->assertNull(
            app(SellerMarketScope::class)->panelGrant(),
            'the staff guard refuses the foreign session before the market scope is reached, so nothing is bound',
        );
    }

    /**
     * The same rule inside the scope itself, because the staff guard is not the only way into it: a session is not a grant, so a staff id
     * that is not this shop's is not read as a staff member at all and the shop's own grant answers.
     *
     * The grant row is written under THIS shop against that foreign id -- a row a deleted staff member left behind, or one whose id was
     * reused -- because that is the only shape in which reading the person instead of checking them changes the answer: the rows are keyed
     * on the shop AND the person, so a row under the other shop could never have been found anyway.
     */
    public function test_the_scope_reads_a_foreign_staff_session_as_the_shop_itself(): void
    {
        $this->buildStaffTables();
        $staffId = $this->insertStaffOfTheOtherShop();
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
        $this->grantMarket(sellerId: self::SELLER, market: self::OTHER_MARKET, staffId: $staffId);
        $this->signInAs(self::SELLER);
        session(['seller_staff_id' => $staffId]);

        $this->assertSame([self::GRANTED_MARKET], app(SellerMarketScope::class)->bindPanel(request: request()));
    }

    public function test_an_inactive_staff_member_of_this_shop_lends_no_market_either(): void
    {
        $this->buildStaffTables();
        $staffId = $this->insertStaffMember(name: 'productsview', permissions: ['products.view']);
        DB::table('seller_staff')->where('id', $staffId)->update(['status' => 'inactive']);
        $this->enforceTheGrants();
        $this->grantMarket(sellerId: self::SELLER, market: self::GRANTED_MARKET);
        $this->grantMarket(sellerId: self::SELLER, market: self::OTHER_MARKET, staffId: $staffId);
        $this->signInAs(self::SELLER);
        session(['seller_staff_id' => $staffId]);

        $this->assertSame(
            [self::GRANTED_MARKET],
            app(SellerMarketScope::class)->bindPanel(request: request()),
            'a staff member who may not sign in lends nothing: the shop answers',
        );
    }
}
