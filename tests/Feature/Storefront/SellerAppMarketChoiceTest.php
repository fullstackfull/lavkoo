<?php

namespace Tests\Feature\Storefront;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsSellerMarketWorld;
use Tests\Support\GrantsSellerMarkets;
use Tests\TestCase;

/**
 * The market the seller app is working in is honoured by the seller APIs (A1-06, A1-11).
 *
 * The app keeps the seller's chosen market and sends it as `X-Storefront` on every call. The grant rows decide which markets
 * the token may reach at all (K-09); the market the app names is the choice INSIDE that bound, and until now only the order
 * list honoured a choice, through its own `storefront` body field, which the app does not send. So a seller granted Riyadh and
 * Dubai who opened the app in Dubai was answered both markets' catalogues, both markets' tills and both markets' orders.
 *
 * Every case is driven through the real route with a real bearer token, so `seller_api_auth` resolves the principal and
 * `seller_market` resolves the grant from the rows and binds the working market. Reading the attribute by hand and calling the
 * controller could not tell a bound route from an unbound one.
 *
 * Each refusal has its unrestricted twin: the same request on the same world with no grant row answers BOTH markets, so an
 * answer of one market can only be the choice taking effect and not an empty world or a wrong address. The header widens
 * nothing — `SellerMarketScopeTest` owns that half — and with the switch off nothing here is read at all.
 */
class SellerAppMarketChoiceTest extends TestCase
{
    use BuildsSellerMarketWorld;
    use DatabaseTransactions;
    use GrantsSellerMarkets;

    private const RIYADH_PRODUCT = 811;

    private const DUBAI_PRODUCT = 812;

    /** The orders this world already holds for this seller: one of each market, one of no market, and a second Riyadh one. */
    private const RIYADH_ORDER = 101;

    private const DUBAI_ORDER = 102;

    private const KUWAIT_ORDER = 103;

    private const ORDER_OF_NO_MARKET = 104;

    private const SECOND_RIYADH_ORDER = 105;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerMarketWorld();
        $this->createTheMarketGrantTable();
        $this->giveProductsTheirStockColumns();
        $this->createTheDigitalCatalogueTables();
        $this->switchStorefrontsOn();

