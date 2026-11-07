<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Contributors\MarketplaceFeeContributor;
use App\Services\OperationalCosts\CostRefresher;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** The estimate before the statement arrives: from a contract when there is one, missing (never zero) when there is not, and never on top of the real charge. */
class MarketplaceFeeContributorTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR']);
    }

    private function order(string $channel = 'trendyol', string $mode = 'merchant_managed', string $status = 'delivered'): ExternalOrderMapping
    {
        $account = $channel === 'amazon' ? $this->amazonAccount() : $this->trendyolAccount();
        $mapping = ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => $channel, 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => $channel === 'amazon' ? '' : '900',
            'status' => $status, 'currency' => 'SAR', 'fulfillment_mode' => $mode, 'prices_include_tax' => true, 'purchased_at' => '2026-11-05 09:00:00', 'order_total' => '170.000000', 'items_total' => '200.000000',
        ]);
        ExternalOrderLine::create(['mapping_id' => $mapping->id, 'external_line_id' => '1', 'sku' => 'SKU-1', 'quantity' => 2, 'quantity_cancelled' => 0, 'line_total' => '200.000000', 'discount_total' => '20.000000', 'tax_total' => '23.478261', 'currency' => 'SAR', 'status' => 'open']);

        return $mapping;
    }

    private function contract(string $provider, string $percent = '10'): void
    {
        $contracts = app(ContractService::class);
        $draft = $contracts->createDraft(['party_type' => 'marketplace', 'provider_code' => $provider, 'party_name' => 'Marketplace', 'currency' => 'SAR', 'effective_from' => '2026-01-01', 'reference' => 'T-1', 'settings' => ['expected_components' => ['marketplace_commission']]], adminId: 1);
        $contracts->addRule($draft, ['service_type' => 'marketplace_commission', 'calc_method' => 'percent', 'params' => ['percent' => $percent]], adminId: 1);
        $contracts->activate($draft, 1, 'test contract with invented numbers');
    }

    private function contribute(ExternalOrderMapping $mapping): array
    {
        return app(MarketplaceFeeContributor::class)->contribute(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));
    }

    public function test_a_channel_order_with_no_commission_contract_has_a_missing_commission_not_a_free_one(): void
    {
        $mapping = $this->order();

        $this->contribute($mapping);

        $entry = OperationalCostEntry::where('cost_component', 'marketplace_commission')->firstOrFail();
        $this->assertSame([CostState::Missing, null, 'no_contract'], [$entry->state, $entry->amount, $entry->missing_reason]);
        $this->assertSame($mapping->id, (int) $entry->external_order_mapping_id);
    }

    public function test_with_a_contract_the_commission_is_an_estimate_on_the_net_revenue(): void
    {
        $this->contract('trendyol', '10');
        $mapping = $this->order();

        $this->contribute($mapping);

        $entry = OperationalCostEntry::where('cost_component', 'marketplace_commission')->firstOrFail();
        $this->assertSame(CostState::Estimated, $entry->state);
        $this->assertSame('15.652174', $entry->amount, '10 % of the revenue after discount and the tax contained in it: 200 - 20 - 23.478261 = 156.521739.');
    }

    public function test_an_fba_order_has_a_missing_fba_fee_until_the_statement_says_what_it_was(): void
    {
        $this->contract('amazon', '8');
        $mapping = $this->order('amazon', 'fba', 'shipped');

        $this->contribute($mapping);

        $fba = OperationalCostEntry::where('cost_component', 'fba_fulfillment')->firstOrFail();
        $this->assertSame([CostState::Missing, 'awaiting_channel_statement'], [$fba->state, $fba->missing_reason]);
    }

    public function test_a_merchant_fulfilled_order_has_no_fba_fee_at_all(): void
    {
        $mapping = $this->order('amazon', 'fbm', 'shipped');

        $this->contribute($mapping);

        $this->assertSame(0, OperationalCostEntry::where('cost_component', 'fba_fulfillment')->count());
    }

    public function test_the_statements_actual_replaces_the_estimate_and_running_the_estimate_again_does_not_bring_it_back(): void
    {
        $this->contract('trendyol', '10');
        $mapping = $this->order();
        $this->contribute($mapping);

        app(ChannelFeeImporter::class)->import($mapping->account, [new RemoteFinancialEntry('a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, Money::of('25'), 'SAR', '111', '900', null, null, CarbonImmutable::parse('2026-11-06'), 'Sale:commission')]);
        $this->contribute($mapping);

        $effective = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->get();
        $this->assertCount(1, $effective);
        $this->assertSame([CostState::Actual, '25.000000'], [$effective[0]->state, $effective[0]->amount]);
    }

    public function test_a_cancelled_order_and_a_storefront_order_get_nothing(): void
    {
        $cancelled = $this->order(status: 'cancelled');

        $this->assertSame([], $this->contribute($cancelled));
        $this->assertSame(0, OperationalCostEntry::count());
    }

    public function test_the_refresher_runs_it_with_the_other_contributors(): void
    {
        $this->order();

        $result = app(CostRefresher::class)->refreshPeriod(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

        $this->assertSame(1, $result['documents']);
        $this->assertGreaterThan(0, OperationalCostEntry::where('cost_component', 'marketplace_commission')->count());
    }
}
