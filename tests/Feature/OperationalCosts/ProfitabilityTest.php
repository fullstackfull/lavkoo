<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\AllocationService;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\AllocationTarget;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Profit\ProductProfitability;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleLine;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** Prices, fees and costs here are arbitrary figures chosen so the arithmetic can be checked by hand. */
class ProfitabilityTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private CostLedger $ledger;

    private ProfitabilityService $profit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->ledger = app(CostLedger::class);
        $this->profit = app(ProfitabilityService::class);
    }

    /** @param list<SaleLine> $lines */
    private function document(array $lines, string $channel = 'storefront', string $state = SaleDocument::STATE_DELIVERED, string $shipping = '0', string $discounts = '0', int $id = 1, string $currency = 'SAR'): SaleDocument
    {
        $storefront = $channel === 'storefront';

        return new SaleDocument(
            subjectType: $storefront ? 'order' : 'channel_order', subjectId: $id, salesChannel: $channel, accountId: $storefront ? null : 1, sellerId: 0,
            currency: $currency, occurredAt: CarbonImmutable::parse('2026-11-10'), state: $state, fulfillmentMode: null, provider: null,
            orderId: $storefront ? $id : null, mappingId: $storefront ? null : $id, shippingCharged: Money::of($shipping), orderDiscounts: Money::of($discounts), lines: $lines,
        );
    }

    private function line(int $id, string $revenue, int $quantity = 1, string $refunded = '0', ?int $productId = 50): SaleLine
    {
        return new SaleLine('order_line', $id, $productId, '', null, $quantity, Money::of($revenue), Money::of($refunded), 1, SaleLine::BASIS_OWNED_STOCK);
    }

    private function cost(SaleDocument $document, CostComponent $component, CostState $state, ?string $amount, string $identity, ?int $productId = null, string $serviceRef = '', string $currency = 'SAR'): void
    {
        $this->ledger->record(new CostDraft(
            subjectType: 'shipment', subjectId: $document->subjectId, component: $component, state: $state, amount: $amount === null ? null : Money::of($amount),
            currency: $currency, periodDate: $document->occurredAt, source: $state === CostState::Estimated ? 'rule' : 'invoice', identity: $identity, serviceRef: $serviceRef,
            salesChannel: $document->salesChannel, accountId: $document->accountId, orderId: $document->orderId, externalOrderMappingId: $document->mappingId,
            productId: $productId, missingReason: $state === CostState::Missing ? 'no_contract' : null,
        ));
    }

    public function test_revenue_is_sales_plus_shipping_less_discounts_and_refunds(): void
    {
        $document = $this->document([$this->line(1, '100'), $this->line(2, '50', refunded: '20')], shipping: '15', discounts: '10');

        $this->assertSame('135.000000', $document->netRevenue()->toStorage(), '150 sales + 15 shipping - 10 discount - 20 refunded.');
    }

    public function test_estimated_and_actual_profit_are_two_separate_figures(): void
    {
        $document = $this->document([$this->line(1, '100')]);
        $this->cost($document, CostComponent::Cogs, CostState::Actual, '40', 'cogs', serviceRef: 'cogs');
        $this->cost($document, CostComponent::Shipping, CostState::Estimated, '12', 'ship-est');

        $result = $this->profit->forDocument($document);

        $this->assertSame('48.000000', $result->estimatedProfit()->toStorage(), '100 - 40 - 12.');
        $this->assertSame('60.000000', $result->actualProfit()->toStorage(), 'The shipping cost is not settled yet, so actual profit leaves it out.');
        $this->assertTrue($result->estimatedComplete());
        $this->assertFalse($result->actualComplete());
        $this->assertSame(['shipping'], $result->pendingActualComponents);
    }

    public function test_the_real_charge_replaces_the_estimate_and_the_service_is_not_counted_twice(): void
    {
        $document = $this->document([$this->line(1, '100')]);
        $this->cost($document, CostComponent::Shipping, CostState::Estimated, '12', 'ship-est');
        $this->cost($document, CostComponent::Shipping, CostState::Actual, '13.5', 'invoice-9');

        $result = $this->profit->forDocument($document);

        $this->assertSame('13.500000', $result->estimatedCost->toStorage());
        $this->assertSame('13.500000', $result->actualCost->toStorage());
        $this->assertSame('86.500000', $result->actualProfit()->toStorage());
        $this->assertTrue($result->actualComplete());
    }

    public function test_a_missing_cost_is_a_gap_in_the_profit_not_a_free_service(): void
    {
        $document = $this->document([$this->line(1, '100')]);
        $this->cost($document, CostComponent::Shipping, CostState::Missing, null, 'none');

        $result = $this->profit->forDocument($document);

        $this->assertSame('100.000000', $result->estimatedProfit()->toStorage());
        $this->assertFalse($result->estimatedComplete(), 'A profit with a hole in it is provisional.');
        $this->assertSame(['shipping'], $result->missingComponents);
    }

    public function test_a_disputed_cost_is_in_neither_figure_and_shown_apart(): void
    {
        $document = $this->document([$this->line(1, '100')]);
        $this->cost($document, CostComponent::Shipping, CostState::Actual, '12', 'invoice-9');
        $entry = OperationalCostEntry::first();
        $this->ledger->dispute($entry, 'overcharged');

        $result = $this->profit->forDocument($document);

        $this->assertSame('0.000000', $result->estimatedCost->toStorage());
        $this->assertSame('12.000000', $result->disputedCost->toStorage());
        $this->assertFalse($result->actualComplete());
    }

    public function test_a_cancelled_order_has_no_revenue_and_no_cost_of_goods(): void
    {
        $document = $this->document([$this->line(1, '100')], state: SaleDocument::STATE_CANCELLED);
        $this->cost($document, CostComponent::Cogs, CostState::Actual, '40', 'cogs', serviceRef: 'cogs');
        $this->cost($document, CostComponent::CancellationFee, CostState::Actual, '4', 'fee');

        $result = $this->profit->forDocument($document);

        $this->assertSame('0.000000', $result->revenue->toStorage());
        $this->assertSame('4.000000', $result->actualCost->toStorage(), 'Only the cancellation fee was really incurred.');
    }

    public function test_full_and_partial_refunds_reduce_revenue(): void
    {
        $partial = $this->document([$this->line(1, '100', refunded: '30')], id: 1);
        $full = $this->document([$this->line(2, '100', refunded: '100')], id: 2);

        $this->assertSame('70.000000', $this->profit->forDocument($partial)->revenue->toStorage());
        $this->assertSame('0.000000', $this->profit->forDocument($full)->revenue->toStorage());
    }

    public function test_marketplace_fees_count_once_and_a_payout_is_not_revenue(): void
    {
        $document = $this->document([new SaleLine('channel_order_line', 7, 50, '', null, 1, Money::of('100'), Money::zero(), 1, SaleLine::BASIS_OWNED_STOCK)], channel: 'amazon', state: SaleDocument::STATE_SHIPPED);
        $this->cost($document, CostComponent::MarketplaceCommission, CostState::Actual, '15', 'event-1', serviceRef: 'line:7');
        $this->cost($document, CostComponent::MarketplaceCommission, CostState::Actual, '15', 'event-1', serviceRef: 'line:7');
        $this->cost($document, CostComponent::FbaFulfillment, CostState::Actual, '8', 'event-2');

        $result = $this->profit->forDocument($document);

        $this->assertSame('23.000000', $result->actualCost->toStorage());
        $this->assertSame('77.000000', $result->actualProfit()->toStorage(), 'What Amazon later pays out is not added: the sale is already counted.');
    }

    public function test_amounts_in_another_currency_are_converted_and_an_unknown_currency_is_flagged_not_guessed(): void
    {
        $lira = $this->document([$this->line(1, '400')], channel: 'trendyol', currency: 'TRY', id: 3);
        $unknown = $this->document([$this->line(2, '400')], channel: 'trendyol', currency: 'EUR', id: 4);

        $converted = $this->profit->forDocument($lira);
        $flagged = $this->profit->forDocument($unknown);

        $this->assertSame('37.500000', $converted->revenue->toStorage(), '400 TRY x 3.75 / 40.');
        $this->assertFalse($flagged->estimatedComplete());
        $this->assertContains('revenue_not_convertible', $flagged->notes);
    }

    public function test_totals_are_grouped_by_channel_and_stay_provisional_when_any_sale_has_a_gap(): void
    {
        $storefront = $this->document([$this->line(1, '100')], id: 1);
        $amazon = $this->document([$this->line(2, '200')], channel: 'amazon', id: 2);
        $this->cost($storefront, CostComponent::Shipping, CostState::Actual, '10', 'a');
        $this->cost($amazon, CostComponent::MarketplaceCommission, CostState::Missing, null, 'b');

        $rows = (new ProfitAggregator())->group($this->profit->forDocuments([$storefront, $amazon]), ProfitAggregator::BY_CHANNEL);

        $this->assertSame(['amazon', 'storefront'], array_keys($rows));
        $this->assertSame('90.000000', $rows['storefront']->actualProfit()->toStorage());
        $this->assertSame(0, $rows['storefront']->incompleteActual);
        $this->assertSame(1, $rows['amazon']->incompleteActual);
        $this->assertSame(1, $rows['amazon']->missingCosts);
    }

    public function test_product_profit_takes_each_products_own_cost_and_its_allocated_share_of_a_shared_fee(): void
    {
        $document = $this->document([$this->line(1, '60', productId: 50), $this->line(2, '40', productId: 51)], shipping: '10');
        $this->cost($document, CostComponent::Cogs, CostState::Actual, '20', 'cogs-1', productId: 50, serviceRef: 'cogs:1');
        $this->cost($document, CostComponent::Cogs, CostState::Actual, '10', 'cogs-2', productId: 51, serviceRef: 'cogs:2');
        $this->cost($document, CostComponent::Shipping, CostState::Actual, '8', 'ship');
        $shipping = OperationalCostEntry::where('cost_component', 'shipping')->first();
        app(AllocationService::class)->allocate($shipping, [
            new AllocationTarget('order_line', 1, 50, '', Money::of(1), null, Money::of(60)),
            new AllocationTarget('order_line', 2, 51, '', Money::of(1), null, Money::of(40)),
        ], 'value');

        $rows = app(ProductProfitability::class)->rows([$document]);

        $this->assertSame('66.000000', $rows['50']->revenue->toStorage(), '60 + 6 of the 10 shipping charged, by value.');
        $this->assertSame('24.800000', $rows['50']->actualCost->toStorage(), '20 of goods + 4.80 of the 8.00 fee.');
        $this->assertSame('44.000000', $rows['51']->revenue->toStorage());
        $this->assertSame('13.200000', $rows['51']->actualCost->toStorage());
    }

    public function test_a_charge_that_could_not_be_split_stays_visible_as_unallocated(): void
    {
        $document = $this->document([$this->line(1, '100')]);
        $this->cost($document, CostComponent::Shipping, CostState::Actual, '8', 'ship');

        $rows = app(ProductProfitability::class)->rows([$document]);

        $this->assertSame('8.000000', $rows[ProductProfitability::UNALLOCATED]->actualCost->toStorage());
    }
}