        $this->insertSellerProduct(id: self::RIYADH_PRODUCT, sellerId: self::SELLER, market: 'SA', stock: 4, unitPrice: 12);
        $this->insertSellerProduct(id: self::DUBAI_PRODUCT, sellerId: self::SELLER, market: 'AE', stock: 7, unitPrice: 30);
    }

    protected function tearDown(): void
    {
        $this->dropTheMarketGrantTable();
        $this->tearDownSellerMarketWorld();

        parent::tearDown();
    }

    public function test_the_product_list_answers_the_market_the_app_is_working_in(): void
    {
        $this->sellerWorksInRiyadhAndDubai();

        $this->assertSame(
            [self::DUBAI_PRODUCT],
            $this->productIdsOfTheList(market: 'AE'),
            'a seller working in Dubai is answered their Dubai catalogue',
        );
        $this->assertSame(
            [self::RIYADH_PRODUCT],
            $this->productIdsOfTheList(market: 'SA'),
            'and their Riyadh catalogue when they move the app to Riyadh',
        );
    }

    public function test_the_product_list_of_a_seller_who_names_no_market_spans_their_whole_grant(): void
    {
        $this->sellerWorksInRiyadhAndDubai();

        $this->assertSame(
            [self::RIYADH_PRODUCT, self::DUBAI_PRODUCT],
            $this->productIdsOfTheList(market: null),
            'naming no market is not naming one, so the list is of every market the token holds',
        );
    }

    public function test_a_seller_with_no_grant_reads_their_whole_catalogue_whatever_the_app_names(): void
    {
        config([SellerMarketScope::SWITCH => true]);

        $this->assertSame(
            [self::RIYADH_PRODUCT, self::DUBAI_PRODUCT],
            $this->productIdsOfTheList(market: 'AE'),
            'the unrestricted twin: with no grant row the header narrows nothing, which is what proves the narrowing is the grant',
        );
    }

    public function test_the_till_offers_only_the_market_it_is_open_in(): void
    {
        $this->sellerWorksInRiyadhAndDubai();

        $this->assertSame([self::DUBAI_PRODUCT], $this->productIdsOfTheTill(market: 'AE'), 'a till open in Dubai offers the Dubai shelf');
        $this->assertSame(
            [self::RIYADH_PRODUCT, self::DUBAI_PRODUCT],
            $this->productIdsOfTheTill(market: null),
            'and a till that names no market offers the grant, as it did before',
        );
    }

    public function test_the_order_list_answers_the_market_the_app_is_working_in(): void
    {
        $this->sellerWorksInRiyadhAndDubai();

        $this->assertSame([self::DUBAI_ORDER], $this->orderIdsOfTheList(market: 'AE'), 'the orders of the market the app is working in');
        $this->assertSame(
            [self::RIYADH_ORDER, self::DUBAI_ORDER, self::ORDER_OF_NO_MARKET, self::SECOND_RIYADH_ORDER],
            $this->orderIdsOfTheList(market: null),
            'and the grant when the app names no market: both its markets, and the order with no record, which the platform reads as the default market',
        );
        $this->assertNotContains(self::KUWAIT_ORDER, $this->orderIdsOfTheList(market: null), 'never a market the grant does not hold');
    }

    public function test_the_market_named_in_the_body_still_decides_the_order_list(): void
    {
        $this->sellerWorksInRiyadhAndDubai();

        $answered = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::SELLER_TOKEN,
            'Accept' => 'application/json',
            StorefrontResolver::HEADER => 'AE',
        ])->json('post', '/api/v3/seller/orders/list', ['limit' => 10, 'offset' => 1, SellerMarketScope::REQUEST_FIELD => 'SA']);

        $answered->assertStatus(200);
        $this->assertSame(
            [self::RIYADH_ORDER, self::ORDER_OF_NO_MARKET, self::SECOND_RIYADH_ORDER],
            $this->idsOf($answered->json('orders')),
            'the field this list has always read keeps its precedence over the market the app happens to be in (and the default '
                . "market keeps the orders that recorded none, as it does everywhere)",
        );
    }

    public function test_with_the_switch_off_the_market_the_app_names_changes_nothing(): void
    {
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');
        config([SellerMarketScope::SWITCH => false]);

        $this->assertSame(
            [self::RIYADH_PRODUCT, self::DUBAI_PRODUCT],
            $this->productIdsOfTheList(market: 'AE'),
            'the capability ships off: an unread grant and a named market move no answer',
        );
        $this->assertSame(
            [self::RIYADH_ORDER, self::DUBAI_ORDER, self::KUWAIT_ORDER, self::ORDER_OF_NO_MARKET, self::SECOND_RIYADH_ORDER],
            $this->orderIdsOfTheList(market: 'AE'),
            'and no order answer either: every order of this seller, Kuwait included',
        );
    }

    /**
     * A market outside the grant is refused rather than answered empty, so the two states cannot be confused: an empty list
     * would tell a seller their Qatari catalogue is empty when the truth is that their account does not work there.
     */
    public function test_a_market_the_token_does_not_hold_is_refused_and_not_answered_empty(): void
    {
        $this->sellerWorksInRiyadhAndDubai();

        $refused = $this->listWithTheAppIn(method: 'get', uri: 'products/list', market: self::UNGRANTED_MARKET);

        $refused->assertStatus(403);
        $this->assertSame('market_scope', $refused->json('errors.0.code'));
    }

    private function sellerWorksInRiyadhAndDubai(): void
    {
        config([SellerMarketScope::SWITCH => true]);
        $this->grantMarket(sellerId: self::SELLER, market: 'SA');
        $this->grantMarket(sellerId: self::SELLER, market: 'AE');
    }

    /** @return list<int> */
    private function productIdsOfTheList(?string $market): array
    {
        $answered = $this->listWithTheAppIn(method: 'get', uri: 'products/list', market: $market, data: ['limit' => 10, 'offset' => 1]);
        $answered->assertStatus(200);

        return $this->idsOf($answered->json());
    }

    /** @return list<int> */
    private function productIdsOfTheTill(?string $market): array
    {
        $answered = $this->listWithTheAppIn(method: 'get', uri: 'pos/product-list', market: $market, data: ['limit' => 10, 'offset' => 1]);
        $answered->assertStatus(200);

        return $this->idsOf($answered->json('products'));
    }

    /** @return list<int> */
    private function orderIdsOfTheList(?string $market): array
    {
        $answered = $this->listWithTheAppIn(method: 'post', uri: 'orders/list', market: $market, data: ['limit' => 10, 'offset' => 1]);
        $answered->assertStatus(200);

        return $this->idsOf($answered->json('orders'));
    }

    /** @param array<string, mixed> $data */
    private function listWithTheAppIn(string $method, string $uri, ?string $market, array $data = []): TestResponse
    {
        $headers = ['Authorization' => 'Bearer ' . self::SELLER_TOKEN, 'Accept' => 'application/json']
            + ($market === null ? [] : [StorefrontResolver::HEADER => $market]);

        // `withHeaders()` MERGES into the headers of the test, so the market of one call would otherwise still be sent by the
        // next one -- and a case about naming no market would silently be a second case about naming the previous one.
        $this->flushHeaders();

        return $this->withHeaders($headers)->json($method, '/api/v3/seller/' . $uri, $data);
    }

    /**
     * The ids of an answer, sorted, so a case is about which rows came back and not about which order a list happens to use.
     *
     * @return list<int>
     */
    private function idsOf(mixed $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], array_values((array) $rows));
        sort($ids);

        return $ids;
    }
}
