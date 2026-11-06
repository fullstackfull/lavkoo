<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use App\Services\Fulfillment\Support\ProviderCurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPilotServerSchema;

/**
 * Real money on the pilot server's store: a single-currency store whose riyal is the currency "KSA" (id 8, rate "1"), rates kept as text,
 * and FULFILLMENT_CURRENCY=SAR. The figures a provider is told to collect, and the code it is told they are in, must be the riyal amounts
 * the customer saw, with the store's own currency rows left exactly as they are.
 */
class PilotServerMoneyTest extends FulfillmentTestCase
{
    use BuildsPilotServerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePilotServerSchema();
    }

    private function router(): FulfillmentRouter
    {
        return app(FulfillmentRouter::class);
    }

    private function convert(float|int|string|null $stored): ?float
    {
        return app(ProviderCurrency::class)->fromStored($stored);
    }

    /** One Torod Point line worth 200, on an order the customer pays in cash on delivery. */
    private function cashOnDeliveryOrder(int $orderId, float $orderAmount, float $paidAmount = 0.0): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder($orderId, ['order_amount' => $orderAmount, 'paid_amount' => $paidAmount, 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        $this->addLine($orderId, 1, 2, 200, ProviderCode::TorodPoint);
    }

    private function capture(float $net, float $tax = 0.0, string $taxModel = 'include', int $orderDetailId = 1): ProviderFulfillmentItem
    {
        $this->makeOrder(1);

        return app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: 1,
            orderDetailId: $orderDetailId,
            product: ['id' => 10, 'name' => 'Item', 'code' => 'SKU10', 'fulfillment_provider' => 'm5azn', 'variation' => '[]'],
            variant: '',
            quantity: 2,
            unitPrice: $net / 2,
            netAmount: $net,
            lineTax: $tax,
            taxModel: $taxModel,
        );
    }

    public function test_the_pilot_riyal_converts_one_to_one_and_rounds_exactly_like_a_store_that_calls_its_riyal_sar(): void
    {
        $plain = [0, 0.004, 0.005, 1, 19, 99.99, 123.456, 1234.5678, '250.50', '19', null];
        $awkwardInBinary = [2.675, 1.005, 10.005, 0.285, 179.745, 250.505, 99999999.995];
        $amounts = [...$plain, ...$awkwardInBinary];

        $onKsa = array_map(fn (float|int|string|null $amount): ?float => $this->convert($amount), $amounts);
        DB::table('currencies')->where('id', 8)->update(['code' => 'SAR']);
        Cache::flush();
        $onSar = array_map(fn (float|int|string|null $amount): ?float => $this->convert($amount), $amounts);

        $this->assertSame($onSar, $onKsa, 'the store\'s spelling of its riyal must not change a single figure');
        $this->assertSame([0.0, 0.0, 0.01, 1.0, 19.0, 99.99, 123.46, 1234.57, 250.5, 19.0, 0.0], array_slice($onKsa, 0, count($plain)));
    }

    public function test_a_provider_is_told_sar_even_when_the_environment_uses_the_stores_spelling(): void
    {
        foreach (['SAR', 'sar', 'KSA', ' ksa '] as $configured) {
            config(['fulfillment.currency' => $configured]);

            $this->assertSame('SAR', app(ProviderCurrency::class)->code(), "FULFILLMENT_CURRENCY={$configured}");
        }
    }

    public function test_a_provider_price_becomes_one_of_ours_unchanged_on_the_single_currency_store(): void
    {
        $this->assertSame(19.0, app(ProviderCurrency::class)->toStored(19));
        $this->assertSame(12.5, app(ProviderCurrency::class)->toStored('12.5'));
    }

    public function test_a_cash_on_delivery_order_tells_the_provider_to_collect_the_exact_riyal_amount_in_sar(): void
    {
        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5);

        $this->router()->route(100);

        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->status);
        $this->assertSame(PaymentMode::CashOnDelivery, $fulfillment->payment_mode);
        $this->assertSame('SAR', $fulfillment->currency, 'never the store\'s own "KSA"');
        $this->assertEqualsWithDelta(215.50, (float) $fulfillment->cod_amount, 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $fulfillment->items_total, 0.001);
        $this->assertEqualsWithDelta(15.50, (float) $fulfillment->shipping_allocation, 0.001);
        $this->assertEqualsWithDelta(215.50, (float) $fulfillment->total_amount, 0.001);
    }

    public function test_a_part_paid_cash_on_delivery_order_collects_only_what_is_still_owed(): void
    {
        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5, paidAmount: 100.0);

        $this->router()->route(100);

        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertEqualsWithDelta(115.50, (float) $fulfillment->cod_amount, 0.001);
        $this->assertEqualsWithDelta(215.50, (float) $fulfillment->total_amount, 0.001);
    }

    public function test_a_prepaid_order_split_between_two_providers_adds_up_to_the_cent(): void
    {
        $this->makeOrder(100, ['order_amount' => 115.35]);
        $this->addLine(100, 1, 1, 40, ProviderCode::M5azn);
        $this->addLine(100, 2, 1, 35, ProviderCode::TorodPoint);
        $this->addLine(100, 3, 2, 25, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $m5azn = $this->fulfillmentFor(100, ProviderCode::M5azn);
        $torod = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertEqualsWithDelta(46.14, (float) $m5azn->total_amount, 0.001);
        $this->assertEqualsWithDelta(69.21, (float) $torod->total_amount, 0.001);
        $this->assertEqualsWithDelta(115.35, (float) ProviderFulfillment::sum('total_amount'), 0.001);
        $this->assertSame(['SAR', 'SAR'], ProviderFulfillment::orderBy('id')->pluck('currency')->all());
        $this->assertSame(PaymentMode::Prepaid, $m5azn->payment_mode);
        $this->assertEqualsWithDelta(0.0, (float) $m5azn->cod_amount, 0.001);
    }

    public function test_a_provider_shipment_that_shares_an_order_with_an_in_house_line_gets_its_pro_rata_share_of_the_extras(): void
    {
        $this->makeOrder(100, ['order_amount' => 120.0]);
        $this->addLine(100, 1, 1, 50, null);
        $this->addLine(100, 2, 1, 50, ProviderCode::TorodPoint);

        $this->router()->route(100);

        $torod = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertEqualsWithDelta(50.0, (float) $torod->items_total, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $torod->total_amount, 0.001, 'its lines plus half of the 20 that are not lines');
        $this->assertEqualsWithDelta(10.0, (float) $torod->shipping_allocation, 0.001);
    }

    public function test_without_the_alias_the_pilot_riyal_is_a_different_currency_and_the_shipment_is_held_not_sent_with_a_guess(): void
    {
        config(['fulfillment.currency_aliases' => []]);
        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5);

        $this->router()->route(100);

        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->status);
        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $fulfillment->hold_reason);
    }

    public function test_a_shipment_held_for_its_currency_is_released_with_the_right_figures_once_the_alias_is_configured(): void
    {
        config(['fulfillment.currency_aliases' => []]);
        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5);
        $this->router()->route(100);
        $held = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame(FulfillmentStatus::Held, $held->status);

        config(['fulfillment.currency_aliases' => ['KSA' => 'SAR']]);
        $released = $this->router()->reevaluateHold($held->id);

        $this->assertTrue($released);
        $fulfillment = $held->fresh();
        $this->assertSame(FulfillmentStatus::Pending, $fulfillment->status);
        $this->assertNull($fulfillment->hold_reason);
        $this->assertEqualsWithDelta(215.50, (float) $fulfillment->cod_amount, 0.001);
        $this->assertEqualsWithDelta(215.50, (float) $fulfillment->total_amount, 0.001);
    }

    public function test_a_single_currency_store_whose_default_currency_is_not_the_riyal_is_held_even_with_the_alias(): void
    {
        $this->useStoreCurrencies(model: 'single_currency', defaultCurrencyId: 1, rows: [1 => ['USD', '1'], 8 => ['KSA', '1']]);
        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5);

        $this->router()->route(100);

        $this->assertNull($this->convert(10));
        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $this->fulfillmentFor(100, ProviderCode::TorodPoint)->hold_reason);
    }

    /** @return iterable<string, array{string, float}> */
    public static function usableTextRates(): iterable
    {
        yield 'plain' => ['3.75', 37.5];
        yield 'spaces around it' => [' 3.75 ', 37.5];
        yield 'trailing zeros' => ['3.7500000000', 37.5];
        yield 'whole number' => ['4', 40.0];
    }

    #[DataProvider('usableTextRates')]
    public function test_a_multi_currency_store_converts_with_a_text_rate_kept_under_the_stores_own_spelling(string $rate, float $expected): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', $rate]]);

        $this->assertSame($expected, $this->convert(10));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableTextRates(): iterable
    {
        yield 'comma as the decimal mark' => ['3,75'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-3.75'];
        yield 'exponent' => ['1e3'];
        yield 'arabic-indic digits' => ['٣٫٧٥'];
        yield 'two points' => ['3.7.5'];
        yield 'thousands separator' => ['1,000.5'];
    }

    #[DataProvider('unusableTextRates')]
    public function test_a_rate_nobody_can_read_holds_the_shipment_instead_of_converting_with_a_guess(string $rate): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', $rate]]);
        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5);

        $this->router()->route(100);

        $this->assertNull($this->convert(10));
        $this->assertNull(app(ProviderCurrency::class)->toStored(10));
        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $this->fulfillmentFor(100, ProviderCode::TorodPoint)->hold_reason);
    }

    public function test_a_multi_currency_store_without_a_usd_base_row_cannot_convert(): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [8 => ['KSA', '3.75']]);

        $this->assertNull($this->convert(10));
    }

    public function test_when_both_spellings_exist_the_iso_row_decides_and_a_bad_iso_rate_is_not_papered_over_by_the_alias_row(): void
    {
        $this->useStoreCurrencies(model: 'multi_currency', defaultCurrencyId: 8, rows: [1 => ['USD', '1'], 8 => ['KSA', '9'], 9 => ['SAR', '3.75']]);
        $this->assertSame(37.5, $this->convert(10));

        DB::table('currencies')->where('id', 9)->update(['exchange_rate' => 'abc']);
        Cache::flush();

        $this->assertNull($this->convert(10), 'a rate that cannot be read is refused, not replaced by the other row\'s');
    }

    public function test_an_order_line_snapshot_keeps_the_real_riyal_amount_including_exclusive_tax(): void
    {
        $item = $this->capture(net: 50.0, tax: 7.5, taxModel: 'exclude');

        $this->assertEqualsWithDelta(57.5, (float) $item->line_total, 0.001);
        $this->assertTrue($item->snapshot['currency_converted']);
        $this->assertEqualsWithDelta(25.0, (float) $item->unit_price, 0.001, 'the price the customer paid is not touched');
    }

    public function test_without_the_alias_the_snapshot_would_record_zero_and_say_it_was_not_converted(): void
    {
        config(['fulfillment.currency_aliases' => []]);

        $item = $this->capture(net: 50.0);

        $this->assertEqualsWithDelta(0.0, (float) $item->line_total, 0.001);
        $this->assertFalse($item->snapshot['currency_converted']);
    }

    public function test_a_line_captured_while_its_amount_could_not_be_converted_is_never_released_priced_at_nothing(): void
    {
        config(['fulfillment.currency_aliases' => []]);
        $this->makeProduct(10, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_amount' => 215.5, 'payment_method' => 'cash_on_delivery', 'payment_status' => 'unpaid']);
        $detailId = $this->addLine(100, 10, 2, 200);
        app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: 100,
            orderDetailId: $detailId,
            product: ['id' => 10, 'name' => 'Item', 'code' => 'SKU10', 'fulfillment_provider' => 'torod_point', 'variation' => '[]'],
            variant: '',
            quantity: 2,
            unitPrice: 100.0,
            netAmount: 200.0,
            lineTax: 0.0,
            taxModel: 'include',
        );
        $this->assertFalse(ProviderFulfillmentItem::firstOrFail()->snapshot['currency_converted'], 'the line was frozen with a total of 0');

        config(['fulfillment.currency_aliases' => ['KSA' => 'SAR']]);
        $this->router()->route(100);

        $fulfillment = $this->fulfillmentFor(100, ProviderCode::TorodPoint);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->status, 'the order total converts now, but the line total cannot be recovered');
        $this->assertSame(HoldReason::CURRENCY_UNCONVERTIBLE, $fulfillment->hold_reason);
        $this->assertFalse($this->router()->reevaluateHold($fulfillment->id));
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->fresh()->status);
    }

    public function test_none_of_this_changes_the_stores_currency_rows_or_settings(): void
    {
        $snapshot = static fn (): array => [
            DB::table('currencies')->orderBy('id')->get()->all(),
            DB::table('business_settings')->orderBy('type')->get()->all(),
        ];
        $before = $snapshot();

        $this->cashOnDeliveryOrder(orderId: 100, orderAmount: 215.5);
        $this->router()->route(100);
        $this->convert(19.99);
        app(ProviderCurrency::class)->toStored(19);
        $this->capture(net: 50.0, tax: 7.5, taxModel: 'exclude', orderDetailId: 900);

        $this->assertEquals($before, $snapshot());
        $this->assertSame('KSA', DB::table('currencies')->where('id', 8)->value('code'));
        $this->assertSame('1', DB::table('currencies')->where('id', 8)->value('exchange_rate'));
    }
}
