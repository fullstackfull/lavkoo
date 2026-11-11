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
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * The upgrade case: a database that already holds a channel fee net filed the way it was filed before 2026-11-11.
 *
 * Until then `ChannelFeeImporter` put a net whose currency matched the order's into the contract estimate's own slot (the
 * empty service reference). Every net now goes to `channel-net:<CUR>` so the estimate survives until a settlement is
 * proven -- but that change migrates nothing, so a row written the old way stays exactly where it was.
 *
 * **These tests do not pretend the hazard is handled.** The owner's instruction is that no migration be built for a
 * theoretical risk, and that the PRE-CHECK must find the case and stop the rollout instead. So what is proved here is: the
 * hazard is real and quantified, `CostLedger`'s general rule has not been bent to hide it, and
 * `costs:check-legacy-commission-slots` finds the row and fails.
 *
 * The figures are invented.
 */
class LegacyCommissionSlotTest extends TestCase
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

    private function contract(): void
    {
        $contracts = app(ContractService::class);
        $draft = $contracts->createDraft([
            'party_type' => 'marketplace', 'provider_code' => 'trendyol', 'party_name' => 'Trendyol', 'currency' => 'SAR',
            'effective_from' => '2026-01-01', 'reference' => 'T-1', 'settings' => ['expected_components' => ['marketplace_commission']],
        ], adminId: 1);
        $contracts->addRule($draft, ['service_type' => 'marketplace_commission', 'calc_method' => 'percent', 'params' => ['percent' => '10']], adminId: 1);
        $contracts->activate($draft, 1, 'test contract with invented numbers');
    }

    private function estimate(ExternalOrderMapping $mapping): void
    {
        app(MarketplaceFeeContributor::class)->contribute(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));
    }

    /** A net exactly as a version before 2026-11-11 wrote it: the order's own currency, in the estimate's slot. */
    private function legacyNet(ExternalOrderMapping $mapping, string $amount): OperationalCostEntry
    {
        return app(CostLedger::class)->record(new CostDraft(
            subjectType: 'channel_order',
            subjectId: (int) $mapping->id,
            component: CostComponent::MarketplaceCommission,
            state: CostState::Actual,
            amount: Money::of($amount),
            currency: 'SAR',
            periodDate: CarbonImmutable::parse('2026-11-06'),
            source: 'channel_api',
            identity: 'legacy-net',
            serviceRef: '',
            sellerId: 0,
            salesChannel: 'trendyol',
            accountId: (int) $this->account->id,
            externalOrderMappingId: (int) $mapping->id,
            occurredAt: CarbonImmutable::parse('2026-11-06'),
        ))->entry;
    }

    private function newFee(string $amount): void
    {
        app(ChannelFeeImporter::class)->import($this->account, [
            new RemoteFinancialEntry(
                'new-net', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, Money::of($amount), 'SAR',
                '111', '900', null, null, CarbonImmutable::parse('2026-11-20 10:00:00 UTC'), 'Commission',
            ),
        ]);
    }

    private function check(): array
    {
        Artisan::call('costs:check-legacy-commission-slots', ['--json' => true]);

        return [Artisan::output(), (int) Artisan::call('costs:check-legacy-commission-slots')];
    }

    // ---- the hazard, quantified -------------------------------------------------------------------------------------

    public function test_a_legacy_net_and_a_new_fee_for_the_same_order_are_two_effective_rows(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->legacyNet($mapping, '12.00');
        $this->newFee('12.00');

        $effective = OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('is_effective', true)
            ->whereIn('state', [CostState::Actual->value, CostState::Reconciled->value])->get();

        $this->assertCount(2, $effective, 'the legacy row is in another slot, so nothing supersedes it');
        $this->assertSame(
            '24.000000',
            app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($mapping->id))->actualCost->toStorage(),
            'and one 12.00 commission is counted as 24.00 -- which is why the pre-check has to stop the rollout',
        );
    }

    public function test_the_general_ledger_rule_was_not_bent_to_hide_this(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $legacy = $this->legacyNet($mapping, '12.00');
        $this->newFee('12.00');

        // Supersession is still per slot, exactly as before: the legacy row keeps its own and is not quietly retired by a
        // write in another. Making `CostLedger` reach across slots to tidy this away is what the owner forbade.
        $this->assertTrue((bool) $legacy->fresh()->is_effective, 'the legacy row is untouched, not swept under the rug');
        $this->assertNull($legacy->fresh()->superseded_by_id);
    }

    // ---- and the pre-check is what stops it -------------------------------------------------------------------------

    public function test_the_pre_check_finds_the_legacy_row_and_fails(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->legacyNet($mapping, '12.00');

        [$json, $exit] = $this->check();
        $report = json_decode($json, true);

        $this->assertSame(1, $report['legacy_rows']);
        $this->assertSame('stop_and_plan_a_data_repair', $report['verdict']);
        $this->assertSame('channel-net:SAR', $report['examples'][0]['would_move_to']);
        $this->assertNotSame(0, $exit, 'a non-zero exit is how a rollout script is stopped rather than merely informed');
    }

    public function test_the_pre_check_passes_on_a_database_written_only_the_new_way(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $this->newFee('12.00');

        [$json, $exit] = $this->check();
        $report = json_decode($json, true);

        $this->assertSame(0, $report['legacy_rows']);
        $this->assertSame('no_risk_in_this_data', $report['verdict']);
        $this->assertSame(0, $exit);
    }

    public function test_the_pre_check_reads_the_slot_from_the_ledgers_own_draft_so_it_cannot_drift(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);
        $legacy = $this->legacyNet($mapping, '12.00');

        $expected = (new CostDraft(
            subjectType: 'channel_order', subjectId: (int) $mapping->id, component: CostComponent::MarketplaceCommission,
            state: CostState::Actual, amount: Money::zero(), currency: 'SAR', periodDate: CarbonImmutable::now(),
            source: 'check', identity: 'check', serviceRef: '', sellerId: 0,
        ))->slotKey();

        // The same recipe `slotKey()` uses, which is the point: a hash rebuilt in SQL with CONCAT_WS would omit a NULL
        // field where `implode` leaves an empty segment, and the check would report zero where the hazard is real.
        $this->assertSame($expected, (string) $legacy->slot_key);
    }

    public function test_a_legacy_net_in_another_currency_was_never_the_problem(): void
    {
        $this->contract();
        $mapping = $this->order();
        $this->estimate($mapping);

        // A foreign-currency net already had its own slot before this change, so it is not legacy and the check leaves it.
        app(CostLedger::class)->record(new CostDraft(
            subjectType: 'channel_order', subjectId: (int) $mapping->id, component: CostComponent::MarketplaceCommission,
            state: CostState::Actual, amount: Money::of('200.00'), currency: 'TRY', periodDate: CarbonImmutable::parse('2026-11-06'),
            source: 'channel_api', identity: 'try-net', serviceRef: 'channel-net:TRY', sellerId: 0, salesChannel: 'trendyol',
            accountId: (int) $this->account->id, externalOrderMappingId: (int) $mapping->id, occurredAt: CarbonImmutable::parse('2026-11-06'),
        ));

        [$json, $exit] = $this->check();

        $this->assertSame(0, json_decode($json, true)['legacy_rows']);
        $this->assertSame(0, $exit);
    }

    public function test_a_database_it_cannot_read_is_its_own_answer_and_also_stops_the_rollout(): void
    {
        // Point the default connection at something that is not there. Measured before this was handled: the command died
        // with a stack trace, so a rollout script learned nothing about whether the hazard was present.
        $was = config('database.default');
        config(['database.default' => 'nowhere', 'database.connections.nowhere' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'nothing_here',
            'username' => 'none', 'password' => '', 'charset' => 'utf8mb4',
        ]]);

        try {
            [$json, $exit] = $this->check();
        } finally {
            // The harness reads the database when the test ends, so the connection goes back before anything else runs.
            config(['database.default' => $was]);
            DB::purge('nowhere');
        }

        $report = json_decode($json, true);

        $this->assertSame('could_not_look', $report['verdict']);
        $this->assertNull($report['legacy_rows'], 'an unreachable database is not an empty one');
        $this->assertNotSame(0, $exit);
    }
}
