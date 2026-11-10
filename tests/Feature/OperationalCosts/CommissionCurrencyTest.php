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

    public function test_an_actual_commission_in_another_currency_also_replaces_the_estimate_and_is_not_counted_twice(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);

        // The same service, settled by Trendyol in lira. A different currency is a different slot in the ledger, and that is
        // exactly what must not turn one commission into two.
        $this->actual('200.00', 'TRY');

        $result = $this->profit($mapping);

        $this->assertSame('18.750000', $result->actualCost->toStorage(), '200 TRY at the table\'s rates');
        $this->assertSame(
            '18.750000',
            $result->estimatedCost->toStorage(),
            'the settled actual is the whole of what this commission costs; anything more is the estimate counted again',
        );
    }

    public function test_the_profit_of_that_order_charges_the_commission_once(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('200.00', 'TRY');

        $result = $this->profit($mapping);

        // 200 TRY at the table's rates (TRY 40 and SAR 3.75 to the USD) is 18.75 SAR. The estimate was 10% of the order. If
        // both are counted the estimated total is the sum of the two, which is the defect.
        $this->assertSame(
            $result->actualCost->toStorage(),
            $result->estimatedCost->toStorage(),
            'with the actual settled there is nothing left estimated beside it; a larger estimated total is the estimate counted again',
        );
    }

    // ---- and the limits the owner set on the same rule ---------------------------------------------------------------

    /**
     * The owner's third condition: a partial fee is not a final settlement, and the estimate is not CANCELLED by fees that
     * are not confirmed to cover the service.
     *
     * Supersession satisfies it without keeping the estimate in the total, and the distinction matters: the estimate keeps
     * its row, stops being counted (so it is never added to the actual, which is the second condition), and points at the
     * actual that replaced it. So nothing is destroyed and the expectation can still be compared with what arrived.
     *
     * The channel's fees also ACCUMULATE rather than replace each other -- two fees of 10 and 5 are one net of 15 -- so a
     * small fee is the running actual, not a partial mistaken for the whole.
     */
    public function test_a_small_actual_keeps_the_estimate_on_the_record_rather_than_deleting_it(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('1.00', 'TRY', 'part-1');

        $estimate = OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('state', CostState::Estimated->value)->firstOrFail();
        $result = $this->profit($mapping);

        $this->assertTrue((bool) $estimate->is_effective, 'the estimate is NOT retired in the ledger: a partial actual must not cancel it');
        $this->assertSame(1, $result->buckets['marketplace_commission']->supersededEstimates, 'it is simply not counted beside the actual');
    }

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

    public function test_the_settlement_arriving_before_the_estimate_is_priced_is_still_counted_once(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->actual('200.00', 'TRY');
        $this->estimate($mapping);

        $result = $this->profit($mapping);

        $this->assertSame('18.750000', $result->actualCost->toStorage());
        $this->assertSame('18.750000', $result->estimatedCost->toStorage(), 'retiring at write time only looked backwards and this ordering defeated it');
    }

    public function test_a_tiny_actual_does_not_make_the_order_read_as_fully_settled_at_that_figure(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->actual('1.00', 'TRY', 'tiny');

        $result = $this->profit($mapping);
        $commission = $result->buckets['marketplace_commission'];

        $this->assertSame('0.093750', $result->actualCost->toStorage(), 'what the channel has reported so far is what it is');
        $this->assertSame(1, $commission->supersededEstimates, 'and the estimate it replaced is still on the record, not deleted');
    }

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
}
