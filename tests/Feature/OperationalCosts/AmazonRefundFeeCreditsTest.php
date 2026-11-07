<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Amazon\AmazonFinanceMapper;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;
use Tests\TestCase;

/**
 * A fact Amazon reports is one fact however often it is reported and whatever date it carries then: a transaction that was deferred and is released later has a new posted date,
 * and the fees it holds (the commission a refund gives back, the commission and the fulfilment fee of a sale) are recorded once, as its revenue is. The figures are invented: a sale
 * charged 20 of commission and 10 of fulfilment fee, and a refund that gives the 20 of commission back.
 */
class AmazonRefundFeeCreditsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const ORDER = '171-0000001-0000001';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true]);
        $this->account = $this->amazonAccount();
        $mapping = ExternalOrderMapping::create([
            'account_id' => $this->account->id, 'sales_channel' => 'amazon', 'seller_id' => 0, 'external_order_id' => self::ORDER, 'external_package_id' => '', 'status' => 'delivered',
            'fulfillment_mode' => 'fbm', 'currency' => 'SAR', 'prices_include_tax' => false, 'items_total' => '200', 'shipping_total' => '10', 'tax_total' => '30', 'order_total' => '240',
            'purchased_at' => '2026-11-05 09:00:00',
        ]);
        ExternalOrderLine::create([
            'mapping_id' => $mapping->id, 'external_line_id' => 'ITEM-1', 'sku' => 'SKU-1', 'product_id' => 50, 'quantity' => 2, 'quantity_cancelled' => 0, 'line_total' => '200',
            'discount_total' => '0', 'tax_total' => '30', 'shipping_total' => '10', 'currency' => 'SAR', 'status' => 'open',
        ]);
    }

    /** @param list<array<string, mixed>> $transactions */
    private function statement(array $transactions): array
    {
        $mapper = app(AmazonFinanceMapper::class);

        return app(ChannelFeeImporter::class)->import($this->account, array_merge(...array_map(fn (array $transaction): array => $mapper->map($transaction, $this->account), $transactions)));
    }

    private function net(string $component): string
    {
        return Money::sum(OperationalCostEntry::where('is_effective', true)->where('state', CostState::Actual->value)->where('cost_component', $component)->pluck('amount'))->toStorage();
    }

    /** @return array<string, mixed> */
    private function refund(string $posted, string $status, string $commission = '20.00'): array
    {
        return Amazon::refund('TX-REFUND-1', 'REFUND-1', self::ORDER, [Amazon::refundItem('SKU-1', 'ADJ-1', ['principal' => '-200.00', 'tax' => '-30.00', 'commission' => $commission])], $posted, $status);
    }

    public function test_a_refund_released_after_it_was_deferred_gives_its_commission_back_once(): void
    {
        $this->statement([Amazon::transaction('TX-SHIP-1')]);
        $this->assertSame('20.000000', $this->net('marketplace_commission'));

        $this->statement([$this->refund('2026-11-08T10:00:00Z', 'DEFERRED')]);
        $this->assertSame('0.000000', $this->net('marketplace_commission'), '20 charged, 20 given back.');
        $this->statement([$this->refund('2026-11-12T10:00:00Z', 'DEFERRED_RELEASED')]);

        $this->assertSame('0.000000', $this->net('marketplace_commission'), 'The same refund released with another posted date: 20 given back once, not twice (it was -20).');
    }

    public function test_the_same_refund_read_again_in_a_wider_window_adds_nothing(): void
    {
        $this->statement([Amazon::transaction('TX-SHIP-1'), $this->refund('2026-11-08T10:00:00Z', 'RELEASED')]);
        $events = SalesChannelEvent::count();
        $entries = OperationalCostEntry::count();

        $second = $this->statement([Amazon::transaction('TX-SHIP-1'), $this->refund('2026-11-08T10:00:00Z', 'RELEASED')]);

        $this->assertSame(0, $second['new']);
        $this->assertSame([$events, $entries], [SalesChannelEvent::count(), OperationalCostEntry::count()]);
        $this->assertSame('0.000000', $this->net('marketplace_commission'));
    }

    public function test_a_sale_that_moves_from_deferred_to_released_keeps_one_commission_and_one_fulfilment_fee(): void
    {
        $this->statement([array_replace(Amazon::transaction('TX-SHIP-1', posted: '2026-11-06T10:00:00Z'), ['transactionStatus' => 'DEFERRED'])]);
        $this->statement([array_replace(Amazon::transaction('TX-SHIP-1', posted: '2026-11-13T10:00:00Z'), ['transactionStatus' => 'DEFERRED_RELEASED'])]);

        $this->assertSame(['20.000000', '10.000000'], [$this->net('marketplace_commission'), $this->net('fba_fulfillment')]);
    }

    public function test_a_transaction_whose_amount_changed_between_the_two_readings_is_two_facts_because_nothing_can_tell_a_correction_from_a_new_charge(): void
    {
        $this->statement([$this->refund('2026-11-08T10:00:00Z', 'DEFERRED', commission: '20.00')]);
        $this->statement([$this->refund('2026-11-12T10:00:00Z', 'DEFERRED_RELEASED', commission: '18.00')]);

        $this->assertSame('-38.000000', $this->net('marketplace_commission'), 'Documented: the identity is the transaction, the item, the place in the tree and the amount.');
    }
}
