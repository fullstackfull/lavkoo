<?php

namespace Tests\Feature\Storefront;

use App\Models\Currency;
use App\Services\Address\AddressSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * The currency a cash-on-delivery order is stored with, measured on the route a shopper actually posts to (B-16).
 *
 * `Web\WebController::getCashOnDeliveryCheckoutComplete()` writes `bring_change_amount_currency` into the order it creates. Nothing on
 * that route calls `loadCurrency()` — not the controller, not a middleware of the `web` group, not a service provider — so whatever the
 * session happens to be carrying is what the order keeps. A visitor's currency pick is kept in the session and the session outlives the
 * storefront the pick was made on, which is how a shopper who browsed Saudi Arabia and then placed a Kuwaiti order of 30 dinars had
 * those dinars stored under `SAR`. The field is read back by the order pages, the invoice, the seller app and the seller VAT report.
 *
 * So these tests post a real `POST /checkout-complete`, with a real cart, a real customer, a real saved address and the real middleware
 * stack, and read the row that was written. **Nothing here calls `loadCurrency()`, warms a currency key or otherwise arranges the
 * precondition it is meant to detect**: the only thing put in the session is the stale pick a visitor carries by browsing.
 *
 * Measured on this world before the fix, and the reason it is a fix and not a characterisation:
 *
 * | Storefront | Session pick | Stored, before | Stored, after |
 * | --- | --- | --- | --- |
 * | KW (trades in dinars) | `SAR` | `SAR` | `KWD` |
 * | KW (trades in dinars) | none | `NULL` | `KWD` |
 * | SA (the default one) | `AED` | `AED` | `AED` |
 * | SA (the default one) | none | `NULL` | `NULL` |
 */
class StorefrontCodOrderCurrencyTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    /** The product each storefront sells in its own currency, as buildSixStorefrontCheckoutWorld() seeds them. */
    private const PRODUCT_OF = ['SA' => 1, 'AE' => 2, 'KW' => 3, 'QA' => 4, 'BH' => 5, 'OM' => 6];

    private const CHANGE_ASKED_FOR = 50;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        // The operator's own currency model, and the only one under which a visitor can pick a currency at all: the pick this measures
        // the effect of could not exist under the single-currency model.
        $this->setSetting('currency_model', json_encode('multi_currency'));
        DB::table('currencies')->where('code', 'SAR')->update(['exchange_rate' => 3.75]);
        cache()->forget('active_currency_list');
        $this->setAddressMode(AddressSchema::MODE_SOFT);
    }

    /** The whole of a currency pick, as a visitor who browsed that market carries it: the code, the sign, the rate and the currency row. */
    private function theVisitorsPickOf(string $currencyCode): array
    {
        $currency = Currency::query()->where('code', $currencyCode)->first();

        return [
            'system_default_currency_info' => $currency,
            'currency_code' => $currency->code,
            'currency_symbol' => $currency->symbol,
            'currency_exchange_rate' => $currency->exchange_rate,
        ];
    }

    /**
     * Places one cash-on-delivery order the way a shopper places it: a cart filled from the storefront, a saved address of their own, the
     * storefront header their browser sends, and the session they arrive with.
     *
     * @param array<string, mixed> $session what the visitor's session carries before they post
     */
    private function placeCashOnDeliveryOrder(string $country, array $session = []): TestResponse
    {
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id);
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: self::PRODUCT_OF[$country]);

        return $this->actingAs($customer, 'customer')
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Storefront' => $country])
            ->withSession($session + ['address_id' => $address->id, 'billing_address_id' => $address->id])
            ->post(route('checkout-complete'), ['payment_method' => 'cash_on_delivery', 'bring_change_amount' => self::CHANGE_ASKED_FOR]);
    }

    /** @return array{currency: string|null, amount: float, country: string|null} the order the route wrote, and nothing manufactured */
    private function theOrderThatWasPlaced(TestResponse $response): array
    {
        $response->assertOk()->assertJsonPath('status', 1);
        $this->assertSame(1, DB::table('orders')->count(), 'one order was placed');
        $order = DB::table('orders')->first();

        return [
            'currency' => $order->bring_change_amount_currency,
            'amount' => (float) $order->order_amount,
            'country' => $order->storefront_country,
        ];
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_cash_on_delivery_order_is_stored_in_this_markets_currency_whatever_the_visitor_picked_elsewhere(string $country, string $currencyCode): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $order = $this->theOrderThatWasPlaced($this->placeCashOnDeliveryOrder($country, $this->theVisitorsPickOf('SAR')));

        $this->assertSame($country, $order['country'], 'the order really was placed on this market');
        $this->assertSame($currencyCode, $order['currency'], 'the currency the change amount is stored under');
    }

    /**
     * The same market, reached by a visitor who has picked nothing at all — a first request, where the session holds no currency keys
     * because no money helper has warmed them. The field used to be stored empty here, which is a different wrong answer from the
     * stale one above, so both states are pinned.
     */
    public function test_a_first_time_visitor_with_no_pick_at_all_still_stores_this_markets_currency(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $order = $this->theOrderThatWasPlaced($this->placeCashOnDeliveryOrder('KW'));

        $this->assertSame('KWD', $order['currency']);
        $this->assertSame('KW', $order['country']);
    }

    /**
     * The amount and its label are the same money. A Kuwaiti product priced at 30 dinars is stored at 30 — the single-currency model is in
     * force on that market, so nothing converts it — and the label now says the dinar. Before the fix the row read `30` and `SAR`.
     */
    public function test_the_amount_stored_and_the_currency_stored_are_the_same_money(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $order = $this->theOrderThatWasPlaced($this->placeCashOnDeliveryOrder('KW', $this->theVisitorsPickOf('SAR')));

        $this->assertSame(30.0, $order['amount'], 'the dinar price as the catalogue holds it');
        $this->assertSame('KWD', $order['currency']);
    }

    /**
     * @return array<string, array{0: string|null}> the two session states a visitor of the default storefront can be in
     */
    public static function picksOnTheDefaultStorefront(): array
    {
        return ['a pick left on another market' => ['AED'], 'no pick at all' => [null]];
    }

    /**
     * **Dormancy.** The default storefront keeps the platform's own currency model and reads the visitor's pick exactly as it did before,
     * in either session state: a pick is stored, and an empty session still stores nothing rather than the platform's currency. Nothing
     * on the live Saudi surface moves, so this goes red if the fix starts answering for the default storefront too.
     */
    #[DataProvider('picksOnTheDefaultStorefront')]
    public function test_the_default_storefront_stores_exactly_what_it_stored_before(?string $pick): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $order = $this->theOrderThatWasPlaced($this->placeCashOnDeliveryOrder('SA', $pick === null ? [] : $this->theVisitorsPickOf($pick)));

        $this->assertSame($pick, $order['currency']);
        $this->assertSame('SA', $order['country']);
    }

    /**
     * And nothing on the route normalises the session on the way: the pick a visitor arrived with is still in their session after the
     * order was placed. This is the measurement the manual `loadCurrency()` in an earlier version of this proof stood in for — if some
     * middleware or provider ever does warm the session on this path, this assertion is what notices, and the one above still holds.
     */
    public function test_the_route_never_rewrites_the_visitors_pick_so_the_order_field_cannot_rely_on_it(): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        $response = $this->placeCashOnDeliveryOrder('KW', $this->theVisitorsPickOf('SAR'));

        $this->theOrderThatWasPlaced($response);
        $response->assertSessionHas('currency_code', 'SAR');
    }
}
