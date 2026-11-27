<?php

namespace Tests\Feature\Payments;

use App\Services\Storefront\RefundFromOriginalPayment;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\KeepsOrderDebugLogClean;

/**
 * M2-11: a refund and a webhook replay for a payment of EACH of the six markets finish in that market.
 *
 * Both halves were proved for one market at a time and never for the six. The replay guard -- the claim a paid settlement makes on the
 * payment's own row, so that two deliveries of one gateway callback build one order and not two -- was measured in Saudi riyals only
 * (PaidCallbackBuildsOneOrderTest). The refund worked out from the original payment was measured per CURRENCY (RefundFromOriginalPaymentTest
 * names USD, SAR, AED and KWD) and never behind a storefront, so no test said that the order a market's callback builds, and the money a
 * market's refund gives back, stay in that market.
 *
 * The two belong in one file because they are one sequence and the second depends on the first: the replay decides which order exists, and
 * the refund is worked out from that order's own recorded market, currency and amount. Splitting them would leave the join untested, which
 * is where a market can be lost.
 *
 * Each case drives the real order writer as the settlement hook calls it, with the market in force, against the six-storefront world whose
 * markets each sell their own product at their own price in their own currency. Nothing is evaluated on its own.
 */
class ARefundAndAReplayFinishInTheirOwnCountryTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;
    use KeepsOrderDebugLogClean;

    /** The payment row of the callback under test. `payment_requests.id` is a uuid on this platform, not an integer. */
    private const PAYMENT_ROW = '7c1e4b90-5d22-4a61-8f03-9ab6c2d4e501';

    private const REFERENCE = 'TXN-SIX-MARKETS';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->rememberOrderDebugLog();
        $this->setSetting('currency_model', 'multi_currency');
    }

    /**
     * The guard that makes every three-decimal figure below mean something: the fixture must build the gateway's own amount column at the
     * precision the real migration leaves it.
     *
     * It was built at (24,2), which ROUNDED a dinar on its way in -- 12.505 BHD stored as 12.51 -- so these refund cases measured the
     * fixture and not the platform, and reported a lost minor unit the platform does not lose. A later narrowing would turn this file green
     * on a false premise, which is what this pins.
     */
    public function test_the_gateway_amount_column_can_hold_what_the_platform_stores(): void
    {
        $this->storeThePaidPayment(currency: 'BHD', paid: '12.505');

        $this->assertSame(
            '12.505',
            bcadd((string) DB::table('payment_requests')->where('id', self::PAYMENT_ROW)->value('payment_amount'), '0', 3),
            'the fixture must hold the three decimals of a dinar, or a three-decimal payment test proves nothing',
        );
    }

    protected function tearDown(): void
    {
        $this->restoreOrderDebugLog();

        parent::tearDown();
    }

    /**
     * The six markets, the product each sells in this world, the currency it trades in and what the gateway took in it.
     *
     * The paid figure is NOT the order total and is not meant to be: a refund worked out from the original payment gives back the share of
     * what the gateway actually took, so a figure of its own is what makes the arithmetic visible. Three of the six are three-decimal
     * currencies, which is where a lost minor unit would show.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: int}>
     */
    public static function theSixMarkets(): array
    {
        return [
            'Saudi Arabia' => ['SA', 1, 'SAR', '100.00', 2],
            'United Arab Emirates' => ['AE', 2, 'AED', '137.41', 2],
            'Kuwait' => ['KW', 3, 'KWD', '10.000', 3],
            'Qatar' => ['QA', 4, 'QAR', '45.50', 2],
            'Bahrain' => ['BH', 5, 'BHD', '12.505', 3],
            'Oman' => ['OM', 6, 'OMR', '14.001', 3],
        ];
    }

    /** The market a direct call to the order writer belongs to: the context an HTTP request of that storefront would have been served. */
    private function serveTheMarket(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    /** The payment the gateway is calling back about, in the table the payment module owns, in the market's own currency. */
    private function storeThePaidPayment(string $currency, string $paid): void
    {
        DB::table('payment_requests')->insert([
            'id' => self::PAYMENT_ROW, 'payment_method' => 'ssl_commerz', 'transaction_id' => self::REFERENCE,
            'payment_amount' => $paid, 'currency_code' => $currency, 'is_paid' => 1, 'additional_data' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The order writer as the settlement hook calls it: paid, carrying the gateway's reference and the payment's own row. */
    private function deliverTheCallback(object $customer): array
    {
        request()->merge(['user' => $customer]);
        request()->setUserResolver(fn () => $customer);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0, 'customer_id' => $customer->id, 'order_status' => 'confirmed', 'payment_method' => 'ssl_commerz',
            'payment_status' => 'paid', 'transaction_ref' => self::REFERENCE, 'payment_record_id' => self::PAYMENT_ROW,
            'requestObj' => request(), 'enforce_address_rules' => false, 'stock_policy' => 'allow_oversell',
        ]);
    }

    /** @return array<string, mixed> the one order the callback built */
    private function theOneOrder(): array
    {
        $this->assertSame(1, DB::table('orders')->count(), 'one payment, one order');

        return (array) DB::table('orders')->first();
    }

    // ---- the webhook replay ---------------------------------------------------------------------------------------------------------

    #[DataProvider('theSixMarkets')]
    public function test_a_replayed_callback_of_each_market_builds_one_order_and_it_stays_in_that_market(string $country, int $productId, string $currency, string $paid): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->storeThePaidPayment(currency: $currency, paid: $paid);
        $this->serveTheMarket($country);

        $first = $this->deliverTheCallback($customer);

        $this->assertCount(1, $first, 'the first delivery builds the order');

        // The second delivery of the same callback, with the cart refilled as it is when the shopper went back and added something.
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->serveTheMarket($country);
        $replay = $this->deliverTheCallback($customer);

        $order = $this->theOneOrder();

        $this->assertSame([], $replay, 'the replay of one payment builds nothing, in every market');
        $this->assertSame($country, $order['storefront_country'], 'and the one order that exists is recorded in the market that sold it');
        $this->assertSame(self::REFERENCE, $order['transaction_ref']);
        $this->assertSame(
            $this->currencyTheMarketRecordsOnItsOrders($country, $currency),
            $order['currency_code'],
            'in the money of that market, and the Saudi market is deliberately the one that records none',
        );
    }

    /**
     * What a market writes into `orders.currency_code`: its own currency, except for the DEFAULT market under the multi-currency model,
     * which records NOTHING and is not a gap.
     *
     * `StorefrontCurrency::currencyOfSales()` answers null for the default country while the platform converts from a base currency,
     * because the Saudi market has no currency of its own to record there -- it reads the platform settings, where the shopper's own pick
     * decides. Writing SAR into that column would be this programme inventing a money label for the market that already works, which is the
     * one behaviour that must not change. The refund below is unaffected: it reads the currency the GATEWAY took, from the payment record,
     * and not the label on the order.
     */
    private function currencyTheMarketRecordsOnItsOrders(string $country, string $currency): ?string
    {
        return $country === StorefrontResolver::fromConfig()->defaultCountry() ? null : $currency;
    }

    #[DataProvider('theSixMarkets')]
    public function test_the_order_a_market_s_callback_built_holds_only_that_market_s_product(string $country, int $productId): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->storeThePaidPayment(currency: 'SAR', paid: '1.00');
        $this->serveTheMarket($country);

        $this->deliverTheCallback($customer);

        $this->assertSame(
            [$productId],
            DB::table('order_details')->pluck('product_id')->map(static fn ($id): int => (int) $id)->all(),
            'the lines of the order are the market own catalogue and no other market product',
        );
    }

    // ---- the refund of that payment -------------------------------------------------------------------------------------------------

    #[DataProvider('theSixMarkets')]
    public function test_a_refund_of_each_market_s_payment_is_given_back_in_that_market_s_money_to_its_own_decimals(string $country, int $productId, string $currency, string $paid, int $decimals): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->storeThePaidPayment(currency: $currency, paid: $paid);
        $this->serveTheMarket($country);
        $this->deliverTheCallback($customer);
        $order = $this->theOneOrder();

        $refund = app(RefundFromOriginalPayment::class)->of(order: $order, refundAmount: $order['order_amount']);

        $this->assertSame($currency, $refund['currency'], 'a refund of this market is given back in this market money');
        $this->assertSame($paid, $refund['paid'], 'and it is worked out from what the gateway really took, not from a converted figure');
        $this->assertSame($decimals, strlen(substr($refund['amount'], (int) strpos($refund['amount'], '.') + 1)), 'to the decimals of that currency');
        $this->assertSame($paid, $refund['amount'], 'a refund of the whole order gives back exactly what was paid');
    }

    #[DataProvider('theSixMarkets')]
    public function test_however_a_market_s_order_is_refunded_its_refunds_add_up_to_exactly_what_was_paid(string $country, int $productId, string $currency, string $paid, int $decimals): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $productId);
        $this->storeThePaidPayment(currency: $currency, paid: $paid);
        $this->serveTheMarket($country);
        $this->deliverTheCallback($customer);
        $order = $this->theOneOrder();

        $computation = app(RefundFromOriginalPayment::class);
        $third = bcdiv((string) $order['order_amount'], '3', 2);
        $refunded = '0';
        $given = [];
        $currencies = [];

        foreach ([$third, $third, bcsub((string) $order['order_amount'], bcmul($third, '2', 2), 2)] as $part) {
            $refund = $computation->of(order: $order, refundAmount: $part, alreadyRefunded: $refunded);
            $given[] = $refund['amount'];
            $currencies[] = $refund['currency'];
            $refunded = bcadd($refunded, $part, 2);
        }

        $total = array_reduce($given, static fn (string $sum, string $part): string => bcadd($sum, $part, $decimals), '0');

        $this->assertSame($paid, $total, 'no minor unit of this market money is lost or made up over three refunds');
        $this->assertSame([$currency], array_values(array_unique($currencies)), 'and every one of the three was in that market money');
    }
}
