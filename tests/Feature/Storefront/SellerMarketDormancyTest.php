<?php

namespace Tests\Feature\Storefront;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\TestCase;

/**
 * A Saudi seller of one market, with markets switched off, sees and does exactly what they saw and did before (K-09 dormancy).
 *
 * The comparison is against the base rather than against a hand-written expectation: each surface is called once on a database
 * that has never run the grant migration — which is the base, table for table — and again with the table created and a grant
 * written while the switch is off. The two answers must be identical, status and body, byte for byte. Any answer that moved is a
 * dormancy break, whatever it moved to.
 *
 * The rendered half is here too, because this family of branches has been bitten by a surface no test renders: a seller request
 * that did not ask for JSON is served HTML, so the refusal of a market is compared as rendered markup and the off case is proved
 * to render nothing new at all.
 */
class SellerMarketDormancyTest extends TestCase
{
    use BuildsSellerMarketWorld;
    use DatabaseTransactions;
    use GrantsSellerMarkets;

    private const STAFF_TOKEN = 'dormancy-staff-token-that-is-longer-than-thirty-characters';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
        $this->giveProductsTheirStockColumns();
        $this->createTheSellerLedger();
        $this->createTheDigitalCatalogueTables();
    }

    protected function tearDown(): void
    {
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function surfacesASaudiSellerUses(): array
    {
        return [
            'who am I' => ['get', 'seller-center/security/me', []],
            'orders' => ['post', 'orders/list', ['limit' => 10, 'offset' => 1]],
            'products' => ['get', 'products/list', ['limit' => 10, 'offset' => 1]],
            'stock' => ['get', 'seller-center/inventory/overview', []],
            'finance' => ['get', 'seller-center/statement', []],
            'payouts' => ['get', 'seller-center/payouts', []],
            'staff management' => ['get', 'seller-center/security/staff', []],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('surfacesASaudiSellerUses')]
    public function test_a_grant_nobody_reads_moves_no_answer(string $method, string $uri, array $body): void
    {
        $this->switchStorefrontsOn();
        $this->insertSellerProduct(id: 801, sellerId: self::SELLER, market: 'SA', stock: 6, unitPrice: 12);
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'SAR', credit: 90, debit: 15);

        // Once before either measurement: this world's first request to a surface warms per-process caches the panel fills at
        // boot, so the first answer of a process can carry a relation the second does not — in either order, with or without the
        // grant table. Measuring two warm calls removes that asymmetry instead of hiding behind it.
        $this->dropOnlyTheMarketGrantTable();
        $this->sellerApi($method, $uri, $body);

        $onTheBase = $this->sellerApi($method, $uri, $body);
        $this->assertSame(200, $onTheBase->status(), "{$uri} must be answered on the base for a comparison to mean anything: " . substr((string) $onTheBase->getContent(), 0, 200));

        $this->createTheMarketGrantTable();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        config([SellerMarketScope::SWITCH => false]);
        $withTheGrantUnread = $this->sellerApi($method, $uri, $body);

        $this->assertSame($onTheBase->status(), $withTheGrantUnread->status(), "{$uri} answers the status of the base");
        $this->assertSame(
            (string) $onTheBase->getContent(),
            (string) $withTheGrantUnread->getContent(),
            "{$uri} answers the body of the base, byte for byte, while the switch is off",
        );
    }

    public function test_who_am_i_keeps_the_eight_keys_in_the_order_the_app_reads_them(): void
    {
        $this->switchStorefrontsOn();
        $this->createTheMarketGrantTable();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        config([SellerMarketScope::SWITCH => false]);

        $me = $this->sellerApi('get', 'seller-center/security/me')->assertStatus(200)->json();

        $this->assertSame([
            'principal_type', 'seller_id', 'staff_id', 'role', 'permissions', 'market_scope', 'allowed_markets', 'storefronts_enabled',
        ], array_keys($me), 'no ninth key appears while no grant is read');
        $this->assertSame('unrestricted', $me['market_scope']);
        $this->assertNull($me['allowed_markets']);
    }

    public function test_a_saudi_seller_of_one_market_with_markets_on_behaves_as_today(): void
    {
        $this->switchStorefrontsOn();
        $this->createTheMarketGrantTable();
        $onTheBase = $this->sellerApi('get', 'seller-center/security/me')->json('permissions');

        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $withOneMarket = $this->sellerApi('get', 'seller-center/security/me')->assertStatus(200)->json();

        $this->assertSame($onTheBase, $withOneMarket['permissions'], 'one market grants the owner exactly what they always held');
        $this->assertSame(['SA'], $withOneMarket['allowed_markets']);
        $this->assertSame($onTheBase, $withOneMarket['permissions_by_market']['SA'], 'and the same set inside that market');
        $this->assertStatus200($this->sellerApi('post', 'orders/list', ['limit' => 10, 'offset' => 1]));
    }

    public function test_a_single_market_staff_member_with_one_permission_set_is_byte_identical(): void
    {
        $this->switchStorefrontsOn();

        $roleId = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view', 'products.view']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $roleId, token: self::STAFF_TOKEN);

        $this->dropOnlyTheMarketGrantTable();
        $this->sellerApi('post', 'orders/list', ['limit' => 10, 'offset' => 1], self::STAFF_TOKEN);
        $onTheBase = $this->sellerApi('post', 'orders/list', ['limit' => 10, 'offset' => 1], self::STAFF_TOKEN);

        $this->createTheMarketGrantTable();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA', staffId: $staffId);
        config([SellerMarketScope::SWITCH => false]);
        $withTheGrantUnread = $this->sellerApi('post', 'orders/list', ['limit' => 10, 'offset' => 1], self::STAFF_TOKEN);

        $this->assertSame(200, $onTheBase->status(), 'the staff member is answered on the base');
        $this->assertSame(
            (string) $onTheBase->getContent(),
            (string) $withTheGrantUnread->getContent(),
            'a staff member of one market, with the switch off, is answered byte for byte what they were answered before',
        );
    }

    public function test_a_single_market_staff_member_keeps_their_one_permission_set_when_the_switch_is_on(): void
    {
        $this->switchStorefrontsOn();

        $roleId = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view', 'products.view']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $roleId, token: self::STAFF_TOKEN);

        $this->createTheMarketGrantTable();
        $onTheBase = $this->sellerApi('get', 'seller-center/security/me', token: self::STAFF_TOKEN)->json('permissions');

        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA', staffId: $staffId);

        $me = $this->sellerApi('get', 'seller-center/security/me', token: self::STAFF_TOKEN)->assertStatus(200)->json();

        $this->assertSame($onTheBase, $me['permissions'], 'a grant that names no role per market leaves the one permission set exactly as it was');
        $this->assertSame($onTheBase, $me['permissions_by_market']['SA'], 'and the same set is what they hold inside their one market');

        $orders = $this->sellerApi('post', 'orders/list', ['limit' => 50, 'offset' => 1], self::STAFF_TOKEN)->assertStatus(200);
        $markets = array_values(array_unique(array_column((array) $orders->json('orders'), 'storefront_country')));
        sort($markets);
        $this->assertSame([null, 'SA'], $markets, 'and their orders are the Saudi ones, with the orders no storefront recorded, as the platform reads them');
    }

    public function test_the_refusal_a_browser_gets_is_a_rendered_page_and_the_off_case_renders_nothing_new(): void
    {
        $this->switchMarketScopesOn();
        $this->createTheMarketGrantTable();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $refused = $this->askForHtml(market: self::UNGRANTED_MARKET);

        $this->assertSame(403, $refused->status(), 'a client that did not ask for JSON is refused too');
        $rendered = (string) $refused->getContent();
        $this->assertStringContainsString('<', $rendered, 'and is served rendered markup, not a JSON body');
        $this->assertStringNotContainsString('storefront_country', $rendered, 'with no data of the market it asked for');
        $this->assertStringNotContainsString('order_amount', $rendered, 'and no order of any market in it');

        config([SellerMarketScope::SWITCH => false]);

        $this->assertNotSame(403, $this->askForHtml(market: self::UNGRANTED_MARKET)->status(), 'while the switch is off no such page is rendered at all');
    }

    /** The same request a browser makes: the headers go in the server bag, which is the only one `call()` reads. */
    private function askForHtml(string $market): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/v3/seller/orders/list', ['limit' => 10, 'offset' => 1], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::SELLER_TOKEN,
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_STOREFRONT' => $market,
        ]);
    }

    public function test_the_grant_table_being_absent_is_the_same_as_the_switch_being_off(): void
    {
        $this->switchMarketScopesOn();
        $this->dropOnlyTheMarketGrantTable();

        $this->assertFalse(Schema::hasTable(SellerMarketScope::TABLE), 'the grant table is not there');

        $answered = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => self::UNGRANTED_MARKET,
        ])->json('post', '/api/v3/seller/orders/list', ['limit' => 10, 'offset' => 1]);

        $this->assertStatus200($answered);
    }

    private function assertStatus200(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertSame(200, $response->status(), 'the request is answered, not refused: ' . substr((string) $response->getContent(), 0, 200));
    }
}
