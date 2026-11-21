<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * What currency a purchase is in (GCC-05). A visitor's currency pick is kept in the session and outlives the storefront it was made on, so a
 * shopper who picked riyals on the Saudi market and then shops the Kuwaiti one carries "SAR" with them. The two v1 order endpoints read that
 * pick behind the currency-model guard, which a storefront trading in its own currency turns into the single-currency model, so they already
 * answered the market's own money; the website's own cash-on-delivery checkout read the pick raw and wrote it onto the order, so a Kuwaiti
 * order of 30 dinars was stored as 30 with "SAR" beside the change the courier has to bring. All three now ask one question.
 *
 * Every figure here comes from a real route: POST /checkout-complete, GET /api/v1/customer/order/place and POST
 * /api/v1/edit-order/due-payment-by-cod, each with its own guards running. Nothing is extracted from a file and evaluated on its own.
 */
class StorefrontPurchaseCurrencyTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const WEB_COD = '/checkout-complete';

    private const API_COD = '/api/v1/customer/order/place';

    private const API_DUE_BY_COD = '/api/v1/edit-order/due-payment-by-cod';

    /** The change a shopper asks the courier to bring, which is the amount the currency label on the order belongs to. */
    private const CHANGE = 50;

    /** The pick a shopper carries from the Saudi market: the code, its sign and its rate, exactly as the currency switcher writes them. */
    private const STALE_PICK = ['currency_code' => 'SAR', 'currency_symbol' => 'SAR', 'currency_exchange_rate' => 3.75];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        (require database_path('migrations/2026_01_11_211839_create_order_edit_histories_table.php'))->up();
        $this->setSetting('currency_model', 'multi_currency');
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> the five storefronts that trade in their own currency, the product each sells and that currency */
    public static function storefrontsWithTheirOwnCurrency(): array
    {
        return [
            'United Arab Emirates' => ['AE', 2, 'AED'],
            'Kuwait' => ['KW', 3, 'KWD'],
            'Qatar' => ['QA', 4, 'QAR'],
            'Bahrain' => ['BH', 5, 'BHD'],
            'Oman' => ['OM', 6, 'OMR'],
        ];
    }

    // ---- the website's own checkout ---------------------------------------------------------------------------------------------

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_the_web_checkout_labels_the_change_of_an_order_in_the_money_of_the_market_it_was_sold_from(string $country, int $productId, string $currencyCode): void
    {
        $this->placeOnTheWebsiteFrom(country: $country, productId: $productId)->assertOk()->assertJsonPath('status', 1);

        $this->assertSame($currencyCode, $this->labelOnTheOrder(), 'and not the pick the shopper carried from another market');
    }

    public function test_the_web_checkout_of_the_default_market_keeps_the_shoppers_own_pick(): void
    {
        $this->placeOnTheWebsiteFrom(country: 'SA', productId: 1)->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('SAR', $this->labelOnTheOrder(), 'the Saudi market reads the platform settings, where the pick is the shopper\'s own');
    }

    public function test_the_web_checkout_keeps_the_pick_while_the_markets_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->placeOnTheWebsiteFrom(country: 'KW', productId: 3)->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('SAR', $this->labelOnTheOrder(), 'nothing is decided by a storefront while no storefront trades in its own currency');
    }

    public function test_the_web_checkout_keeps_the_pick_while_only_the_default_market_is_configured(): void
    {
        foreach (['AE', 'KW', 'QA', 'BH', 'OM'] as $country) {
            config(["gcc.storefronts.countries.{$country}.active" => false]);
        }

        $this->placeOnTheWebsiteFrom(country: 'SA', productId: 1)->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('SAR', $this->labelOnTheOrder());
    }

    // ---- the two v1 endpoints, which must answer the same ------------------------------------------------------------------------

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_the_v1_order_endpoint_answers_the_same_currency_as_the_website(string $country, int $productId, string $currencyCode): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)
            ->getJson(self::API_COD . '?bring_change_amount=' . self::CHANGE . '&current_currency_code=SAR')
            ->assertOk();

        $this->assertSame($currencyCode, $this->labelOnTheOrder(), 'the field a client sent cannot name another market\'s money either');
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_the_v1_order_edit_endpoint_answers_the_same_currency_as_the_website(string $country, int $productId, string $currencyCode): void
    {
        $customer = $this->makeCustomer();
        $this->placeOnTheWebsiteFrom(country: $country, productId: $productId, customer: $customer)->assertOk();
        $orderId = DB::table('orders')->value('id');

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson(self::API_DUE_BY_COD, [
            'order_id' => $orderId,
            'payment_method' => 'cash_on_delivery',
            'bring_change_amount' => self::CHANGE,
            'current_currency_code' => 'SAR',
        ])->assertOk();

        $this->assertSame($currencyCode, $this->labelOnTheOrder());
    }

    public function test_the_v1_endpoints_keep_the_field_a_client_sent_while_the_markets_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'KW', productId: 3);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'KW')
            ->getJson(self::API_COD . '?bring_change_amount=' . self::CHANGE . '&current_currency_code=SAR')
            ->assertOk();

        $this->assertSame('SAR', $this->labelOnTheOrder());
    }

    public function test_the_v1_order_endpoint_keeps_the_platform_default_under_the_single_currency_model(): void
    {
        $this->setSetting('currency_model', 'single_currency');
        config(['gcc.storefronts.currency.enabled' => false]);
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'KW', productId: 3);

        $this->asCustomer($customer)->withHeader('X-Storefront', 'KW')
            ->getJson(self::API_COD . '?bring_change_amount=' . self::CHANGE . '&current_currency_code=AED')
            ->assertOk();

        $this->assertSame('SAR', $this->labelOnTheOrder(), 'a client may not pick a currency where the platform runs one');
    }

    // ---- nothing stored moves ---------------------------------------------------------------------------------------------------

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_the_amounts_of_the_order_are_the_amounts_of_the_catalogue_and_the_change_is_the_number_that_was_asked_for(string $country, int $productId): void
    {
        $this->placeOnTheWebsiteFrom(country: $country, productId: $productId)->assertOk();

        $this->assertSame($this->priceOf($productId), $this->money('order_details', 'price'), 'the line is the price of its own catalogue');
        $this->assertSame((float) self::CHANGE, $this->money('orders', 'bring_change_amount'), 'the change is the number the shopper typed');
    }

    /**
     * The whole of what the Saudi market stores for a cash order, with a rate on the platform's currency that would show any re-scaling, pinned
     * to the numbers this fixture produced before the currency of a purchase had one answer. This branch has been bitten by a figure no test read.
     */
    public function test_a_saudi_cash_order_stores_the_same_money_it_has_always_stored(): void
    {
        DB::table('currencies')->where('code', 'SAR')->update(['exchange_rate' => 3.75]);
        cache()->forget('active_currency_list');

        $this->placeOnTheWebsiteFrom(country: 'SA', productId: 1)->assertOk();

        $this->assertSame([
            'order_amount' => 100.0,
            'bring_change_amount' => 50.0,
            'bring_change_amount_currency' => 'SAR',
            'shipping_cost' => 0.0,
            'discount_amount' => 0.0,
            'line_price' => 100.0,
        ], $this->storedMoneyOfTheOrder());
    }

    // ---- the payment --------------------------------------------------------------------------------------------------------------

    public function test_a_payment_of_a_market_with_its_own_money_is_still_refused_rather_than_converted(): void
    {
        $country = 'KW';
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: 3);
        $this->actingAs($customer, 'customer');

        $response = $this->withSession(self::STALE_PICK)
            ->post('/customer/web-payment-request?storefront=' . $country, [
                'payment_method' => 'paypal',
                'payment_platform' => 'web',
                'current_currency_code' => 'SAR',
                'external_redirect_link' => 'https://example.test/done',
            ]);

        $this->assertContains($response->status(), [302, 403, 422, 500], 'the gateway is not handed this order: ' . $response->status());
        $this->assertSame(0, DB::table('orders')->count(), 'and no order is written by a refused payment');
    }

    public function test_the_gateway_policy_still_refuses_a_gateway_that_was_not_asked_for_the_markets_money(): void
    {
        $policy = app(\App\Services\Storefront\GatewayCurrencyPolicy::class);

        $this->expectException(\App\Exceptions\StorefrontPaymentNotSupportedException::class);

        $this->inStorefront('KW', fn () => $policy->assertAcceptable(gateway: 'paypal', currencyCode: 'SAR'));
    }

    // ---- the claim page of the auction add-on ---------------------------------------------------------------------------------------

    /**
     * The hidden field the auction claim page posts into the payment path. The page itself cannot be rendered here: the three routes it names
     * and the handler that reads the field belong to Modules/Auction, which is not part of this repository, so what is checked is the whole
     * file (one such field, no currency-model guard anywhere in it, and the one answer in its value) and then that answer's own behaviour.
     */
    public function test_the_claim_page_posts_the_money_of_the_market_it_is_served_from(): void
    {
        $page = file_get_contents(base_path('resources/themes/default/auction/web-views/product/details/_claim-checkout.blade.php'));

        $this->assertSame(1, substr_count($page, 'name="current_currency_code"'), 'one field names the currency of the claim');
        $this->assertStringNotContainsString('currency_model', $page, 'the page has no currency-model guard of its own, so its expression is the whole of its decision');
        $this->assertStringContainsString(
            'value="{{ app(StorefrontCurrency::class)->purchaseCurrencyCode(picked: session(\'currency_code\') ?? getCurrencyCode()) }}"',
            $page,
            'and it asks the same question the checkout and the two endpoints ask',
        );

        session()->put(self::STALE_PICK);
        $answer = $this->inStorefront('KW', fn (): ?string => app(StorefrontCurrency::class)->purchaseCurrencyCode(picked: session('currency_code') ?? getCurrencyCode()));
        $this->assertSame('KWD', $answer);

        session()->put(self::STALE_PICK);
        $this->assertSame('SAR', app(StorefrontCurrency::class)->purchaseCurrencyCode(picked: session('currency_code') ?? getCurrencyCode()), 'and leaves the pick alone off the markets');
    }

    /**
     * The field itself, rendered, with the markets off. The whole page cannot be rendered here, but the field can be: the file carries no
     * currency-model guard at all (pinned above), so this one expression is the whole of what the page decides about money, and its
     * {@see \App\Services\Storefront\StorefrontCurrency::purchaseCurrencyCode()} answers nothing while no storefront trades in its own
     * currency. These are the bytes the old expression rendered; the same test file on the previous head renders them too.
     */
    public function test_the_claim_pages_currency_field_renders_the_bytes_it_rendered_before_while_the_markets_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $rendered = [];

        foreach (['multi_currency', 'single_currency'] as $model) {
            $this->setSetting('currency_model', $model);

            session()->forget(['currency_code', 'currency_symbol', 'currency_exchange_rate', 'system_default_currency_info']);
            $rendered[$model . ', a session with no pick'] = $this->renderedCurrencyField();

            session()->put(self::STALE_PICK);
            $rendered[$model . ', a session carrying a pick'] = $this->renderedCurrencyField();

            session()->put(['currency_code' => 'AED', 'currency_symbol' => 'AED', 'currency_exchange_rate' => 1]);
            $rendered[$model . ', a session carrying another pick'] = $this->renderedCurrencyField();
        }

        $this->assertSame([
            'multi_currency, a session with no pick' => '<input type="hidden" name="current_currency_code" value="SAR">',
            'multi_currency, a session carrying a pick' => '<input type="hidden" name="current_currency_code" value="SAR">',
            'multi_currency, a session carrying another pick' => '<input type="hidden" name="current_currency_code" value="AED">',
            'single_currency, a session with no pick' => '<input type="hidden" name="current_currency_code" value="SAR">',
            'single_currency, a session carrying a pick' => '<input type="hidden" name="current_currency_code" value="SAR">',
            'single_currency, a session carrying another pick' => '<input type="hidden" name="current_currency_code" value="AED">',
        ], $rendered);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------------

    private function placeOnTheWebsiteFrom(string $country, int $productId, ?\App\Models\User $customer = null): TestResponse
    {
        $customer ??= $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->actingAs($customer, 'customer');

        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession(self::STALE_PICK)
            ->post(self::WEB_COD . '?storefront=' . $country, ['payment_method' => 'cash_on_delivery', 'bring_change_amount' => self::CHANGE]);
    }

    private function labelOnTheOrder(): ?string
    {
        $this->assertSame(1, DB::table('orders')->count(), 'one order was placed');

        return DB::table('orders')->value('bring_change_amount_currency');
    }

    private function money(string $table, string $column): float
    {
        return (float) DB::table($table)->value($column);
    }

    /** @return array<string, float|string|null> */
    private function storedMoneyOfTheOrder(): array
    {
        return [
            'order_amount' => $this->money('orders', 'order_amount'),
            'bring_change_amount' => $this->money('orders', 'bring_change_amount'),
            'bring_change_amount_currency' => DB::table('orders')->value('bring_change_amount_currency'),
            'shipping_cost' => $this->money('orders', 'shipping_cost'),
            'discount_amount' => $this->money('orders', 'discount_amount'),
            'line_price' => $this->money('order_details', 'price'),
        ];
    }

    private function priceOf(int $productId): float
    {
        return (float) DB::table('products')->where('id', $productId)->value('unit_price');
    }

    /**
     * The one line of the claim page that names the currency, rendered from the page's own file together with that file's imports. The rest of
     * the page is a whole request's worth of view data and three routes of an add-on this repository does not carry; this line is the only one
     * of it this change touches, and the file has no guard above it (the test above pins that).
     */
    private function renderedCurrencyField(): string
    {
        $lines = explode("\n", file_get_contents(base_path('resources/themes/default/auction/web-views/product/details/_claim-checkout.blade.php')));
        $field = preg_grep('/name="current_currency_code"/', $lines);

        $this->assertCount(1, $field, 'the page names the currency of a claim on one line');

        return trim(\Illuminate\Support\Facades\Blade::render(implode("\n", [...preg_grep('/^@use\(/', $lines), ...$field])));
    }
}
