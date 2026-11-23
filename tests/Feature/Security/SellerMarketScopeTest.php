<?php

namespace Tests\Feature\Security;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Marketplace\SellerMarketTotals;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\TestCase;

/**
 * A seller or staff token carries the markets it may use, and nothing it does reaches outside them (K-09, K-04, K-V2).
 *
 * Every refusal here is produced by the real middleware chain of the real route, reached with a real bearer token: the request is
 * made with `$this->json()` against `/api/v3/seller/...`, so `api_lang`, `seller_api_auth`, `seller_market`, `seller_can` and
 * `seller_all_markets` all run in the order the routing table declares them. Nothing is assembled by hand — no middleware is
 * invoked directly, no principal is built, no request attribute is set by a test — because a test that assembles the conditions
 * proves its own assembly and not the server.
 *
 * The grant is written into `seller_market_scopes` by its own real migration, and only there, which is the property under test:
 * the market a request names is a *request*, and the only lever that widens it is a row.
 */
class SellerMarketScopeTest extends TestCase
{
    use BuildsSellerMarketWorld;
    use DatabaseTransactions;
    use GrantsSellerMarkets;

    private const STAFF_TOKEN = 'staff-market-scope-token-that-is-longer-than-thirty-characters';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
        $this->createTheMarketGrantTable();
        $this->giveProductsTheirStockColumns();
        $this->createTheSellerLedger();
    }

    protected function tearDown(): void
    {
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    /**
     * Every surface the criteria name, as the route that serves it. The grant holds SA; each of these names QA, which no test
     * grants, so each is a request for a market outside the grant.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function surfacesOfTheSellerApi(): array
    {
        return [
            'orders' => ['post', 'orders/list'],
            'products' => ['get', 'products/list'],
            'stock' => ['get', 'seller-center/inventory/overview'],
            'finance' => ['get', 'seller-center/statement'],
            'exports' => ['get', 'seller-center/statement/export'],
            'reports' => ['get', 'seller-center/reports/orders'],
            'report exports' => ['get', 'seller-center/reports/orders/export'],
            'bulk jobs' => ['get', 'seller-center/bulk-jobs'],
            'automation rules' => ['get', 'seller-center/automation/rules'],
            'audit logs' => ['get', 'seller-center/security/audit'],
            'payouts' => ['get', 'seller-center/payouts'],
            'bank details' => ['post', 'payment-information/add'],
            'staff management' => ['get', 'seller-center/security/staff'],
            'account deletion' => ['delete', 'account-delete'],
        ];
    }

    #[DataProvider('surfacesOfTheSellerApi')]
    public function test_a_market_outside_the_grant_is_refused_with_no_data_on_every_surface(string $method, string $uri): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => self::UNGRANTED_MARKET,
        ])->json($method, '/api/v3/seller/' . $uri);

        $response->assertStatus(403);
        $this->assertSame(
            [['code' => 'market_scope', 'message' => 'Your account does not work in that market']],
            $response->json('errors'),
            "{$uri} must refuse a market outside the grant with the market refusal and nothing else",
        );
        $this->assertSame(['errors'], array_keys((array) $response->json()), "{$uri} must carry no data beside the refusal");
    }

    #[DataProvider('surfacesOfTheSellerApi')]
    public function test_the_field_a_route_reads_is_no_more_a_grant_than_the_header_is(string $method, string $uri): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . self::SELLER_TOKEN, 'Accept' => 'application/json'])
            ->json($method, '/api/v3/seller/' . $uri . '?' . SellerMarketScope::REQUEST_FIELD . '=' . self::UNGRANTED_MARKET);

        $response->assertStatus(403);
        $this->assertSame('market_scope', $response->json('errors.0.code'), "{$uri} must refuse the field as it refuses the header");
    }

    public function test_the_only_lever_that_widens_a_grant_is_a_row(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $asking = fn (): \Illuminate\Testing\TestResponse => $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => 'AE',
        ])->json('post', '/api/v3/seller/orders/list', ['limit' => 10, 'offset' => 1]);

        $asking()->assertStatus(403);

        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $asking()->assertStatus(200);
    }

    public function test_a_granted_market_is_answered_with_its_own_orders_and_no_others(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
        ])->json('post', '/api/v3/seller/orders/list', ['limit' => 50, 'offset' => 1]);

        $response->assertStatus(200);

        $markets = array_values(array_unique(array_column((array) $response->json('orders'), 'storefront_country')));
        $this->assertSame(['AE'], $markets, 'a seller granted AE alone sees AE orders and nothing else');
    }

    public function test_a_staff_member_holds_different_permissions_in_two_markets(): void
    {
        $this->switchMarketScopesOn();

        $riyadhRole = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view', 'orders.manage']);
        $dubaiRole = $this->insertRole(sellerId: self::SELLER, name: 'Reader', permissions: ['products.view']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $riyadhRole, token: self::STAFF_TOKEN);

        $this->grantMarket(sellerId: self::SELLER, market: 'SA', staffId: $staffId, roleId: $riyadhRole);
        $this->grantMarket(sellerId: self::SELLER, market: 'AE', staffId: $staffId, roleId: $dubaiRole);

        $inSaudi = $this->listOrdersAsStaffIn('SA');
        $inDubai = $this->listOrdersAsStaffIn('AE');

        $inSaudi->assertStatus(200);
        $inDubai->assertStatus(403);
        $this->assertSame('permission', $inDubai->json('errors.0.code'), 'the same action is refused in the market whose role does not grant it');
    }

    public function test_a_staff_member_spanning_their_grant_may_only_do_what_every_market_allows(): void
    {
        $this->switchMarketScopesOn();

        $riyadhRole = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view', 'orders.manage']);
        $dubaiRole = $this->insertRole(sellerId: self::SELLER, name: 'Reader', permissions: ['products.view']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $riyadhRole, token: self::STAFF_TOKEN);

        $this->grantMarket(sellerId: self::SELLER, market: 'SA', staffId: $staffId, roleId: $riyadhRole);
        $this->grantMarket(sellerId: self::SELLER, market: 'AE', staffId: $staffId, roleId: $dubaiRole);

        $spanning = $this->withHeaders(['Authorization' => 'Bearer ' . self::STAFF_TOKEN, 'Accept' => 'application/json'])
            ->json('post', '/api/v3/seller/orders/list', ['limit' => 10, 'offset' => 1]);

        $spanning->assertStatus(403);
        $this->assertSame('permission', $spanning->json('errors.0.code'), 'a list of both markets may not be worked with one market\'s authority');
    }

    public function test_only_a_principal_who_holds_every_market_reaches_a_shop_wide_action(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $partial = $this->sellerApi('get', 'seller-center/security/staff');
        $partial->assertStatus(403);
        $this->assertSame('market_scope', $partial->json('errors.0.code'), 'a principal granted one market does not hold the shop');

        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->grantMarket(sellerId: self::SELLER, market: $market);
        }

        $this->assertNotSame(
            403,
            $this->sellerApi('get', 'seller-center/security/staff')->status(),
            'a principal granted every market the platform serves is past the market guard',
        );
    }

    public function test_an_all_markets_view_never_adds_two_currencies(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'SAR', credit: 100, debit: 0);
        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'AED', credit: 40, debit: 0);

        $summary = $this->sellerApi('get', 'seller-center/statement')->assertStatus(200)->json('summary');

        $this->assertNull($summary['range'], 'a range that spans two currencies is no one figure');
        $this->assertNull($summary['buckets'], 'balances of two currencies are not one balance');
        $this->assertNull($summary['withdrawable'], 'a withdrawable amount of two currencies is not an amount');
        $this->assertSame(SellerMarketTotals::CURRENCIES_DIFFER, $summary['totals_refused_because'], 'the refusal is visible, not silent');

        $byCurrency = collect($summary['range_by_currency'])->keyBy('currency_code');
        $this->assertSame([100.0, 40.0], [(float) $byCurrency['SAR']['credits'], (float) $byCurrency['AED']['credits']], 'each currency keeps its own real figure');
        $this->assertNotContains(140, array_map('floatval', array_column($summary['range_by_currency'], 'net')), 'the sum of the two is nowhere in the answer');
    }

    public function test_one_currency_is_answered_as_one_total_exactly_as_before(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');

        $this->insertLedgerEntry(sellerId: self::SELLER, currency: 'SAR', credit: 100, debit: 25);

        $summary = $this->sellerApi('get', 'seller-center/statement')->assertStatus(200)->json('summary');

        $this->assertSame(['currency', 'range', 'buckets', 'withdrawable'], array_keys($summary), 'a single-currency account keeps the keys it always had');
        $this->assertSame(75.0, (float) $summary['range']['net'], 'and the one figure it always printed');
    }

    public function test_the_stock_screen_counts_per_market_and_values_per_currency(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->insertSellerProduct(id: 901, sellerId: self::SELLER, market: 'SA', stock: 4, unitPrice: 10);
        $this->insertSellerProduct(id: 902, sellerId: self::SELLER, market: 'AE', stock: 3, unitPrice: 20);
        $this->insertSellerProduct(id: 903, sellerId: self::OTHER_SELLER, market: 'SA', stock: 99, unitPrice: 99);

        $overview = $this->sellerApi('get', 'seller-center/inventory/overview')->assertStatus(200)->json();

        $perMarket = collect($overview['by_market'])->keyBy('storefront_country');
        $this->assertSame(4, $perMarket['SA']['units_on_hand'], 'the Saudi shelf is counted on its own');
        $this->assertSame(3, $perMarket['AE']['units_on_hand'], 'and the Dubai shelf on its own');
        $this->assertSame(7, $overview['combined_counts']['units_on_hand'], 'units add across markets, because a unit is a unit');

        $this->assertNull($overview['stock_value_combined'], 'the value of two currencies is not one value');
        $this->assertSame(SellerMarketTotals::CURRENCIES_DIFFER, $overview['stock_value_refused_because'], 'and the reason is in the answer');

        $value = collect($overview['stock_value_by_currency'])->keyBy('currency_code');
        $this->assertSame([40.0, 60.0], [(float) $value['SAR']['stock_value'], (float) $value['AED']['stock_value']], 'each currency keeps its own value');
    }

    public function test_the_stock_screen_sees_only_the_granted_markets(): void
    {
        $this->switchMarketScopesOn();
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');

        $this->insertSellerProduct(id: 911, sellerId: self::SELLER, market: 'SA', stock: 5, unitPrice: 10);
        $this->insertSellerProduct(id: 912, sellerId: self::SELLER, market: 'AE', stock: 2, unitPrice: 10);

        $overview = $this->sellerApi('get', 'seller-center/inventory/overview')->assertStatus(200)->json();

        $this->assertSame(1, $overview['products'], 'a product of a market the seller was not granted is not theirs to count');
        $this->assertSame(2, $overview['units_on_hand'], 'and its units are not on their shelf');
    }

    public function test_me_carries_the_grant_and_what_the_token_may_do_in_each_market(): void
    {
        $this->switchMarketScopesOn();

        $riyadhRole = $this->insertRole(sellerId: self::SELLER, name: 'Order clerk', permissions: ['orders.view']);
        $dubaiRole = $this->insertRole(sellerId: self::SELLER, name: 'Reader', permissions: ['products.view']);
        $staffId = $this->insertStaff(sellerId: self::SELLER, roleId: $riyadhRole, token: self::STAFF_TOKEN);

        $this->grantMarket(sellerId: self::SELLER, market: 'SA', staffId: $staffId, roleId: $riyadhRole);
        $this->grantMarket(sellerId: self::SELLER, market: 'AE', staffId: $staffId, roleId: $dubaiRole);

        $me = $this->sellerApi('get', 'seller-center/security/me', token: self::STAFF_TOKEN)->assertStatus(200)->json();

        $this->assertSame('limited', $me['market_scope'], 'a token with a grant is not unrestricted');
        $this->assertSame(['AE', 'SA'], $me['allowed_markets'], 'the token carries the markets it may use');
        $this->assertSame(['products.view'], $me['permissions_by_market']['AE'], 'and what it may do in each of them');
        $this->assertSame(['orders.view'], $me['permissions_by_market']['SA']);
    }

    private function listOrdersAsStaffIn(string $market): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . self::STAFF_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => $market,
        ])->json('post', '/api/v3/seller/orders/list', ['limit' => 10, 'offset' => 1]);
    }
}
