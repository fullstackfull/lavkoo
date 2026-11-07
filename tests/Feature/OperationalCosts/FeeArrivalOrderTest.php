<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Invoices\ReconciliationService;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Services\SalesChannels\Finance\ManualStatementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * One fee, however it arrives: the channel's API, a statement file a person imported, and the invoice that bills the month as one total. They are one cost whichever came first, a
 * fee belongs to the month of its own posting, a reversed charge is not part of what the statements booked, and what a person decided stands. The figures are invented.
 */
class FeeArrivalOrderTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const HEADER = "order_id,package_id,posted_at,category,component,amount,currency,description\n";

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'operational_costs.enabled' => true]);
        $this->account = $this->trendyolAccount();
    }

    private function order(string $orderId = '111', string $packageId = '900'): ExternalOrderMapping
    {
        return ExternalOrderMapping::create(['account_id' => $this->account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => $orderId, 'external_package_id' => $packageId, 'status' => 'delivered', 'currency' => 'SAR']);
    }

    /** A fee (or, negative, a credit) the channel's API gave. */
    private function api(string $identity, string $amount, ?string $orderId = '111', ?string $packageId = '900', string $postedAt = '2026-11-05 10:00:00 UTC', CostComponent $component = CostComponent::MarketplaceCommission, string $currency = 'SAR', ?SalesChannelAccount $account = null): array
    {
        return app(ChannelFeeImporter::class)->import($account ?? $this->account, [
            new RemoteFinancialEntry($identity, RemoteFinancialEntry::FEE, $component, Money::of($amount), $currency, $orderId, $packageId, null, null, CarbonImmutable::parse($postedAt), 'Test'),
        ]);
    }

    /** @param list<string> $rows */
    private function statementFile(array $rows): array
    {
        return app(ManualStatementImporter::class)->import($this->account, self::HEADER . implode("\n", $rows) . "\n");
    }

    /** @param array<string, mixed> $line */
    private function invoice(string $number, string $amount, array $line = [], array $data = []): OperationalCostInvoice
    {
        return app(InvoiceService::class)->create($data + [
            'kind' => 'marketplace_statement', 'party_type' => 'marketplace', 'provider_code' => 'trendyol', 'party_name' => 'Trendyol', 'invoice_number' => $number, 'invoice_date' => '2026-12-01',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => $amount,
        ], [$line + ['cost_component' => 'marketplace_commission', 'amount' => $amount, 'description' => 'Commission, November']], adminId: 1)->invoice;
    }

    private function approve(OperationalCostInvoice $invoice): OperationalCostInvoice
    {
        app(InvoiceService::class)->approve($invoice, 1);

        return $invoice->fresh();
    }

    private function row(OperationalCostInvoice $invoice): ?OperationalCostReconciliation
    {
        return OperationalCostReconciliation::where('invoice_id', $invoice->id)->first();
    }

    /** What the ledger counts for the commission: the settled entries in effect (a reversal is one of them). */
    private function commission(string $field = 'amount'): string
    {
        return Money::sum(OperationalCostEntry::where('is_effective', true)->whereIn('state', ['actual', 'reconciled'])->where('cost_component', 'marketplace_commission')->pluck($field))->toStorage();
    }

    private function ledgerRows(): int
    {
        return OperationalCostEntry::count();
    }

    // ---- the same fee told by the API and by a statement file ---------------------------------------------------------------------

    public function test_the_api_names_only_the_order_and_the_statement_file_names_the_order_and_its_package_is_one_fee(): void
    {
        $this->order('111', '900');
        $this->api('api-1', '25.00', packageId: null);

        $result = $this->statementFile(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->assertSame('25.000000', $this->commission(), 'One fee of 25, not 50.');
        $this->assertSame(1, $result['already_via_api']);
        $this->assertSame(1, SalesChannelEvent::where('outcome', SalesChannelEvent::DUPLICATE)->count());
    }

    public function test_the_statement_file_names_the_order_and_its_package_and_the_api_names_only_the_order_is_one_fee(): void
    {
        $this->order('111', '900');
        $this->statementFile(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->api('api-1', '25.00', packageId: null);

        $this->assertSame('25.000000', $this->commission(), 'One fee of 25, not 50.');
        $this->assertSame(1, SalesChannelEvent::where('source', 'manual')->where('outcome', SalesChannelEvent::DUPLICATE)->count(), 'The row stops counting, the API\'s charge is the one that does.');
    }

    public function test_a_package_named_on_one_side_only_matches_whichever_side_names_it_and_whichever_came_first(): void
    {
        $this->order('111', '900');
        $this->api('api-1', '25.00', packageId: '900');
        $this->statementFile(['111,,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);
        $this->assertSame('25.000000', $this->commission(), 'The API names the package, the file only the order.');

        $this->order('222', '901');
        $this->statementFile(['222,,2026-11-06,fee,marketplace_commission,30.00,SAR,Commission']);
        $this->api('api-2', '30.00', orderId: '222', packageId: '901', postedAt: '2026-11-06 10:00:00 UTC');
        $this->assertSame('55.000000', $this->commission(), 'The file first, naming only the order; then the API names the package.');
    }

    public function test_an_order_whose_number_merely_starts_like_another_is_another_order(): void
    {
        $this->order('111', '900');
        $this->order('1111', '901');
        $this->api('api-1', '25.00', orderId: '111', packageId: null);

        $this->statementFile(['1111,901,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);

        $this->assertSame('50.000000', $this->commission(), 'Two orders, two fees of 25.');
    }

    // ---- an invoice that bills the month as one total, and the statements ---------------------------------------------------------

    public function test_an_invoice_approved_before_the_statements_is_one_cost_once_they_arrive(): void
    {
        $this->order('111', '900');
        $this->order('222', '901');
        $invoice = $this->approve($this->invoice('TY-NOV', '55'));
        $this->assertSame('55.000000', $this->commission(), 'Nothing booked the fee yet: the invoice line is posted in full.');
        $this->assertNotNull($invoice->items()->first()->entry_id);

        $this->api('api-111', '25.00', orderId: '111', packageId: '900');
        $this->assertSame('25.000000', $this->commission(), 'Part of the month is in: the line is no longer counted as a whole, the statements count, and the 30 they do not have yet are a variance for a person.');
        $this->assertSame([OperationalCostReconciliation::VARIANCE, '25.000000', '55.000000'], [$this->row($invoice)->status, $this->row($invoice)->estimated_amount, $this->row($invoice)->actual_amount]);

        $this->api('api-222', '30.00', orderId: '222', packageId: '901');

        $this->assertSame('55.000000', $this->commission(), 'The November commission is 55, not 110.');
        $this->assertSame('55.000000', $this->commission('base_amount'), 'And so are the totals of the entries page, which add up the amounts in the reporting currency.');
        $this->assertSame([OperationalCostReconciliation::MATCHED, '55.000000', '0.000000'], [$this->row($invoice)->status, $this->row($invoice)->estimated_amount, $this->row($invoice)->variance_amount]);
        $this->assertNull($invoice->items()->first()->fresh()->entry_id, 'The line posts nothing of its own now.');
        $this->assertSame(1, OperationalCostEntry::where('source', 'manual')->count(), 'The full posting was taken back once, with an equal and opposite entry.');
    }

    public function test_statements_stored_while_the_module_was_off_and_an_invoice_approved_meanwhile_are_one_cost_after_the_catch_up(): void
    {
        config(['operational_costs.enabled' => false]);
        $this->order('111', '900');
        $this->order('222', '901');
        $this->api('api-111', '25.00', orderId: '111', packageId: '900');
        $this->api('api-222', '30.00', orderId: '222', packageId: '901');
        $this->assertSame(0, $this->ledgerRows(), 'Nothing is posted while the module is off.');
        $invoice = $this->approve($this->invoice('TY-NOV', '55'));
        $this->assertSame('55.000000', $this->commission(), 'A person approved it: the statements are only stored, so the line is posted in full.');

        config(['operational_costs.enabled' => true]);
        Artisan::call('costs:post-pending-fees');

        $this->assertSame('55.000000', $this->commission(), 'The November commission is 55, not 110.');
        $this->assertSame(OperationalCostReconciliation::MATCHED, $this->row($invoice)->status);
        $rows = $this->ledgerRows();
        Artisan::call('costs:post-pending-fees');
        $this->assertSame($rows, $this->ledgerRows(), 'The catch-up posts, and takes back, exactly once.');
    }

    public function test_a_statement_file_imported_after_the_invoice_is_set_against_it_like_the_api(): void
    {
        $this->order('111', '900');
        $this->approve($this->invoice('TY-NOV', '55'));

        $this->statementFile(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission', '111,900,2026-11-06,fee,marketplace_commission,30.00,SAR,Commission']);

        $this->assertSame('55.000000', $this->commission());
    }

    public function test_the_statements_of_the_invoice_s_own_month_are_what_it_is_set_against_not_those_of_other_fees_accounts_months_or_currencies(): void
    {
        $this->order('111', '900');
        $this->order('222', '901');
        $invoice = $this->approve($this->invoice('TY-NOV', '55', data: ['account_id' => $this->account->id]));
        $other = $this->trendyolAccount(sellerId: '7654321');
        $this->order('333', '902');
        ExternalOrderMapping::create(['account_id' => $other->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '444', 'external_package_id' => '1', 'status' => 'delivered', 'currency' => 'SAR']);

        $this->api('api-service', '9.00', component: CostComponent::MarketplaceServiceFee);
        $this->api('api-december', '12.00', orderId: '222', packageId: '901', postedAt: '2026-12-02 10:00:00 UTC');
        $this->api('api-euro', '8.00', orderId: '333', packageId: '902', currency: 'EUR');
        $this->api('api-other-account', '7.00', orderId: '444', packageId: '1', account: $other);

        $this->assertNotNull($invoice->items()->first()->fresh()->entry_id, 'None of them is the fee, the month, the currency or the account the line bills: it is still counted in full.');
        $this->assertNull($this->row($invoice)?->status);
        $this->assertSame(0, OperationalCostEntry::where('source', 'manual')->count());
    }

    public function test_the_month_a_fee_belongs_to_is_the_day_it_was_posted_not_the_latest_posting_of_its_order(): void
    {
        $this->order('111', '900');
        $this->api('api-fee', '25.00', postedAt: '2026-11-28 10:00:00 UTC');
        $this->api('api-credit', '-5.00', postedAt: '2026-12-03 10:00:00 UTC');
        $net = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->firstOrFail();
        $this->assertSame(['20.000000', '2026-12-03', ['2026-11-28' => '25.000000', '2026-12-03' => '-5.000000']], [$net->amount, $net->period_date->toDateString(), $net->calc_inputs['days']]);

        $november = $this->approve($this->invoice('TY-NOV', '25'));
        $december = $this->approve($this->invoice('TY-DEC', '-5', data: ['period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'invoice_date' => '2027-01-02']));

        $this->assertSame([OperationalCostReconciliation::MATCHED, '25.000000'], [$this->row($november)->status, $this->row($november)->estimated_amount], 'November\'s invoice is compared with the 25 charged in November.');
        $this->assertSame([OperationalCostReconciliation::MATCHED, '-5.000000'], [$this->row($december)->status, $this->row($december)->estimated_amount], 'December\'s credit with the 5 given back in December.');
        $this->assertSame('20.000000', $this->commission(), '25 charged and 5 given back: 20, and neither invoice adds a second set (it was 45).');
    }

    public function test_a_charge_that_was_booked_and_reversed_is_a_total_of_zero_not_nothing_and_not_what_it_was(): void
    {
        $this->order('111', '900');
        $this->api('api-1', '25.00');
        app(CostLedger::class)->reverse(OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->firstOrFail(), 1, 'It was charged by mistake');
        $this->assertSame('0.000000', $this->commission());

        $invoice = $this->approve($this->invoice('TY-NOV', '0.10'));

        $row = $this->row($invoice);
        $this->assertSame([OperationalCostReconciliation::VARIANCE, '0.000000', '0.100000'], [$row->status, $row->estimated_amount, $row->variance_amount], 'The statements booked 25 and gave 25 back: 0, which is not compared with 25.');
    }

    public function test_accepting_the_difference_after_a_reversed_charge_books_what_the_invoice_says(): void
    {
        $this->order('111', '900');
        $this->api('api-1', '25.00');
        app(CostLedger::class)->reverse(OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->firstOrFail(), 1, 'It was charged by mistake');
        $invoice = $this->approve($this->invoice('TY-NOV', '0.10'));

        app(ReconciliationService::class)->resolve($this->row($invoice), 'accept', 1, 'the invoice stands');

        $this->assertSame('0.100000', $this->commission(), 'The books must say what the invoice says: 0.10, not -24.90.');
    }

    public function test_a_difference_a_person_accepted_follows_the_statements_that_arrive_afterwards(): void
    {
        $this->order('111', '900');
        $this->order('222', '901');
        $this->api('api-111', '25.00', orderId: '111', packageId: '900');
        $invoice = $this->approve($this->invoice('TY-NOV', '55'));
        app(ReconciliationService::class)->resolve($this->row($invoice), 'accept', 1, 'Trendyol bills 30 more than the statements have so far');
        $this->assertSame('55.000000', $this->commission(), '25 from the statements and the 30 the invoice stands for.');

        $this->api('api-222', '30.00', orderId: '222', packageId: '901');

        $this->assertSame('55.000000', $this->commission(), 'The 30 are in the statements now: the adjustment is reversed, not counted on top (it was 85).');
        $row = $this->row($invoice);
        $this->assertSame([OperationalCostReconciliation::RESOLVED, '55.000000', '0.000000', null], [$row->status, $row->estimated_amount, $row->variance_amount, $row->actual_entry_id]);
        $rows = $this->ledgerRows();
        app(ChannelFeeImporter::class)->postPending($this->account);
        app(ReconciliationService::class)->settleStatementLines($invoice);
        $this->assertSame($rows, $this->ledgerRows(), 'Settling again changes nothing.');
    }

    public function test_a_difference_a_person_accepted_is_booked_again_when_the_statements_move_the_other_way(): void
    {
        $this->order('111', '900');
        $this->order('222', '901');
        $this->api('api-111', '25.00', orderId: '111', packageId: '900');
        $this->api('api-222', '30.00', orderId: '222', packageId: '901');
        $invoice = $this->approve($this->invoice('TY-NOV', '60'));
        app(ReconciliationService::class)->resolve($this->row($invoice), 'accept', 1, 'five more');
        $this->assertSame('60.000000', $this->commission());

        $this->api('api-credit', '-5.00', orderId: '222', packageId: '901', postedAt: '2026-11-20 10:00:00 UTC');

        $this->assertSame('60.000000', $this->commission(), 'The invoice stands at 60: the statements are 50 now and the adjustment is 10.');
        $this->assertSame('10.000000', $this->row($invoice)->variance_amount);
    }

    public function test_a_line_a_person_disputed_is_left_alone_when_more_statements_arrive(): void
    {
        $this->order('111', '900');
        $this->order('222', '901');
        $this->api('api-111', '25.00', orderId: '111', packageId: '900');
        $invoice = $this->approve($this->invoice('TY-NOV', '55'));
        app(ReconciliationService::class)->resolve($this->row($invoice), 'dispute', 1, 'we do not owe 30 more');

        $this->api('api-222', '30.00', orderId: '222', packageId: '901');

        $this->assertSame(OperationalCostReconciliation::DISPUTED, $this->row($invoice)->status);
        $this->assertSame('55.000000', $this->commission(), 'The statements 25 + 30; the invoice line books nothing while it is contested.');
        $this->assertSame(0, OperationalCostEntry::where('invoice_item_id', $invoice->items()->first()->id)->count());
    }

    public function test_reconciling_an_invoice_again_picks_up_a_reversal_a_person_made_after_the_line_was_compared(): void
    {
        $this->order('111', '900');
        $this->api('api-1', '25.00');
        $invoice = $this->approve($this->invoice('TY-NOV', '25'));
        $this->assertSame(OperationalCostReconciliation::MATCHED, $this->row($invoice)->status);

        app(CostLedger::class)->reverse(OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->firstOrFail(), 1, 'It was charged by mistake');
        $this->assertSame(1, app(ReconciliationService::class)->settleStatementLines($invoice->fresh()));

        $row = $this->row($invoice);
        $this->assertSame([OperationalCostReconciliation::VARIANCE, '0.000000', '25.000000'], [$row->status, $row->estimated_amount, $row->variance_amount]);
    }

    // ---- while the module is off, and the take-back of a repeated statement fee ---------------------------------------------------

    public function test_an_api_fee_that_repeats_a_posted_statement_fee_writes_nothing_while_the_module_is_off_and_is_one_fee_once_it_is_on(): void
    {
        $this->statementFile(['111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission']);
        $this->assertSame(1, $this->ledgerRows(), 'The module was on: the row was posted as a fee of no order we hold.');

        config(['operational_costs.enabled' => false]);
        $this->api('api-1', '25.00');
        $this->assertSame(1, $this->ledgerRows(), 'No ledger row may be written while OPERATIONAL_COSTS_ENABLED is off.');

        config(['operational_costs.enabled' => true]);
        Artisan::call('costs:post-pending-fees');
        Artisan::call('costs:post-pending-fees');

        $this->assertSame('25.000000', $this->commission(), 'One fee.');
        $this->assertSame(1, SalesChannelEvent::where('source', 'manual')->where('outcome', SalesChannelEvent::DUPLICATE)->count());
    }

    // ---- a charge a person contests ------------------------------------------------------------------------------------------------

    public function test_a_later_posting_is_kept_beside_a_contested_charge_for_a_person_and_never_replaces_it(): void
    {
        $this->order('111', '900');
        $this->api('api-1', '25.00');
        $contested = OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->firstOrFail();
        app(CostLedger::class)->dispute($contested, 'We do not owe this commission');

        $this->api('api-2', '-5.00', postedAt: '2026-11-06 10:00:00 UTC');

        $this->assertSame(['disputed', true], [$contested->fresh()->state->value, (bool) $contested->fresh()->is_effective], 'The contest stays until a person resolves it.');
        $beside = OperationalCostEntry::where('cost_component', 'marketplace_commission')->where('id', '!=', $contested->id)->firstOrFail();
        $this->assertSame(['20.000000', false, 'conflicts_with_disputed_entry'], [$beside->amount, (bool) $beside->is_effective, $beside->note], 'The new net of the order is stored beside it, outside the totals.');
        $this->assertSame('0.000000', $this->commission(), 'A contested charge is in no total.');

        app(CostLedger::class)->resolveDispute($contested->fresh(), true, 1, 'the commission is due');
        $this->assertSame('25.000000', $this->commission());
    }
}
