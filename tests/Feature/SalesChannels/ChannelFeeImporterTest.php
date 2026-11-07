<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** What a channel's statement does to the cost ledger: costs counted once and netted, payouts and unknowns never counted, nothing spread by guesswork. */
class ChannelFeeImporterTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private ChannelFeeImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR']);
        $this->importer = app(ChannelFeeImporter::class);
    }

    private function entry(string $identity, string $category, CostComponent $component, string $amount, ?string $orderId = '111', ?string $packageId = '900', string $currency = 'SAR'): RemoteFinancialEntry
    {
        return new RemoteFinancialEntry($identity, $category, $component, Money::of($amount), $currency, $orderId, $packageId, null, null, CarbonImmutable::parse('2026-11-06 10:00:00 UTC'), 'Test');
    }

    private function order(SalesChannelAccount $account, string $orderId = '111', string $packageId = '900'): ExternalOrderMapping
    {
        return ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => $account->channel, 'seller_id' => 0, 'external_order_id' => $orderId, 'external_package_id' => $packageId, 'status' => 'delivered', 'currency' => 'SAR']);
    }

    /** @return array<string, string> component => amount of the effective actual entries */
    private function actuals(): array
    {
        return OperationalCostEntry::where('is_effective', true)->where('state', CostState::Actual->value)->pluck('amount', 'cost_component')->all();
    }

    public function test_a_fee_whose_recording_failed_once_is_recorded_by_the_next_run_and_not_twice_after_that(): void
    {
        $account = $this->trendyolAccount();
        $this->order($account);
        $fact = $this->entry('a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '25');

        $refusing = new class(app(AuditLogger::class)) extends CostLedger {
            public function record(CostDraft $draft): RecordOutcome
            {
                throw new QueryException('mysql', 'insert into operational_cost_entries', [], new \Exception('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction'));
            }
        };
        $this->app->instance(CostLedger::class, $refusing);
        try {
            app(ChannelFeeImporter::class)->import($account, [$fact]);
            $this->fail('The database refusal was swallowed.');
        } catch (QueryException) {
            $this->assertSame(0, OperationalCostEntry::count());
            $this->assertSame('received', SalesChannelEvent::firstOrFail()->outcome, 'The fact is remembered as not yet in the ledger.');
        }

        $this->app->instance(CostLedger::class, new CostLedger(app(AuditLogger::class)));
        $second = app(ChannelFeeImporter::class)->import($account, [$fact]);
        $third = app(ChannelFeeImporter::class)->import($account, [$fact]);

        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
        $this->assertSame(['new' => 0, 'duplicates' => 0, 'costs_recorded' => 1, 'needs_review' => 0], $second);
        $this->assertSame(['new' => 0, 'duplicates' => 1, 'costs_recorded' => 0, 'needs_review' => 0], $third, 'Once recorded it is a duplicate like any other.');
        $this->assertSame('applied', SalesChannelEvent::firstOrFail()->outcome);
        $this->assertSame(1, OperationalCostEntry::count());
    }

    /** @param list<array<string, mixed>> $lines */
    private function marketplaceInvoice(string $number, ExternalOrderMapping $mapping, array $lines): \App\Models\OperationalCostInvoice
    {
        $invoices = app(\App\Services\OperationalCosts\Invoices\InvoiceService::class);
        $total = Money::sum(array_map(static fn (array $line): Money => Money::of($line['amount']), $lines))->toStorage();
        $items = array_map(static fn (array $line): array => $line + ['external_order_mapping_id' => $mapping->id], $lines);

        return $invoices->create([
            'kind' => 'marketplace_statement', 'party_type' => 'marketplace', 'provider_code' => $mapping->sales_channel, 'party_name' => 'Marketplace', 'invoice_number' => $number,
            'invoice_date' => '2026-11-30', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => $total,
        ], $items, adminId: 1)->invoice;
    }

    private function approve(\App\Models\OperationalCostInvoice $invoice): void
    {
        app(\App\Services\OperationalCosts\Invoices\InvoiceService::class)->approve($invoice, 1);
    }

    public function test_a_commission_on_the_invoice_is_the_same_cost_as_the_one_from_the_statement_and_the_estimate(): void
    {
        $account = $this->trendyolAccount();
        $mapping = $this->order($account);
        $this->importer->import($account, [$this->entry('a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '25')]);

        $this->approve($this->marketplaceInvoice('MP-1', $mapping, [['cost_component' => 'marketplace_commission', 'amount' => '25', 'description' => 'commission order 111']]));

        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals(), 'The fee the statement already carried is not counted a second time.');
        $this->assertSame(1, OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->count());
    }

    public function test_an_invoice_commission_replaces_the_estimate_of_the_order(): void
    {
        $account = $this->trendyolAccount();
        $mapping = $this->order($account);
        app(\App\Services\OperationalCosts\CostLedger::class)->record(new CostDraft(
            subjectType: 'channel_order', subjectId: (int) $mapping->id, component: CostComponent::MarketplaceCommission, state: CostState::Estimated, amount: Money::of('20'), currency: 'SAR',
            periodDate: now(), source: 'rule', identity: 'rule:1', externalOrderMappingId: (int) $mapping->id,
        ));

        $this->approve($this->marketplaceInvoice('MP-1', $mapping, [['cost_component' => 'marketplace_commission', 'amount' => '22']]));

        $effective = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->get();
        $this->assertCount(1, $effective, 'Estimate 20 plus invoice 22 is one cost of 22, not 42.');
        $this->assertSame([CostState::Actual, '22.000000'], [$effective[0]->state, $effective[0]->amount]);
    }

    public function test_a_commission_and_its_refund_on_one_invoice_are_one_net_fee(): void
    {
        $account = $this->trendyolAccount();
        $mapping = $this->order($account);

        $this->approve($this->marketplaceInvoice('MP-1', $mapping, [
            ['cost_component' => 'marketplace_commission', 'amount' => '25', 'description' => 'commission'],
            ['cost_component' => 'marketplace_commission', 'amount' => '-10', 'description' => 'commission refunded with the return'],
        ]));

        $this->assertSame(['marketplace_commission' => '15.000000'], $this->actuals());
    }

    public function test_a_commission_and_the_part_returned_with_a_refund_are_netted_into_one_actual(): void
    {
        $account = $this->trendyolAccount();
        $this->order($account);

        $this->importer->import($account, [$this->entry('a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '25')]);
        $this->importer->import($account, [$this->entry('b', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '-10')]);

        $this->assertSame(['marketplace_commission' => '15.000000'], $this->actuals());
        $this->assertSame(1, OperationalCostEntry::where('is_effective', true)->count(), 'The earlier net is superseded, not added to.');
        $this->assertSame(2, OperationalCostEntry::where('cost_component', 'marketplace_commission')->count(), 'Nothing is deleted: the earlier net stays as history.');
    }

    public function test_the_same_fact_delivered_again_is_recorded_once(): void
    {
        $account = $this->trendyolAccount();
        $this->order($account);
        $fact = $this->entry('a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '25');

        $first = $this->importer->import($account, [$fact]);
        $second = $this->importer->import($account, [$fact]);

        $this->assertSame([1, 0], [$first['new'], $second['new']]);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(1, OperationalCostEntry::count());
    }

    public function test_sales_refunds_payouts_and_duplicates_are_kept_but_never_become_costs(): void
    {
        $account = $this->trendyolAccount();
        $this->order($account);

        $stats = $this->importer->import($account, [
            $this->entry('s', RemoteFinancialEntry::SALE, CostComponent::Other, '170'),
            $this->entry('r', RemoteFinancialEntry::REFUND, CostComponent::Other, '170'),
            $this->entry('p', RemoteFinancialEntry::PAYOUT, CostComponent::Other, '900', orderId: null, packageId: null),
            $this->entry('d', RemoteFinancialEntry::DUPLICATE_OF_ANOTHER_ENTRY, CostComponent::MarketplaceCommission, '25'),
        ]);

        $this->assertSame(0, OperationalCostEntry::count(), 'A payout is not revenue and a repeated commission is not a second cost.');
        $this->assertSame(4, $stats['new']);
        $this->assertSame(4, SalesChannelEvent::count(), 'They are kept for reconciliation.');
    }

    public function test_a_fact_nobody_could_classify_is_flagged_for_a_person_and_not_counted(): void
    {
        $account = $this->trendyolAccount();

        $stats = $this->importer->import($account, [$this->entry('u', RemoteFinancialEntry::UNCLASSIFIED, CostComponent::Other, '40', orderId: null, packageId: null)]);

        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame('needs_review', SalesChannelEvent::firstOrFail()->outcome);
    }

    public function test_a_fee_that_belongs_to_no_order_we_hold_stays_on_the_account_unallocated(): void
    {
        $account = $this->amazonAccount();

        $this->importer->import($account, [
            $this->entry('sub-1', RemoteFinancialEntry::FEE, CostComponent::MarketplaceServiceFee, '39', orderId: null, packageId: null),
            $this->entry('sub-2', RemoteFinancialEntry::FEE, CostComponent::MarketplaceServiceFee, '39', orderId: null, packageId: null),
            $this->entry('ghost', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '12', orderId: 'NOT-IMPORTED-YET', packageId: null),
        ]);

        $entries = OperationalCostEntry::where('is_effective', true)->get();
        $this->assertCount(3, $entries, 'Two separate monthly fees are two costs, not one.');
        $this->assertSame(['channel_account'], $entries->pluck('subject_type')->unique()->values()->all());
        $this->assertSame(['unallocated'], $entries->pluck('allocation_status')->unique()->values()->all());
    }

    public function test_an_order_held_as_several_packages_is_not_guessed_at(): void
    {
        $account = $this->trendyolAccount();
        $this->order($account, '111', '900');
        $this->order($account, '111', '901');

        $this->importer->import($account, [$this->entry('x', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '25', packageId: null)]);

        $this->assertSame('channel_account', OperationalCostEntry::firstOrFail()->subject_type);
    }

    public function test_an_approved_figure_is_not_displaced_by_a_later_posting(): void
    {
        $account = $this->trendyolAccount();
        $this->order($account);
        $this->importer->import($account, [$this->entry('a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, '25')]);
        app(CostLedger::class)->approve(OperationalCostEntry::firstOrFail(), 1, 'checked against the statement');

        $this->importer->import($account, [$this->entry('b', RemoteFinancialEntry::ADJUSTMENT, CostComponent::MarketplaceCommission, '5')]);

        $effective = OperationalCostEntry::where('is_effective', true)->firstOrFail();
        $this->assertSame('25.000000', $effective->amount, 'The approved entry is untouched.');
        $this->assertSame(CostState::Disputed, OperationalCostEntry::where('is_effective', false)->firstOrFail()->state, 'The newer figure waits beside it for a person to resolve.');
    }

    public function test_the_cost_carries_its_base_currency_and_the_order_it_belongs_to(): void
    {
        $account = $this->trendyolAccount();
        $mapping = $this->order($account);

        $this->importer->import($account, [$this->entry('usd', RemoteFinancialEntry::FEE, CostComponent::MarketplaceServiceFee, '10', currency: 'USD')]);

        $row = OperationalCostEntry::firstOrFail();
        $this->assertSame(['channel_order', $mapping->id, 'USD', 'channel_api'], [$row->subject_type, (int) $row->subject_id, $row->currency, $row->source]);
        $this->assertSame('37.500000', $row->base_amount, '10 USD at 3.75 SAR per USD.');
        $this->assertSame('SAR', $row->base_currency);
    }
}
