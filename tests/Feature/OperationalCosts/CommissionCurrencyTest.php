<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Contributors\MarketplaceFeeContributor;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Profit\ProfitResult;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * One commission, counted once, even when the channel settles it in another currency.
 *
 * The owner's rule, approved 2026-11-10 (docs/sales-channels/decisions.md §8, decision two): the final CONFIRMED actual
 * commission replaces the estimate for the same service whatever currency it arrives in; the estimate and the actual are never
 * both counted; a PARTIAL fee is not a settlement and must not retire the estimate; and with no trustworthy rate nothing is
 * converted and no profit is shown as final.
 *
 * `ChannelFeeImporter` files a net whose currency differs from the order's under a service reference of its own
 * (`channel-net:<CUR>` instead of `''`), which is deliberate -- two currencies in one slot would add up to nonsense. But
 * supersession in `CostLedger` is scoped to the slot, and `ProfitabilityService` adds EVERY effective, convertible entry to
 * the estimated total. So the slot that keeps the currencies apart is also what lets the estimate survive its own actual.
 *
 * The amounts here are invented.
 */
class CommissionCurrencyTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn (): FakeCurrencyRates => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'operational_costs.enabled' => true]);
        $this->account = $this->trendyolAccount();
    }

    // ---- the fixtures ------------------------------------------------------------------------------------------------

    private function order(): ExternalOrderMapping
    {
        $mapping = ExternalOrderMapping::create([
            'account_id' => $this->account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => '900',
            'status' => 'delivered', 'currency' => 'SAR', 'fulfillment_mode' => 'merchant_managed', 'prices_include_tax' => true,
            'purchased_at' => '2026-11-05 09:00:00', 'order_total' => '170.000000', 'items_total' => '200.000000',
        ]);
        ExternalOrderLine::create([
            'mapping_id' => $mapping->id, 'external_line_id' => '1', 'sku' => 'SKU-1', 'quantity' => 2, 'quantity_cancelled' => 0,
            'line_total' => '200.000000', 'discount_total' => '20.000000', 'tax_total' => '23.478261', 'currency' => 'SAR', 'status' => 'open',
        ]);

        return $mapping;
    }

    /** A commission contract, so the estimate is a figure rather than a `missing` marker. */
    private function contract(string $percent = '10'): void
    {
        $contracts = app(ContractService::class);
        $draft = $contracts->createDraft([
            'party_type' => 'marketplace', 'provider_code' => 'trendyol', 'party_name' => 'Trendyol', 'currency' => 'SAR',
            'effective_from' => '2026-01-01', 'reference' => 'T-1', 'settings' => ['expected_components' => ['marketplace_commission']],
        ], adminId: 1);
        $contracts->addRule($draft, ['service_type' => 'marketplace_commission', 'calc_method' => 'percent', 'params' => ['percent' => $percent]], adminId: 1);
        $contracts->activate($draft, 1, 'test contract with invented numbers');
    }

    private function estimate(ExternalOrderMapping $mapping): void
    {
        app(MarketplaceFeeContributor::class)->contribute(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));
    }

    /** A real commission the channel reported, in whatever currency it chose to report it in. */
    private function actual(string $amount, string $currency, string $identity = 'settle-1'): void
    {
        app(ChannelFeeImporter::class)->import($this->account, [
            new RemoteFinancialEntry(
                $identity, RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, Money::of($amount), $currency,
                '111', '900', null, null, CarbonImmutable::parse('2026-11-20 10:00:00 UTC'), 'Commission',
            ),
        ]);
    }

    /** @return list<array{state: string, ref: string, amount: ?string, currency: string}> every commission row still in effect */
    private function effectiveCommissions(): array
    {
        return OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('is_effective', true)->orderBy('id')
            ->get()->map(static fn (OperationalCostEntry $entry): array => [
                'state' => (string) $entry->state?->value,
                'ref' => (string) $entry->service_ref,
                'amount' => $entry->amount,
                'currency' => (string) $entry->currency,
            ])->all();
    }

    private function profit(ExternalOrderMapping $mapping): ProfitResult
    {
        return app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));
    }

    // ---- the control: same currency, which has always worked ---------------------------------------------------------

    public function test_an_actual_commission_in_the_order_currency_replaces_the_estimate(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('18.00', 'SAR');

        $effective = $this->effectiveCommissions();

        $this->assertCount(1, $effective, 'the estimate and its actual are one commission, not two: ' . json_encode($effective));
        $this->assertNotSame(CostState::Estimated->value, $effective[0]['state'], 'the actual is what stands once it has arrived');
    }

    // ---- the case the owner's rule is about --------------------------------------------------------------------------

    // ---- and the limits the owner set on the same rule ---------------------------------------------------------------

    public function test_two_fees_of_the_same_service_accumulate_into_one_actual(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('10.00', 'SAR', 'fee-a');
        $this->actual('5.00', 'SAR', 'fee-b');

        $effective = $this->effectiveCommissions();

        $this->assertCount(1, $effective, 'two fees are one commission: ' . json_encode($effective));
        $this->assertSame('15.000000', $effective[0]['amount'], 'and the actual is their sum, so no single fee is mistaken for the whole');
    }

    public function test_with_no_trustworthy_rate_nothing_is_converted_and_the_profit_is_not_final(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('30.00', 'AED');

        $result = $this->profit($mapping);

        $this->assertContains('marketplace_commission', $result->missingComponents, 'an unconvertible cost is missing, not zero');
        $this->assertContains('cost_not_convertible:marketplace_commission', $result->notes, 'and the reason is said, not guessed');
    }

    /**
     * A credit is part of the same net, not a separate cost: the channel refunding part of its commission reduces what we
     * were charged. So a credit arriving in the foreign slot nets off against the fee there, and the estimate stays retired
     * rather than coming back to be counted a second time.
     */
    public function test_a_credit_nets_off_against_the_fee_and_does_not_revive_the_estimate(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('200.00', 'TRY', 'fee');
        $this->actual('-50.00', 'TRY', 'credit');

        $try = array_values(array_filter($this->effectiveCommissions(), static fn (array $row): bool => $row['currency'] === 'TRY'));

        $this->assertCount(1, $try, 'the fee and its credit are one net in their own currency: ' . json_encode($try));
        $this->assertSame('150.000000', $try[0]['amount'], 'and the net is what is charged');
        $this->assertSame('14.062500', $this->profit($mapping)->actualCost->toStorage(), '150 TRY converted, counted once');
    }
    // ---- every ordering, because the choice is made at read time and so cannot depend on one -------------------------


    public function test_an_actual_with_no_trustworthy_rate_does_not_displace_a_convertible_estimate(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('30.00', 'AED', 'no-rate');

        $result = $this->profit($mapping);

        $this->assertSame('15.652174', $result->estimatedCost->toStorage(), 'with no rate the estimate stays the best figure we hold');
        $this->assertContains('cost_not_convertible:marketplace_commission', $result->notes, 'and the profit is not presented as final');
    }

    public function test_a_disputed_figure_never_displaces_the_estimate(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);

        OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('state', CostState::Estimated->value)->firstOrFail();
        $this->actual('200.00', 'TRY');
        OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('currency', 'TRY')
            ->update(['state' => CostState::Disputed->value]);

        $result = $this->profit($mapping);

        $this->assertSame('15.652174', $result->estimatedCost->toStorage(), 'a contested figure is not a settlement');
    }

    public function test_the_ledger_keeps_both_rows_so_the_history_stays_auditable(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('200.00', 'TRY');

        $this->assertCount(2, $this->effectiveCommissions(), 'nothing is destroyed: which one counts is decided when the profit is read');
    }
    // ---- estimated, observed, and proven: three different things -----------------------------------------------------

    private function proveTheSettlement(): void
    {
        $actual = OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('state', CostState::Actual->value)->firstOrFail();
        app(CostLedger::class)->markReconciled($actual, adminId: 1);
    }

    /**
     * **The number the owner asked to see proved.** A 1 TRY fee against a 15.652174 SAR estimate used to leave
     * estimatedCost at 0.093750 with nothing pending -- the order read as fully settled at 0.6% of its commission.
     */
    public function test_a_partial_fee_leaves_the_estimate_as_the_expectation_and_reports_the_fee_separately(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('1.00', 'TRY', 'tiny');

        $result = $this->profit($mapping);
        $commission = $result->buckets['marketplace_commission'];

        $this->assertSame('15.652174', $result->estimatedCost->toStorage(), 'the expectation is not cut down by one fee');
        $this->assertSame('0.093750', $result->actualCost->toStorage(), 'and what has actually arrived is reported on its own');
        $this->assertContains('marketplace_commission', $result->pendingActualComponents, 'the settlement is not proven, so it is not presented as final');
        $this->assertSame(0, $commission->supersededEstimates, 'and nothing is hidden');
    }

    public function test_a_fee_as_large_as_the_estimate_still_proves_nothing_on_its_own(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('200.00', 'TRY');

        $result = $this->profit($mapping);

        $this->assertSame('15.652174', $result->estimatedCost->toStorage(), 'size is not evidence: no amount comparison decides this');
        $this->assertSame('18.750000', $result->actualCost->toStorage());
        $this->assertContains('marketplace_commission', $result->pendingActualComponents);
    }

    public function test_a_proven_settlement_replaces_the_estimate_and_the_commission_is_charged_once(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('200.00', 'TRY');
        $this->proveTheSettlement();

        $result = $this->profit($mapping);

        $this->assertSame('18.750000', $result->actualCost->toStorage());
        $this->assertSame('18.750000', $result->estimatedCost->toStorage(), 'one commission, counted once, in another currency');
        $this->assertNotContains('marketplace_commission', $result->pendingActualComponents, 'and the settlement is proven, so it is final');
        $this->assertSame(1, $result->buckets['marketplace_commission']->supersededEstimates);
    }

    public function test_the_proof_carries_its_date(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('200.00', 'TRY');
        $this->proveTheSettlement();

        $settled = OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('state', CostState::Reconciled->value)->firstOrFail();

        $this->assertNotNull($settled->approved_at, 'a settlement without a date is not evidence of when it settled');
        $this->assertNotNull($settled->approved_by, 'nor without who signed it off');
    }

    public function test_the_estimate_priced_after_a_proven_settlement_is_still_not_counted_beside_it(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->actual('200.00', 'TRY');
        $this->proveTheSettlement();
        $this->estimate($mapping);

        $result = $this->profit($mapping);

        $this->assertSame('18.750000', $result->estimatedCost->toStorage(), 'the arrival order cannot defeat a decision made at read time');
    }

    public function test_several_fees_in_two_currencies_are_each_their_own_net_and_none_displaces_the_estimate(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('100.00', 'TRY', 'try-a');
        $this->actual('100.00', 'TRY', 'try-b');
        $this->actual('2.00', 'USD', 'usd-a');

        $result = $this->profit($mapping);

        // 200 TRY = 18.75 SAR and 2 USD = 7.50 SAR at the table's rates.
        $this->assertSame('26.250000', $result->actualCost->toStorage(), 'each currency nets on its own and both are observed');
        $this->assertSame('15.652174', $result->estimatedCost->toStorage(), 'and the expectation is still the estimate');
        $this->assertContains('marketplace_commission', $result->pendingActualComponents);
    }

    public function test_a_credit_on_its_own_does_not_touch_the_estimate(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('-5.00', 'TRY', 'credit-only');

        $result = $this->profit($mapping);

        $this->assertSame('15.652174', $result->estimatedCost->toStorage(), 'a credit is not a settlement');
        $this->assertSame('-0.468750', $result->actualCost->toStorage(), 'and it is reported as what it is');
    }

    public function test_a_proven_settlement_of_one_service_does_not_displace_the_estimate_of_another(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);

        // A reversal is a different service that happens to share the component. Settling it says nothing about the
        // commission the contract priced.
        app(CostLedger::class)->record(new \App\Services\OperationalCosts\Data\CostDraft(
            subjectType: 'channel_order', subjectId: (int) $mapping->id, component: CostComponent::MarketplaceCommission,
            state: CostState::Reconciled, amount: Money::of('3.00'), currency: 'SAR', periodDate: CarbonImmutable::parse('2026-11-20'),
            source: 'manual', identity: 'rev-1', serviceRef: 'reversal:rev-1', sellerId: 0, salesChannel: 'trendyol',
            accountId: (int) $this->account->id, externalOrderMappingId: (int) $mapping->id, occurredAt: CarbonImmutable::parse('2026-11-20'),
        ));

        $result = $this->profit($mapping);

        $this->assertSame(0, $result->buckets['marketplace_commission']->supersededEstimates, 'a different service reference is a different service');
    }
}
