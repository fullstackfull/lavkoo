<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Models\OperationalCostReconciliation;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\CostRefresher;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Invoices\ReconciliationService;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Services\SalesChannels\Finance\ManualStatementImporter;
use Carbon\CarbonImmutable;
use Tests\Support\CreatesOperationalCostData;

/**
 * A marketplace invoice that bills a fee as one total for a month, with no order on the line, is compared with what the channel's statements already booked for that fee and month
 * instead of being posted as a second set of actual costs; and the same fee told by the estimate, the API, a statement file and an invoice is one cost in the profit.
 */
class InvoiceAgainstStatementsTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fulfillment.currency' => 'SAR']);
        $this->account = $this->trendyolAccount();
        $this->costChannelAccounts['trendyol'] = $this->account;
    }

    private function fee(string $identity, string $amount, string $orderId, string $postedAt = '2026-11-05 10:00:00 UTC', CostComponent $component = CostComponent::MarketplaceCommission): RemoteFinancialEntry
    {
        return new RemoteFinancialEntry($identity, RemoteFinancialEntry::FEE, $component, Money::of($amount), 'SAR', $orderId, null, null, null, CarbonImmutable::parse($postedAt), 'Test');
    }

    /** Two orders' commissions the channel's statements booked in November: 25.00 and 30.00. */
    private function statementsOfNovember(): void
    {
        foreach (['111' => '25.00', '222' => '30.00'] as $order => $amount) {
            ExternalOrderMapping::create(['account_id' => $this->account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => $order, 'external_package_id' => '', 'status' => 'delivered', 'currency' => 'SAR']);
            app(ChannelFeeImporter::class)->import($this->account, [$this->fee('api-' . $order, $amount, $order)]);
        }
    }

    /** @param array<string, mixed> $line */
    private function invoice(string $number, array $line = [], array $data = []): OperationalCostInvoice
    {
        $line += ['cost_component' => 'marketplace_commission', 'amount' => '55', 'description' => 'Commission, November'];

        return app(InvoiceService::class)->create($data + [
            'kind' => 'marketplace_statement', 'party_type' => 'marketplace', 'provider_code' => 'trendyol', 'party_name' => 'Trendyol', 'invoice_number' => $number,
            'invoice_date' => '2026-12-01', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => $line['amount'],
        ], [$line], adminId: 1)->invoice;
    }

    private function reconciliation(OperationalCostInvoice $invoice): OperationalCostReconciliation
    {
        return OperationalCostReconciliation::where('invoice_id', $invoice->id)->firstOrFail();
    }

    private function effectiveCommission(): string
    {
        return Money::sum(OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->pluck('amount'))->toStorage();
    }

    public function test_a_monthly_total_the_statements_already_booked_is_compared_with_them_and_posts_no_second_set_of_actuals(): void
    {
        $this->statementsOfNovember();
        $invoice = $this->invoice('TY-NOV');

        app(InvoiceService::class)->approve($invoice, 1);

        $this->assertSame('55.000000', $this->effectiveCommission(), 'Not 110.');
        $this->assertSame(2, OperationalCostEntry::where('is_effective', true)->count());
        $row = $this->reconciliation($invoice);
        $this->assertSame([OperationalCostReconciliation::MATCHED, OperationalCostReconciliation::BASIS_STATEMENTS, '55.000000', '55.000000', '0.000000'], [$row->status, $row->basis, $row->estimated_amount, $row->actual_amount, $row->variance_amount]);
        $this->assertSame([OperationalCostInvoiceItem::MATCHED, null], [$invoice->items()->first()->match_status, $invoice->items()->first()->entry_id]);
        $this->assertSame(1, app(ReconciliationService::class)->comparedWithStatements($invoice));
    }

    public function test_a_difference_within_the_tolerance_is_matched_and_the_statements_stand(): void
    {
        $this->statementsOfNovember();
        $invoice = $this->invoice('TY-NOV', ['amount' => '55.50']);

        app(InvoiceService::class)->approve($invoice, 1);

        $row = $this->reconciliation($invoice);
        $this->assertSame([OperationalCostReconciliation::MATCHED, '0.500000'], [$row->status, $row->variance_amount]);
        $this->assertSame('55.000000', $this->effectiveCommission());
    }

    public function test_a_difference_beyond_the_tolerance_waits_for_a_person_and_posts_nothing_yet(): void
    {
        $this->statementsOfNovember();
        $invoice = $this->invoice('TY-NOV', ['amount' => '60']);

        app(InvoiceService::class)->approve($invoice, 1);

        $row = $this->reconciliation($invoice);
        $this->assertSame([OperationalCostReconciliation::VARIANCE, '5.000000', '9.0909'], [$row->status, $row->variance_amount, $row->variance_percent]);
        $this->assertSame('55.000000', $this->effectiveCommission());
        $this->assertSame(OperationalCostInvoiceItem::PARTIAL, $invoice->items()->first()->match_status);
    }

    public function test_accepting_the_difference_books_it_once_as_an_adjustment_so_the_books_say_what_the_invoice_says(): void
    {
        $this->statementsOfNovember();
        $invoice = $this->invoice('TY-NOV', ['amount' => '60']);
        app(InvoiceService::class)->approve($invoice, 1);
        $reconciliations = app(ReconciliationService::class);

        $reconciliations->resolve($this->reconciliation($invoice), 'accept', 1, 'Trendyol bills the platform fee of two more orders');
        $reconciliations->resolve($this->reconciliation($invoice)->fresh(), 'accept', 1, 'again');

        $this->assertSame('60.000000', $this->effectiveCommission(), 'The invoice\'s 60: the statements\' 55 and the 5 they did not have.');
        $adjustment = OperationalCostEntry::where('invoice_item_id', $invoice->items()->first()->id)->firstOrFail();
        $this->assertSame(['5.000000', CostState::Reconciled, 'invoice'], [$adjustment->amount, $adjustment->state, $adjustment->source]);
        $this->assertSame(1, OperationalCostEntry::where('invoice_item_id', $invoice->items()->first()->id)->count(), 'Once.');
        $this->assertSame($adjustment->id, $this->reconciliation($invoice)->actual_entry_id);
    }

    public function test_a_difference_in_the_other_direction_books_a_credit_and_a_disputed_one_books_nothing(): void
    {
        $this->statementsOfNovember();
        $lower = $this->invoice('TY-LOW', ['amount' => '50']);
        $disputed = $this->invoice('TY-HIGH', ['amount' => '70']);
        app(InvoiceService::class)->approve($lower, 1);
        app(InvoiceService::class)->approve($disputed, 1);
        $reconciliations = app(ReconciliationService::class);

        $reconciliations->resolve($this->reconciliation($lower), 'write_off', 1, 'we accept the lower figure');
        $reconciliations->resolve($this->reconciliation($disputed), 'dispute', 1, 'we do not owe 15 more');

        $this->assertSame('50.000000', $this->effectiveCommission(), '55 booked by the statements and 5 given back; the 15 we dispute is not booked.');
        $this->assertSame(OperationalCostReconciliation::DISPUTED, $this->reconciliation($disputed)->status);
        $this->assertSame(0, OperationalCostEntry::where('invoice_item_id', $disputed->items()->first()->id)->count());
    }

    public function test_a_fee_the_statements_booked_nothing_for_is_posted_as_before(): void
    {
        $this->statementsOfNovember();
        $december = $this->invoice('TY-DEC', ['amount' => '80'], ['period_start' => '2026-12-01', 'period_end' => '2026-12-31', 'invoice_date' => '2027-01-02']);
        $otherFee = $this->invoice('TY-FEE', ['cost_component' => 'marketplace_service_fee', 'amount' => '39']);

        app(InvoiceService::class)->approve($december, 1);
        app(InvoiceService::class)->approve($otherFee, 1);

        $this->assertSame(['80.000000'], OperationalCostEntry::where('invoice_item_id', $december->items()->first()->id)->pluck('amount')->all(), 'No statement covers December.');
        $this->assertSame(['39.000000'], OperationalCostEntry::where('invoice_item_id', $otherFee->items()->first()->id)->pluck('amount')->all(), 'No statement gave this fee in November.');
        $this->assertSame(0, OperationalCostReconciliation::where('basis', OperationalCostReconciliation::BASIS_STATEMENTS)->count());
    }

    public function test_an_invoice_with_no_period_cannot_say_what_it_covers_and_is_posted_as_before(): void
    {
        $this->statementsOfNovember();
        $invoice = $this->invoice('TY-NOPERIOD', [], ['period_start' => '', 'period_end' => '']);

        app(InvoiceService::class)->approve($invoice, 1);

        $this->assertSame(['55.000000'], OperationalCostEntry::where('invoice_item_id', $invoice->items()->first()->id)->pluck('amount')->all());
    }

    public function test_the_statements_of_another_account_are_not_what_an_invoice_of_this_one_is_compared_with(): void
    {
        $this->statementsOfNovember();
        $other = $this->trendyolAccount(sellerId: '7654321');
        $invoice = $this->invoice('TY-OTHER', [], ['account_id' => $other->id]);

        app(InvoiceService::class)->approve($invoice, 1);

        $this->assertSame(['55.000000'], OperationalCostEntry::where('invoice_item_id', $invoice->items()->first()->id)->pluck('amount')->all());
    }

    public function test_a_statement_file_a_person_imported_counts_as_what_the_statements_booked(): void
    {
        ExternalOrderMapping::create(['account_id' => $this->account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => '900', 'status' => 'delivered', 'currency' => 'SAR']);
        app(ManualStatementImporter::class)->import($this->account, "order_id,package_id,posted_at,category,component,amount,currency,description\n111,900,2026-11-05,fee,marketplace_commission,55.00,SAR,Commission\n");
        $invoice = $this->invoice('TY-NOV');

        app(InvoiceService::class)->approve($invoice, 1);

        $this->assertSame('55.000000', $this->effectiveCommission());
        $this->assertSame(OperationalCostReconciliation::MATCHED, $this->reconciliation($invoice)->status);
    }

    public function test_approving_from_the_screen_says_how_many_lines_were_compared_instead_of_posted(): void
    {
        $this->statementsOfNovember();
        $invoice = $this->invoice('TY-NOV');

        $this->post(route('admin.operational-costs.invoices.approve', $invoice->id))->assertRedirect();

        $this->assertContains(translate('invoice_lines_compared_with_the_channel_statements') . ': 1', $this->flashedToasts());
        $html = $this->get(route('admin.operational-costs.invoices.show', $invoice->id))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('compared_with_the_channel_statements_not_an_estimate')), $html);
    }

    // ---- one fee, four sources ------------------------------------------------------------------------------------------

    public function test_the_same_fee_told_by_the_estimate_the_api_a_statement_file_and_an_invoice_is_one_cost_in_the_profit(): void
    {
        $contracts = app(ContractService::class);
        $draft = $contracts->createDraft(['party_type' => 'marketplace', 'provider_code' => 'trendyol', 'party_name' => 'Trendyol', 'currency' => 'SAR', 'effective_from' => '2026-01-01', 'reference' => 'T-1', 'settings' => ['expected_components' => ['marketplace_commission']]], adminId: 1);
        $contracts->addRule($draft, ['service_type' => 'marketplace_commission', 'calc_method' => 'percent', 'params' => ['percent' => '10']], adminId: 1);
        $contracts->activate($draft, 1, 'a contract with invented numbers');
        $sale = $this->channelSale('250', 'trendyol', '2026-11-05 09:00:00');
        $document = app(SaleDocumentReader::class)->forChannelOrder($sale->id);
        $profit = fn (): array => (function () use ($sale): array {
            $bucket = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($sale->id))->buckets['marketplace_commission'];

            return [$bucket->estimated->toStorage(), $bucket->actual->toStorage()];
        })();

        // 1. The estimate, from the contract: 10 % of 250.
        app(CostRefresher::class)->refresh($document);
        $this->assertSame(['25.000000', '0.000000'], $profit(), 'An estimate is not yet an actual cost.');

        // 2. The API gives the real charge: it replaces the estimate.
        app(ChannelFeeImporter::class)->import($sale->account, [new RemoteFinancialEntry('api-1', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, Money::of('25'), 'SAR', $sale->external_order_id, $sale->external_package_id, null, null, CarbonImmutable::parse('2026-11-06 10:00:00 UTC'), 'Test')]);
        $this->assertSame(['25.000000', '25.000000'], $profit());

        // 3. A statement file a person exported tells the same charge (typed 25.00): a duplicate, not counted.
        $file = app(ManualStatementImporter::class)->import($sale->account, "order_id,package_id,posted_at,category,component,amount,currency,description\n{$sale->external_order_id},{$sale->external_package_id},2026-11-06,fee,marketplace_commission,25.00,SAR,Commission\n");
        $this->assertSame(1, $file['already_via_api']);
        $this->assertSame(['25.000000', '25.000000'], $profit());

        // 4. The invoice of the month bills the same charge for the order: still one cost.
        $invoice = app(InvoiceService::class)->create([
            'kind' => 'marketplace_statement', 'party_type' => 'marketplace', 'provider_code' => 'trendyol', 'party_name' => 'Trendyol', 'invoice_number' => 'TY-NOV',
            'invoice_date' => '2026-11-30', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => '25',
        ], [['cost_component' => 'marketplace_commission', 'amount' => '25', 'external_order_mapping_id' => $sale->id]], adminId: 1)->invoice;
        app(InvoiceService::class)->approve($invoice, 1);

        $this->assertSame(['25.000000', '25.000000'], $profit(), 'One commission of 25.00, not 25 + 25 + 25 + 25.');
        $this->assertSame(1, OperationalCostEntry::where('is_effective', true)->where('cost_component', 'marketplace_commission')->count());
    }
}
