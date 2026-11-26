<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\OrderMarketReading;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsWhatOrderAnswersRead;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * One customer, orders in two markets, one history (A2-10).
 *
 * The order answers already carry the storefront snapshot's own columns, which CustomerOrderMarketAnswerTest pins key by key. Two things
 * they could not do: NAME the market and the company of an order that carries no stamp -- a null column names nothing, and every order
 * placed before markets existed is one -- and let the customer ask for one market's orders alone.
 *
 * `App\Services\Storefront\OrderMarketReading` is the reading of the record beside the record: the raw columns stay exactly what the row
 * holds (asserted here, because a reading that rewrote them would be a lie about what was sold) and three further keys say what the row
 * means by the written rule -- an unstamped order is the DEFAULT storefront's and the platform's own company, and its currency says
 * nothing at all on a platform that converts from a base currency, because there the currency an order is shown in is the visitor's own
 * choice and naming one would be an invention.
 *
 * The filter narrows only to a market the customer picked: with none picked the history is every market, which is what it has always been
 * and what the owner has still to decide. With storefronts switched off it narrows nothing, which the first test here is.
 */
class StorefrontOrderHistoryTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsWhatOrderAnswersRead;
    use KeepsOrderDebugLogClean;

    private const PLACE_ORDER = '/api/v1/customer/order/place';
    private const ORDER_LIST = '/api/v1/customer/order/list?limit=10&offset=1';
    private const ORDER_BY_ID = '/api/v1/customer/order/get-order-by-id?order_id=';
    private const ORDER_DETAILS = '/api/v1/customer/order/details?order_id=';

    private const PRODUCT_OF = ['SA' => 1, 'KW' => 3, 'QA' => 4];
    private const PLATFORM_COMPANY = 'Lavkoo Platform';

    /** The columns the order row holds of its own market, which the reading must never rewrite. */
    private const RECORD_KEYS = ['storefront_country', 'currency_code', 'currency_rate'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->addWhatTheOrderAnswersRead();
        $this->addTheCompanyColumns();
        $this->setSetting('company_name', self::PLATFORM_COMPANY);
        $this->rememberOrderDebugLog();
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    /** The company columns an order of a legal entity carries; the rest of what the answers read is the shared builder's. */
    private function addTheCompanyColumns(): void
    {
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
    }

    /** @return array{0: User, 1: array<string, int>} the customer and the id of the order each market placed for them */
    private function placeOrdersIn(string ...$markets): array
    {
        $customer = $this->makeCustomer();
        $orders = [];

        foreach ($markets as $market) {
            $this->fillTheCartFrom(customer: $customer, country: $market, productId: self::PRODUCT_OF[$market]);
            $orders[$market] = (int) $this->asTheApp(customer: $customer, country: $market)
                ->getJson(self::PLACE_ORDER)->assertOk()->json('order_ids.0');
        }

        return [$customer, $orders];
    }

    private function historyOf(User $customer, string $servedFrom = 'SA', ?string $picked = null): TestResponse
    {
        $url = self::ORDER_LIST . ($picked === null ? '' : '&storefront=' . $picked);

        return $this->asTheApp(customer: $customer, country: $servedFrom)->getJson($url)->assertOk();
    }

    /** @return list<int> the ids the history answered with, newest first as the list orders them */
    private function orderIdsIn(TestResponse $history): array
    {
        return array_map(static fn (array $order): int => (int) $order['id'], $history->json('orders'));
    }

    /** @return array<string, mixed> the reading of the order the history lists at this position */
    private function readingOf(TestResponse $history, int $position): array
    {
        $order = $history->json("orders.{$position}");

        return [
            OrderMarketReading::MARKET => $order[OrderMarketReading::MARKET] ?? null,
            OrderMarketReading::CURRENCY => $order[OrderMarketReading::CURRENCY] ?? null,
            OrderMarketReading::COMPANY => $order[OrderMarketReading::COMPANY] ?? null,
        ];
    }

    /** @return array<string, mixed> the three columns of the order row itself, as the answer carries them */
    private function recordIn(TestResponse $history, int $position): array
    {
        $order = $history->json("orders.{$position}");

        return array_combine(self::RECORD_KEYS, array_map(static fn (string $key) => $order[$key] ?? null, self::RECORD_KEYS));
    }

    /** An order as every order placed before markets existed: the columns are there and empty. */
    private function forgetWhatTheOrderWasSoldIn(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null]);
    }

    public function test_with_storefronts_off_a_picked_market_narrows_nothing(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');
        config(['gcc.storefronts.enabled' => false]);

        $listed = $this->orderIdsIn($this->historyOf($customer, picked: 'KW'));

        $this->assertEqualsCanonicalizing(array_values($orders), $listed, 'with storefronts off the history is every order, as it has always been');
    }

    public function test_the_history_of_one_customer_holds_the_orders_of_every_market(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW', 'QA');

        $this->assertEqualsCanonicalizing(array_values($orders), $this->orderIdsIn($this->historyOf($customer)));
    }

    public function test_each_listed_order_names_its_market_its_currency_and_the_company_that_sold_it(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');

        $history = $this->historyOf($customer, servedFrom: 'KW');

        $this->assertSame($orders['KW'], $this->orderIdsIn($history)[0]);
        $this->assertSame([
            OrderMarketReading::MARKET => 'KW',
            OrderMarketReading::CURRENCY => 'KWD',
            OrderMarketReading::COMPANY => self::PLATFORM_COMPANY,
        ], $this->readingOf($history, position: 0));
    }

    public function test_an_order_that_carries_no_stamp_still_names_the_default_market_and_the_platforms_company(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');
        $this->forgetWhatTheOrderWasSoldIn($orders['KW']);

        $reading = $this->readingOf($this->historyOf($customer), position: 0);

        $this->assertSame('SA', $reading[OrderMarketReading::MARKET]);
        $this->assertSame(self::PLATFORM_COMPANY, $reading[OrderMarketReading::COMPANY]);
    }

    public function test_the_reading_never_rewrites_the_record_it_reads(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');
        $this->forgetWhatTheOrderWasSoldIn($orders['KW']);

        $record = $this->recordIn($this->historyOf($customer), position: 0);

        $this->assertSame(array_fill_keys(self::RECORD_KEYS, null), $record, 'the columns of an unstamped order stay empty in the answer');
    }

    public function test_the_history_can_be_asked_for_one_market_alone(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');

        $this->assertSame([$orders['KW']], $this->orderIdsIn($this->historyOf($customer, picked: 'KW')));
        $this->assertSame([$orders['SA']], $this->orderIdsIn($this->historyOf($customer, picked: 'SA')));
    }

    public function test_the_default_market_is_asked_for_by_its_own_name_and_answers_the_unstamped_orders_too(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');
        $this->forgetWhatTheOrderWasSoldIn($orders['SA']);

        $this->assertSame([$orders['SA']], $this->orderIdsIn($this->historyOf($customer, picked: 'SA')));
    }

    public function test_a_market_the_customer_has_never_bought_in_answers_no_orders_rather_than_all_of_them(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');

        $this->assertSame([], $this->orderIdsIn($this->historyOf($customer, picked: 'OM')));
        $this->assertSame([], $this->orderIdsIn($this->historyOf($customer, picked: 'not-a-market')));
    }

    public function test_the_word_all_asks_for_every_market_and_is_not_read_as_a_market(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');

        $this->assertEqualsCanonicalizing(array_values($orders), $this->orderIdsIn($this->historyOf($customer, picked: 'all')));
    }

    public function test_the_filter_never_reaches_another_customers_order(): void
    {
        [$shopper, $orders] = $this->placeOrdersIn('KW');
        [$stranger] = $this->placeOrdersIn('SA');

        $this->assertSame([], $this->orderIdsIn($this->historyOf($stranger, picked: 'KW')));
        $this->assertSame([$orders['KW']], $this->orderIdsIn($this->historyOf($shopper, picked: 'KW')));
    }

    public function test_the_single_order_answer_names_what_it_was_sold_in(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');

        $answer = $this->asTheApp(customer: $customer, country: 'KW')->getJson(self::ORDER_BY_ID . $orders['KW'])->assertOk();

        $this->assertSame('KW', $answer->json(OrderMarketReading::MARKET));
        $this->assertSame('KWD', $answer->json(OrderMarketReading::CURRENCY));
        $this->assertSame(self::PLATFORM_COMPANY, $answer->json(OrderMarketReading::COMPANY));
    }

    public function test_the_order_details_answer_names_what_the_order_was_sold_in(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');

        $answer = $this->asTheApp(customer: $customer, country: 'KW')->getJson(self::ORDER_DETAILS . $orders['KW'])->assertOk();

        $this->assertSame('KW', $answer->json('0.order.' . OrderMarketReading::MARKET));
        $this->assertSame('KWD', $answer->json('0.order.' . OrderMarketReading::CURRENCY));
    }

    public function test_the_markets_offered_to_a_customer_are_the_ones_they_have_orders_in(): void
    {
        [$customer] = $this->placeOrdersIn('SA', 'KW');
        $reading = app(OrderMarketReading::class);

        $this->assertSame(['KW', 'SA'], $reading->marketsOfCustomer(customerId: $customer->id));
    }

    public function test_a_customer_who_has_bought_in_one_market_is_offered_no_filter(): void
    {
        [$customer] = $this->placeOrdersIn('KW');

        $this->assertSame([], app(OrderMarketReading::class)->marketsOfCustomer(customerId: $customer->id));
    }

    public function test_no_filter_is_offered_while_storefronts_are_off(): void
    {
        [$customer] = $this->placeOrdersIn('SA', 'KW');
        config(['gcc.storefronts.enabled' => false]);

        $this->assertSame([], app(OrderMarketReading::class)->marketsOfCustomer(customerId: $customer->id));
    }

    public function test_the_company_a_listed_order_names_is_the_one_frozen_on_it(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');
        DB::table('orders')->where('id', $orders['KW'])->update([
            'entity_snapshot' => json_encode(['trade_name' => 'Lavkoo Kuwait', 'legal_name' => 'Lavkoo Kuwait W.L.L.']),
        ]);

        $this->assertSame('Lavkoo Kuwait', $this->readingOf($this->historyOf($customer), position: 0)[OrderMarketReading::COMPANY]);
    }

    public function test_a_frozen_company_with_no_trade_name_is_named_by_its_legal_name(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('KW');
        DB::table('orders')->where('id', $orders['KW'])->update([
            'entity_snapshot' => json_encode(['trade_name' => '', 'legal_name' => 'Lavkoo Kuwait W.L.L.']),
        ]);

        $this->assertSame('Lavkoo Kuwait W.L.L.', $this->readingOf($this->historyOf($customer), position: 0)[OrderMarketReading::COMPANY]);
    }

    /**
     * The website's own history page, rendered as a browser gets it: the real template, the real controller, the stand-in storefront shell
     * (the real one needs the installation database) and the payment settings the page asks about, all switched off.
     */
    private function websiteHistoryOf(User $customer, ?string $picked = null): TestResponse
    {
        $this->renderTheWebsiteWithTheStandInShell();
        $customer->forceFill(['is_active' => 1])->save();

        return $this->actingAs($customer, 'customer')
            ->get(route('account-oder', $picked === null ? [] : ['storefront' => $picked]))
            ->assertOk();
    }

    private function renderTheWebsiteWithTheStandInShell(): void
    {
        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }

        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));

        if (!Schema::hasTable('addon_settings')) {
            Schema::create('addon_settings', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('key_name')->nullable();
                $table->text('live_values')->nullable();
                $table->text('test_values')->nullable();
                $table->string('settings_type')->nullable();
                $table->string('mode')->default('test');
                $table->integer('is_active')->default(0);
                $table->text('additional_data')->nullable();
                $table->timestamps();
            });
        }

        foreach (['digital_payment', 'cash_on_delivery', 'offline_payment', 'wallet_status'] as $setting) {
            $this->setSetting($setting, json_encode(['status' => 0]));
        }
    }

    private function orderNumberOf(int $orderId): string
    {
        return '#' . $orderId;
    }

    public function test_the_website_page_offers_no_market_filter_and_shows_no_market_while_storefronts_are_off(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');
        config(['gcc.storefronts.enabled' => false]);

        $page = $this->websiteHistoryOf($customer);

        $page->assertDontSee('id="orderMarketFilter"', escape: false);
        $page->assertDontSee('title-semidark fs-12 text-uppercase', escape: false);
        $page->assertSee($this->orderNumberOf($orders['SA']), escape: false);
        $page->assertSee($this->orderNumberOf($orders['KW']), escape: false);
    }

    public function test_the_website_page_offers_each_market_the_customer_has_bought_in(): void
    {
        [$customer] = $this->placeOrdersIn('SA', 'KW');

        $page = $this->websiteHistoryOf($customer);

        $page->assertSee('id="orderMarketFilter"', escape: false);
        $page->assertSee('storefront=KW', escape: false);
        $page->assertSee('storefront=SA', escape: false);
    }

    public function test_the_website_page_shows_which_market_each_order_was_sold_in(): void
    {
        [$customer] = $this->placeOrdersIn('SA', 'KW');

        $content = (string) $this->websiteHistoryOf($customer)->getContent();

        foreach (['KW', 'SA'] as $market) {
            $this->assertMatchesRegularExpression(
                '/title-semidark fs-12 text-uppercase[\s\S]{0,250}?>\s*' . $market . '\s*</',
                $content,
                "the history prints the market {$market} on the order sold there",
            );
        }
    }

    public function test_the_market_the_website_page_offers_is_the_market_it_then_narrows_to(): void
    {
        [$customer, $orders] = $this->placeOrdersIn('SA', 'KW');

        $page = $this->websiteHistoryOf($customer, picked: 'KW');

        $page->assertSee($this->orderNumberOf($orders['KW']), escape: false);
        $page->assertDontSee($this->orderNumberOf($orders['SA']), escape: false);
    }
}
