<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Amazon\AmazonFinanceMapper;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Services\SalesChannels\Trendyol\TrendyolFinanceMapper;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/**
 * Amazon refunds, read from the Finances statement, take their money off the revenue of the order line they are about (without tax, once, never more than the line earned),
 * leave the cost of the goods alone (nothing came back), and net the fees they reverse against the fees of the order. The figures are invented: an order of two units at 100.00 each,
 * 15 % tax shown apart, and 10.00 of shipping.
 */
class AmazonRefundsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const ORDER = '171-0000001-0000001';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->account = $this->amazonAccount();
    }

    /** @param list<array{sku: string, quantity?: int, total?: string}> $lines */
    private function order(array $lines = [['sku' => 'SKU-1']], string $orderId = self::ORDER, string $currency = 'SAR'): ExternalOrderMapping
    {
        $mapping = ExternalOrderMapping::create([
            'account_id' => $this->account->id, 'sales_channel' => 'amazon', 'seller_id' => 0, 'external_order_id' => $orderId, 'external_package_id' => '', 'status' => 'delivered',
            'fulfillment_mode' => 'fbm', 'currency' => $currency, 'prices_include_tax' => false, 'items_total' => '200', 'shipping_total' => '10', 'tax_total' => '30', 'order_total' => '240',
            'purchased_at' => '2026-11-05 09:00:00',
        ]);
        foreach ($lines as $index => $line) {
            ExternalOrderLine::create([
                'mapping_id' => $mapping->id, 'external_line_id' => 'ITEM-' . ($index + 1), 'sku' => $line['sku'], 'product_id' => 50 + $index, 'quantity' => $line['quantity'] ?? 2,
                'quantity_cancelled' => 0, 'line_total' => $line['total'] ?? '200', 'discount_total' => '0', 'tax_total' => '30', 'shipping_total' => '10', 'currency' => $currency, 'status' => 'open',
            ]);
        }

        return $mapping->fresh();
    }

    /** @param list<array<string, mixed>> $transactions */
    private function statement(array $transactions): array
    {
        $mapper = app(AmazonFinanceMapper::class);

        return app(ChannelFeeImporter::class)->import($this->account, array_merge(...array_map(fn (array $transaction): array => $mapper->map($transaction, $this->account), $transactions)));
    }

    private function document(ExternalOrderMapping $order): SaleDocument
    {
        return app(SaleDocumentReader::class)->forChannelOrder($order->id);
    }

    private function net(ExternalOrderMapping $order): string
    {
        return $this->document($order)->netRevenue()->toStorage();
    }

    /** @param array<string, string> $amounts */
    private function refund(array $amounts, string $id = '1', string $sku = 'SKU-1', string $orderId = self::ORDER): array
    {
        return Amazon::refund('TX-REFUND-' . $id, 'REFUND-' . $id, $orderId, [Amazon::refundItem($sku, 'ADJ-' . $id, $amounts)]);
    }

    private function line(ExternalOrderMapping $order, int $index = 0): ExternalOrderLine
    {
        return $order->lines()->orderBy('id')->get()[$index]->fresh();
    }

    public function test_a_full_refund_takes_the_goods_and_the_shipping_off_the_revenue_and_not_the_tax(): void
    {
        $order = $this->order();
        $this->assertSame('210.000000', $this->net($order), '200 of goods and 10 of shipping.');

        $this->statement([$this->refund(['principal' => '-200.00', 'shipping' => '-10.00', 'tax' => '-30.00'])]);

        $line = $this->line($order);
        $this->assertSame(['210.000000', '30.000000'], [$line->refunded_total, $line->refunded_tax]);
        $this->assertSame('0.000000', $this->net($order), 'Everything that was earned was given back; the 30.00 of tax never was revenue.');
    }

    public function test_a_partial_refund_reduces_only_its_amount(): void
    {
        $order = $this->order();

        $this->statement([$this->refund(['principal' => '-50.00', 'tax' => '-7.50'])]);

        $this->assertSame('50.000000', $this->line($order)->refunded_total);
        $this->assertSame('160.000000', $this->net($order));
    }

    public function test_a_promotion_that_was_taken_off_the_refund_is_part_of_what_was_given_back(): void
    {
        $order = $this->order();

        $this->statement([$this->refund(['principal' => '-200.00', 'promotion' => '20.00', 'tax' => '-27.00'])]);

        $this->assertSame('180.000000', $this->line($order)->refunded_total, 'The buyer got back 200 of goods less the 20 the promotion had already taken off.');
    }

    public function test_a_refund_without_a_physical_return_reduces_revenue_but_not_the_cost_of_goods(): void
    {
        $order = $this->order();
        app(CostLedger::class)->record(new CostDraft(
            subjectType: 'channel_order_line', subjectId: (int) $this->line($order)->id, component: CostComponent::Cogs, state: CostState::Actual, amount: Money::of('60'), currency: 'SAR',
            periodDate: CarbonImmutable::parse('2026-11-06'), source: 'layer', identity: 'layers:1', sellerId: 0, salesChannel: 'amazon', accountId: $this->account->id,
            externalOrderMappingId: $order->id, productId: 50, occurredAt: CarbonImmutable::parse('2026-11-06'),
        ));
        $before = app(ProfitabilityService::class)->forDocument($this->document($order));

        $this->statement([$this->refund(['principal' => '-100.00', 'tax' => '-15.00', 'commission' => '0'])]);

        $after = app(ProfitabilityService::class)->forDocument($this->document($order));
        $this->assertSame('210.000000', $before->revenue->toStorage());
        $this->assertSame('110.000000', $after->revenue->toStorage(), '100 of the goods were refunded.');
        $this->assertSame('60.000000', $before->actualCost->toStorage());
        $this->assertSame('60.000000', $after->actualCost->toStorage(), 'No unit came back, so the cost of the goods stays.');
        $this->assertSame('150.000000', $before->actualProfit()->toStorage());
        $this->assertSame('50.000000', $after->actualProfit()->toStorage());
        $this->assertSame(0, $this->line($order)->quantity_returned);
        $this->assertSame(0, $this->document($order)->lines[0]->quantityReturned);
    }

    public function test_a_refund_never_takes_more_than_the_line_earned_and_the_figure_the_channel_gave_is_kept(): void
    {
        $order = $this->order();

        $this->statement([$this->refund(['principal' => '-500.00', 'tax' => '-75.00'])]);

        $this->assertSame('500.000000', $this->line($order)->refunded_total, 'What the channel said is kept.');
        $this->assertSame('210.000000', $this->document($order)->lines[0]->refunded->toStorage(), 'The goods and the shipping it was charged: no more.');
        $this->assertSame('0.000000', $this->net($order), 'Never a negative revenue.');
    }

    public function test_two_refunds_of_one_line_add_up(): void
    {
        $order = $this->order();

        $this->statement([$this->refund(['principal' => '-50.00', 'tax' => '-7.50'], '1'), $this->refund(['principal' => '-30.00', 'tax' => '-4.50'], '2')]);

        $this->assertSame('80.000000', $this->line($order)->refunded_total);
        $this->assertSame('12.000000', $this->line($order)->refunded_tax);
        $this->assertSame('130.000000', $this->net($order));
    }

    public function test_the_same_refund_delivered_twice_or_read_again_counts_once(): void
    {
        $order = $this->order();
        $transaction = $this->refund(['principal' => '-50.00', 'tax' => '-7.50']);

        $first = $this->statement([$transaction]);
        $second = $this->statement([$transaction]);

        $this->assertGreaterThan(0, $first['new']);
        $this->assertSame(0, $second['new']);
        $this->assertSame('50.000000', $this->line($order)->refunded_total);
        $this->assertSame('160.000000', $this->net($order));
    }

    public function test_a_refund_that_is_released_after_it_was_deferred_is_one_refund_even_when_its_posted_date_moved(): void
    {
        $order = $this->order();
        $deferred = Amazon::refund('TX-REFUND-1', 'REFUND-1', self::ORDER, [Amazon::refundItem('SKU-1', 'ADJ-1', ['principal' => '-50.00', 'tax' => '-7.50'])], '2026-11-08T10:00:00Z', 'DEFERRED');
        $released = Amazon::refund('TX-REFUND-1', 'REFUND-1', self::ORDER, [Amazon::refundItem('SKU-1', 'ADJ-1', ['principal' => '-50.00', 'tax' => '-7.50'])], '2026-11-12T10:00:00Z', 'DEFERRED_RELEASED');

        $this->statement([$deferred]);
        $this->statement([$released]);

        $this->assertSame('50.000000', $this->line($order)->refunded_total, 'Counted once, not 100.');
    }

    public function test_a_refund_for_an_order_we_do_not_hold_is_kept_for_review_counted_nowhere_and_applied_when_the_order_arrives(): void
    {
        $other = $this->order(orderId: '171-9999999-9999999');

        $this->statement([$this->refund(['principal' => '-50.00', 'tax' => '-7.50'])]);

        $this->assertNull($this->line($other)->refunded_total, 'Not the order it is about.');
        $events = SalesChannelEvent::where('event_type', 'like', 'finance.refund.%')->get();
        $this->assertNotEmpty($events);
        $this->assertSame([SalesChannelEvent::NEEDS_REVIEW], $events->pluck('outcome')->unique()->all());
        $this->assertSame([SalesChannelEvent::NOTE_ORDER_NOT_HELD], $events->pluck('outcome_note')->unique()->all());

        $order = $this->order();
        app(ChannelFeeImporter::class)->import($this->account, []);

        $this->assertSame('50.000000', $this->line($order)->refunded_total, 'The order has arrived: the refund is applied, without the channel sending it again.');
        $this->assertSame([SalesChannelEvent::APPLIED], SalesChannelEvent::where('event_type', 'like', 'finance.refund.%')->pluck('outcome')->unique()->all());
        $this->assertSame('160.000000', $this->net($order));
    }

    public function test_an_item_that_matches_no_single_line_is_kept_for_a_person_and_counted_nowhere(): void
    {
        $order = $this->order([['sku' => 'SKU-1', 'total' => '100'], ['sku' => 'SKU-2', 'total' => '100']]);

        $this->statement([$this->refund(['principal' => '-50.00'], sku: 'SKU-9')]);

        $this->assertNull($this->line($order, 0)->refunded_total);
        $this->assertNull($this->line($order, 1)->refunded_total);
        $this->assertSame([SalesChannelEvent::NOTE_LINE_NOT_MATCHED], SalesChannelEvent::where('event_type', 'like', 'finance.refund.%')->pluck('outcome_note')->unique()->all());
    }

    public function test_the_refund_of_the_other_line_of_an_order_goes_to_that_line_by_its_sku(): void
    {
        $order = $this->order([['sku' => 'SKU-1', 'total' => '100'], ['sku' => 'SKU-2', 'total' => '100']]);

        $this->statement([$this->refund(['principal' => '-40.00'], sku: 'SKU-2')]);

        $this->assertNull($this->line($order, 0)->refunded_total);
        $this->assertSame('40.000000', $this->line($order, 1)->refunded_total);
    }

    public function test_a_refund_that_names_no_sku_goes_to_the_only_line_of_the_order(): void
    {
        $order = $this->order();

        $this->statement([$this->refund(['principal' => '-40.00'], sku: '')]);

        $this->assertSame('40.000000', $this->line($order)->refunded_total);
    }

    public function test_an_amount_of_a_refund_that_is_none_of_its_parts_is_kept_for_a_person_and_never_counted(): void
    {
        $order = $this->order();

        $stats = $this->statement([$this->refund(['principal' => '-50.00', 'other' => '-5.00'])]);

        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame('50.000000', $this->line($order)->refunded_total, 'The gift wrap is not guessed at.');
        $this->assertSame(1, SalesChannelEvent::where('event_type', 'like', 'finance.unclassified.%')->where('outcome', 'needs_review')->count());
    }

    public function test_a_refund_in_another_currency_than_the_order_is_not_applied(): void
    {
        $order = $this->order(currency: 'AED');

        $this->statement([$this->refund(['principal' => '-50.00'])]);

        $this->assertNull($this->line($order)->refunded_total);
        $this->assertSame([SalesChannelEvent::NOTE_CURRENCY_DIFFERS], SalesChannelEvent::where('event_type', 'like', 'finance.refund.%')->pluck('outcome_note')->unique()->all());
    }

    public function test_a_refund_is_applied_whatever_the_cost_module_switch_says_while_its_fees_wait(): void
    {
        config(['operational_costs.enabled' => false]);
        $order = $this->order();

        $this->statement([$this->refund(['principal' => '-50.00', 'tax' => '-7.50'])]);

        $this->assertSame('50.000000', $this->line($order)->refunded_total, 'It is the order\'s own record, not a posting to the ledger.');
        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame(1, SalesChannelEvent::where('event_type', 'like', 'finance.fee.%')->where('outcome', 'received')->count(), 'The commission the refund reverses is held like any fee.');
    }

    public function test_a_refund_survives_the_order_being_read_again_from_the_channel(): void
    {
        $order = $this->order();
        $this->statement([$this->refund(['principal' => '-50.00', 'tax' => '-7.50'])]);

        $line = $this->line($order);
        $line->fill(['title' => 'Read again', 'status' => 'open'])->save();

        $this->assertSame('50.000000', $line->fresh()->refunded_total);
        $this->assertArrayHasKey('refunded_by_event', (array) $line->fresh()->snapshot);
    }

    // ---- fees on returns ------------------------------------------------------------------------------------------

    /** @return array<string, string> component => amount of the effective actual entries of the order */
    private function actuals(): array
    {
        $actuals = OperationalCostEntry::where('is_effective', true)->where('state', CostState::Actual->value)->pluck('amount', 'cost_component')->all();
        ksort($actuals);

        return $actuals;
    }

    public function test_the_commission_a_refund_gives_back_and_the_fee_charged_on_it_net_the_orders_fees(): void
    {
        $this->order();
        $shipment = Amazon::transaction('TX-SHIP-1');
        $refund = $this->refund(['principal' => '-100.00', 'tax' => '-15.00', 'commission' => '20.00', 'refund_commission' => '-5.00']);

        $this->statement([$shipment]);
        $this->assertSame(['fba_fulfillment' => '10.000000', 'marketplace_commission' => '20.000000'], $this->actuals());

        $this->statement([$refund]);

        $this->assertSame(['fba_fulfillment' => '10.000000', 'marketplace_commission' => '5.000000'], $this->actuals(), '20 charged, 20 given back, 5 charged on the refund: 5 is kept.');
        $this->assertSame(1, OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->count(), 'One net, not three entries.');
    }

    public function test_a_commission_returned_in_a_refund_nets_to_nothing_and_does_not_make_the_order_cost_negative(): void
    {
        $this->order();

        $this->statement([Amazon::transaction('TX-SHIP-1'), $this->refund(['principal' => '-200.00', 'tax' => '-30.00', 'commission' => '20.00'])]);

        $this->assertSame('0.000000', $this->actuals()['marketplace_commission']);
    }

    public function test_the_refund_of_an_amazon_order_is_read_item_by_item_while_a_shipment_is_still_read_at_one_level(): void
    {
        $mapper = app(AmazonFinanceMapper::class);
        $refund = $mapper->map($this->refund(['principal' => '-100.00', 'tax' => '-15.00', 'commission' => '20.00']), $this->account);
        $shipment = $mapper->map(Amazon::twoItemTransaction(transactionLevel: true), $this->account);

        $byCategory = static fn (array $entries): array => array_map(static fn ($entry): string => $entry->category, $entries);
        $this->assertSame([RemoteFinancialEntry::REFUND, RemoteFinancialEntry::REFUND, RemoteFinancialEntry::FEE], $byCategory($refund));
        $this->assertSame('ADJ-1', $refund[0]->lineId, 'The refund names the item it is about.');
        $this->assertSame(['principal', 'tax'], [$refund[0]->meta['refund_part'], $refund[1]->meta['refund_part']]);
        $this->assertSame('REFUND-1', $refund[0]->meta['refund_id']);
        $this->assertCount(2, $shipment, 'Unchanged: the transaction-level breakdowns already add up its items\'.');
    }

    // ---- Trendyol: the commission given back on a return row ------------------------------------------------------------

    public function test_a_trendyol_return_row_gives_the_commission_back_against_the_sale_rows_commission(): void
    {
        $account = $this->trendyolAccount();
        ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'status' => 'delivered', 'currency' => 'SAR']);
        $mapper = app(TrendyolFinanceMapper::class);
        $sale = $mapper->settlement(Trendyol::settlement('row-1', 25.0), 'Sale', $account);
        $return = $mapper->settlement(Trendyol::settlement('row-2', 10.0, 0.0, ['transactionType' => 'Return', 'debt' => 68.0, 'sellerRevenue' => -58.0]), 'Return', $account);

        app(ChannelFeeImporter::class)->import($account, $sale);
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
        app(ChannelFeeImporter::class)->import($account, $return);

        $this->assertSame(['marketplace_commission' => '15.000000'], $this->actuals(), '25 charged on the sale, 10 given back with the return.');
    }

    public function test_a_trendyol_return_that_gives_all_the_commission_back_nets_it_to_nothing(): void
    {
        $account = $this->trendyolAccount();
        ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '10234567890', 'external_package_id' => '3012345678', 'status' => 'delivered', 'currency' => 'SAR']);
        $mapper = app(TrendyolFinanceMapper::class);

        app(ChannelFeeImporter::class)->import($account, [...$mapper->settlement(Trendyol::settlement('row-1', 25.0), 'Sale', $account), ...$mapper->settlement(Trendyol::settlement('row-2', -25.0, 0.0, ['transactionType' => 'Return', 'debt' => 170.0]), 'Return', $account)]);

        $this->assertSame(['marketplace_commission' => '0.000000'], $this->actuals());
    }

    public function test_a_fee_type_nobody_classified_is_held_for_review_in_a_refund_as_it_is_anywhere_else(): void
    {
        $this->order();
        $odd = Amazon::refund('TX-REFUND-9', 'REFUND-9', self::ORDER, [[
            'description' => 'odd', 'totalAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-7.00'],
            'contexts' => [['contextType' => 'ProductContext', 'sku' => 'SKU-1']],
            'breakdowns' => [['breakdownType' => 'BrandNewThingAmazonInvented', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-7.00'], 'breakdowns' => []]],
        ]]);

        $stats = $this->statement([$odd]);

        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame([], $this->actuals());
    }
}
