<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Services\SalesChannels\Finance\ChargeIdentity;
use App\Services\SalesChannels\Finance\ManualStatementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * One charge is one cost however many sources report it: the channel's API, and a statement a person exported from the seller portal. A statement row that repeats a charge the API
 * already gave is recorded as a duplicate and not counted; one that may overlap is counted and marked for a person; and the match works whichever of the two arrived first.
 */
class StatementOverlapTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const HEADER = "order_id,package_id,posted_at,category,component,amount,currency,description\n";

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR']);
        $this->account = $this->trendyolAccount();
    }

    private function order(string $orderId = '111', string $packageId = '900'): ExternalOrderMapping
    {
        return ExternalOrderMapping::create(['account_id' => $this->account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => $orderId, 'external_package_id' => $packageId, 'status' => 'delivered', 'currency' => 'SAR']);
    }

    /** What the API gave: a fee of one order, posted on 2026-11-05. */
    private function api(string $identity, string $amount, ?string $orderId = '111', ?string $packageId = '900', string $postedAt = '2026-11-05 10:00:00 UTC', CostComponent $component = CostComponent::MarketplaceCommission): array
    {
        return app(ChannelFeeImporter::class)->import($this->account, [
            new RemoteFinancialEntry($identity, RemoteFinancialEntry::FEE, $component, Money::of($amount), 'SAR', $orderId, $packageId, null, null, CarbonImmutable::parse($postedAt), 'Test'),
        ]);
    }

    /** @param list<string> $rows lines of a statement file, without the header */
    private function statement(array $rows): array
    {
        return app(ManualStatementImporter::class)->import($this->account, self::HEADER . implode("\n", $rows) . "\n");
    }

    /** @return array<string, string> component => the sum of the effective actual entries of that component */
    private function actuals(): array
    {
        $sums = [];
        foreach (OperationalCostEntry::where('is_effective', true)->where('state', CostState::Actual->value)->get() as $entry) {
            $sums[$entry->cost_component] = Money::of($sums[$entry->cost_component] ?? 0)->plus($entry->amount)->toStorage();
        }
        ksort($sums);

        return $sums;
    }

    private function manualEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return SalesChannelEvent::where('source', 'manual')->orderBy('id')->get();
    }

    // ---- the canonical identity ----------------------------------------------------------------------------------------

    public function test_25_and_25_00_and_25_000000_are_the_same_amount_in_a_charge_identity(): void
    {
        $identity = fn (string $amount): ChargeIdentity => ChargeIdentity::of(1, '111', '900', null, CostComponent::MarketplaceCommission, 'SAR', Money::of($amount));

        $this->assertSame($identity('25')->shape, $identity('25.00')->shape);
        $this->assertSame($identity('25')->shape, $identity('25.000000')->shape);
        $this->assertSame($identity('25')->shape, $identity('25.0')->shape);
        $this->assertNotSame($identity('25')->shape, $identity('25.01')->shape);
    }

    public function test_the_identity_tells_apart_the_fee_type_the_currency_and_the_account_and_names_what_it_is_about(): void
    {
        $base = ChargeIdentity::of(1, '111', '900', null, CostComponent::MarketplaceCommission, 'SAR', Money::of('25'));

        $this->assertNotSame($base->shape, ChargeIdentity::of(1, '111', '900', null, CostComponent::MarketplaceServiceFee, 'SAR', Money::of('25'))->shape);
        $this->assertNotSame($base->shape, ChargeIdentity::of(1, '111', '900', null, CostComponent::MarketplaceCommission, 'USD', Money::of('25'))->shape);
        $this->assertNotSame($base->shape, ChargeIdentity::of(2, '111', '900', null, CostComponent::MarketplaceCommission, 'SAR', Money::of('25'))->shape);
        $this->assertSame('111|900', $base->reference);
        $this->assertSame('111|', ChargeIdentity::referenceOf('111', null, null));
        $this->assertSame('settlement:PO-7', ChargeIdentity::referenceOf(null, null, 'PO-7'));
        $this->assertNull(ChargeIdentity::referenceOf(null, null, null));
        $this->assertSame('111|900', ChargeIdentity::referenceOf(' 111 ', ' 900 ', 'PO-7'), 'An order wins over a settlement.');
    }

    public function test_the_same_row_typed_as_25_and_as_25_00_is_the_same_row_to_import(): void
    {
        $this->order();

        $first = $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25,SAR,Commission']);
        $second = $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);
        $third = $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25.000000,SAR,Commission']);

        $this->assertSame([1, 0, 0], [$first['new'], $second['new'], $third['new']]);
        $this->assertSame([0, 1, 1], [$first['duplicates'], $second['duplicates'], $third['duplicates']], 'The same fact however the amount was typed.');
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    // ---- rule 1: the same identifiers -------------------------------------------------------------------------------------

    public function test_a_statement_row_with_the_same_order_package_fee_and_amount_as_an_api_fee_is_a_duplicate_and_not_counted(): void
    {
        $this->order();
        $this->api('api-1', '25.00');

        $result = $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25,SAR,Commission']);

        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals(), 'Not 50.');
        $this->assertSame([1, 1, 0], [$result['new'], $result['already_via_api'], $result['possible_overlap']]);
        $row = $this->manualEvents()->firstOrFail();
        $this->assertSame([SalesChannelEvent::DUPLICATE, SalesChannelEvent::where('source', 'poll')->value('id')], [$row->outcome, $row->duplicate_of_id]);
        $this->assertSame(1, OperationalCostEntry::where('is_effective', true)->count());
    }

    public function test_a_row_that_names_only_the_order_matches_the_api_fee_of_any_package_of_it(): void
    {
        $this->order();
        $this->api('api-1', '25.00');

        $result = $this->statement(['111,,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->assertSame(1, $result['already_via_api']);
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    public function test_a_row_with_the_same_identifiers_but_another_amount_is_another_charge_and_both_count(): void
    {
        $this->order();
        $this->api('api-1', '25.00');

        $result = $this->statement(['111,900,2026-11-05,fee,marketplace_commission,10.00,SAR,A second commission']);

        $this->assertSame(0, $result['already_via_api']);
        $this->assertSame(['marketplace_commission' => '35.000000'], $this->actuals());
    }

    public function test_the_same_amount_of_another_fee_type_or_another_order_is_not_a_repeat(): void
    {
        $this->order();
        $this->order('222', '901');
        $this->api('api-1', '25.00');

        $result = $this->statement([
            '111,900,2026-11-05,fee,marketplace_service_fee,25.00,SAR,Another fee type',
            '222,901,2026-11-05,fee,marketplace_commission,25.00,SAR,Another order',
        ]);

        $this->assertSame(0, $result['already_via_api']);
        $this->assertCount(3, OperationalCostEntry::where('is_effective', true)->get());
    }

    public function test_two_charges_of_the_same_amount_in_the_file_while_the_api_gave_one_count_once_from_the_file(): void
    {
        $this->order();
        $this->api('api-1', '5.00', component: CostComponent::MarketplaceServiceFee);

        $result = $this->statement([
            '111,900,2026-11-05,fee,marketplace_service_fee,5.00,SAR,Per unit fee',
            '111,900,2026-11-05,fee,marketplace_service_fee,5,SAR,Per unit fee',
        ]);

        $this->assertSame(1, $result['already_via_api'], 'One of the two repeats the API fee; the other has nothing left to repeat.');
        $this->assertSame(['marketplace_service_fee' => '10.000000'], $this->actuals(), 'Two charges of 5.00: the API\'s and the file\'s second.');
    }

    public function test_importing_the_same_file_again_changes_nothing_and_is_not_reported_as_skipped_again(): void
    {
        $this->order();
        $this->api('api-1', '25.00');
        $row = '111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission';

        $first = $this->statement([$row]);
        $second = $this->statement([$row]);

        $this->assertSame([1, 1], [$first['new'], $first['already_via_api']]);
        $this->assertSame([0, 1, 0], [$second['new'], $second['duplicates'], $second['already_via_api']], 'Already imported, not skipped for the API this time.');
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    public function test_a_charge_that_names_only_a_settlement_matches_by_the_settlement(): void
    {
        $this->api('api-pay', '39.00', orderId: null, packageId: null, component: CostComponent::MarketplaceServiceFee);
        SalesChannelEvent::where('dedupe_key', 'api-pay')->update(['external_reference' => 'settlement:PO-7']);

        $matched = app(ManualStatementImporter::class)->import($this->account, "order_id,package_id,posted_at,category,component,amount,currency,description,settlement_id\n,,2026-11-30,fee,marketplace_service_fee,39,SAR,Subscription,PO-7\n");
        $other = app(ManualStatementImporter::class)->import($this->account, "order_id,package_id,posted_at,category,component,amount,currency,description,settlement_id\n,,2026-11-30,fee,marketplace_service_fee,39,SAR,Subscription,PO-8\n");

        $this->assertSame(1, $matched['already_via_api']);
        $this->assertSame(0, $other['already_via_api'], 'Another settlement: another charge.');
        $this->assertSame(['marketplace_service_fee' => '78.000000'], $this->actuals());
    }

    // ---- rule 2: no identifiers -------------------------------------------------------------------------------------------

    public function test_a_row_with_no_identifiers_is_a_duplicate_when_exactly_one_api_fee_has_the_same_type_currency_amount_and_day(): void
    {
        $this->order();
        $this->api('api-1', '25.00');

        $result = $this->statement([',,2026-11-06,fee,marketplace_commission,25.00,SAR,Commission (no order given)']);

        $this->assertSame([1, 0], [$result['already_via_api'], $result['possible_overlap']]);
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    public function test_a_row_with_no_identifiers_and_several_candidates_is_counted_and_marked_possible_overlap(): void
    {
        $this->order();
        $this->order('222', '901');
        $this->api('api-1', '25.00');
        $this->api('api-2', '25.00', orderId: '222', packageId: '901');

        $result = $this->statement([',,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission (no order given)']);

        $this->assertSame([0, 1], [$result['already_via_api'], $result['possible_overlap']]);
        $row = $this->manualEvents()->firstOrFail();
        $this->assertSame(SalesChannelEvent::NOTE_POSSIBLE_OVERLAP, $row->outcome_note);
        $this->assertContains($row->outcome, [SalesChannelEvent::RECEIVED, SalesChannelEvent::APPLIED], 'Counted, not a duplicate.');
        $this->assertSame('75.000000', Money::sum(OperationalCostEntry::where('is_effective', true)->pluck('amount'))->toStorage(), 'Imported: it is counted, and marked for a person.');
    }

    public function test_a_row_with_no_identifiers_and_no_candidate_is_counted_and_marked_possible_overlap(): void
    {
        $result = $this->statement([',,2026-11-06,fee,marketplace_service_fee,39.00,SAR,Subscription']);

        $this->assertSame([0, 1], [$result['already_via_api'], $result['possible_overlap']]);
        $this->assertSame(['marketplace_service_fee' => '39.000000'], $this->actuals());
        $this->assertSame(SalesChannelEvent::NOTE_POSSIBLE_OVERLAP, $this->manualEvents()->firstOrFail()->outcome_note);
    }

    public function test_an_api_fee_more_than_a_day_away_is_not_a_candidate(): void
    {
        $this->order();
        $this->api('api-1', '25.00', postedAt: '2026-11-02 10:00:00 UTC');

        $result = $this->statement([',,2026-11-06,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->assertSame(0, $result['already_via_api']);
        $this->assertSame(['marketplace_commission' => '50.000000'], $this->actuals());
    }

    public function test_a_row_with_an_order_that_matches_nothing_is_not_marked_as_an_overlap(): void
    {
        $this->order();

        $result = $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->assertSame([0, 0], [$result['already_via_api'], $result['possible_overlap']], 'It names its order: nothing the API gave overlaps with it.');
        $this->assertNull($this->manualEvents()->firstOrFail()->outcome_note);
    }

    // ---- the other way round: the statement first -------------------------------------------------------------------------

    public function test_a_statement_imported_first_stops_counting_when_the_api_reads_the_same_charge_later(): void
    {
        $this->order();
        $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());

        $this->api('api-1', '25.00');

        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals(), 'Not 50: the file\'s row is the repeat now.');
        $row = $this->manualEvents()->firstOrFail();
        $this->assertSame([SalesChannelEvent::DUPLICATE, SalesChannelEvent::where('source', 'poll')->value('id')], [$row->outcome, $row->duplicate_of_id]);
    }

    public function test_a_statement_that_was_never_posted_is_not_posted_after_the_api_gave_the_same_charge(): void
    {
        config(['operational_costs.enabled' => false]);
        $this->order();
        $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);
        $this->api('api-1', '25.00');

        config(['operational_costs.enabled' => true]);
        Artisan::call('costs:post-pending-fees');

        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    public function test_a_statement_fee_of_no_order_we_hold_that_the_api_later_gave_is_taken_back_with_an_opposite_entry(): void
    {
        $this->statement(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);
        $this->assertSame(1, OperationalCostEntry::where('is_effective', true)->count());

        $this->api('api-1', '25.00');

        $this->assertSame('25.000000', Money::sum(OperationalCostEntry::where('is_effective', true)->pluck('amount'))->toStorage(), 'The row\'s fee (25), its reversal (-25) and the API\'s fee (25): one fee.');
        $this->assertSame(1, OperationalCostEntry::where('amount', '-25.000000')->count());
    }

    public function test_a_statement_row_with_no_identifiers_is_only_marked_when_the_api_gives_the_same_charge_later(): void
    {
        $this->order();
        $this->statement([',,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->api('api-1', '25.00');

        $row = $this->manualEvents()->firstOrFail();
        $this->assertSame(SalesChannelEvent::NOTE_POSSIBLE_OVERLAP, $row->outcome_note, 'It was posted as a fee of no order: only a person can take it back.');
        $this->assertNotSame(SalesChannelEvent::DUPLICATE, $row->outcome);
    }

    // ---- netting and the command ------------------------------------------------------------------------------------------

    public function test_a_row_that_names_only_the_order_and_the_api_fee_of_the_same_order_are_netted_together_not_one_over_the_other(): void
    {
        $this->order();
        $this->api('api-1', '25.00');

        $this->statement(['111,,2026-11-06,fee,marketplace_commission,-10.00,SAR,Part returned']);

        $this->assertSame(['marketplace_commission' => '15.000000'], $this->actuals());
    }

    public function test_the_import_command_tells_the_person_how_many_rows_were_skipped_because_the_api_had_them(): void
    {
        $this->order();
        $this->api('api-1', '25.00');
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, self::HEADER . "111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission\n,,2026-11-30,fee,marketplace_service_fee,39.00,SAR,Subscription\n");

        $code = Artisan::call('channels:import-statement', ['account' => $this->account->id, 'file' => $path]);
        $output = Artisan::output();
        unlink($path);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2 rows: 2 new', $output);
        $this->assertStringContainsString('1 rows skipped: the channel API had already given the same charge.', $output);
        $this->assertStringContainsString('1 rows were imported but may repeat a charge the API gave (possible_overlap)', $output);
    }
}
