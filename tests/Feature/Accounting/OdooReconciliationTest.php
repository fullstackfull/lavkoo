<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooPosting;
use App\Models\OdooReconciliation;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooOutbox;
use App\Services\Accounting\Odoo\OdooThreeWayReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * Three books for one period, each read from its own place, and the differences left showing.
 *
 * **The point of this file is that a null is never a zero.** The source leg is null when the channel publishes no statement at
 * all — noon has no settlement, payout or fee endpoint anywhere in its reference — and the Odoo leg is null when Odoo could not
 * be read. Reporting either as `0.00` would turn "nobody said" into "it was nothing": it would invent a difference where there
 * is no claim, or hide one where there is.
 *
 * And nothing here closes a difference. A row that resolved one by itself would be agreeing with whichever figure this code
 * happened to trust, and the reason for keeping three books is that none of them is automatically right.
 */
class OdooReconciliationTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;
    use CreatesOperationalCostData;

    private const FROM = '2026-11-01';

    private const TO = '2026-11-30';

    private SalesChannelAccount $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00 UTC'));
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true, 'odoo.draft_creation_enabled' => true]);
        $this->connection = $this->odooConnection();
        $this->connection->forceFill(['settings' => array_merge((array) $this->connection->settings, ['company_id' => self::ODOO_COMPANY])])->save();
        $this->connection = $this->connection->fresh();
    }

    public function test_three_books_that_agree_show_no_difference(): void
    {
        $entry = $this->settledCost('100');
        $this->anApprovedInvoiceLine('100');
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([['debit' => 100.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']]]);

        $row = $this->reconcile();

        $this->assertSame('100.000000', $row->source_amount);
        $this->assertSame('100.000000', $row->ledger_amount);
        $this->assertSame('100.000000', $row->odoo_amount);
        $this->assertTrue($row->agrees());
        $this->assertSame([], $row->missingLegs());
    }

    public function test_a_channel_that_publishes_no_statement_leaves_the_source_leg_null_and_not_zero(): void
    {
        // noon. Its reference has no settlement, payout or fee endpoint anywhere, so there is no figure to compare against --
        // which is a different statement from "the channel charged nothing".
        $entry = $this->settledCost('100');
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([['debit' => 100.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']]]);

        $row = $this->reconcile();

        $this->assertNull($row->source_amount, 'nobody said, so there is no figure');
        $this->assertNull($row->source_basis);
        $this->assertNull($row->sourceDifference(), 'and no difference is invented from a null');
        $this->assertSame(['source'], $row->missingLegs());
        $this->assertTrue($row->agrees(), 'the legs that DO have figures agree');
    }

    public function test_odoo_that_could_not_be_read_leaves_its_leg_null_and_not_zero(): void
    {
        $entry = $this->settledCost('100');
        $this->anApprovedInvoiceLine('100');
        $this->postedTo($entry, 71);
        Http::fake([self::ODOO_OBJECT => Http::response('', 503)]);

        $row = $this->reconcile();

        $this->assertNull($row->odoo_amount, 'not read is not nothing there');
        $this->assertNull($row->odoo_read_at);
        $this->assertNull($row->odooDifference());
        $this->assertSame(['odoo'], $row->missingLegs());
    }

    public function test_a_difference_between_the_channel_and_our_books_is_shown_and_not_closed(): void
    {
        // The reason three books exist: the provider billed more than our ledger computed, and neither figure is automatically
        // the right one.
        $entry = $this->settledCost('100');
        $this->anApprovedInvoiceLine('118.50');
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([['debit' => 100.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']]]);

        $row = $this->reconcile();

        $this->assertSame('18.500000', $row->sourceDifference());
        $this->assertFalse($row->agrees());
        $this->assertSame('118.500000', $row->source_amount, 'and neither figure was changed to agree with the other');
        $this->assertSame('100.000000', $row->ledger_amount);
    }

    public function test_a_difference_between_our_books_and_odoo_is_shown(): void
    {
        $entry = $this->settledCost('100');
        $this->anApprovedInvoiceLine('100');
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([['debit' => 90.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']]]);

        $row = $this->reconcile();

        $this->assertSame('10.000000', $row->odooDifference());
        $this->assertFalse($row->agrees());
    }

    public function test_an_unposted_fact_is_counted_apart_from_a_difference_in_the_figures(): void
    {
        // A gap caused by an empty outbox is a different problem from a gap between two figures, and a report that merged them
        // would send somebody looking in the wrong place.
        $entry = $this->settledCost('100');
        $this->anApprovedInvoiceLine('100');
        app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);
        $this->fakeOdooLines([]);

        $row = $this->reconcile();

        $this->assertSame(0, $row->entries_counted);
        $this->assertSame(1, $row->entries_not_posted, 'one fact is queued and not in Odoo');
        $this->assertSame('0.000000', $row->odoo_amount, 'nothing of ours is in Odoo, which IS a figure');
        $this->assertSame('100.000000', $row->odooDifference());
    }

    public function test_an_estimate_is_not_one_of_the_three_books(): void
    {
        // The ledger leg is what is SETTLED, for the same reason the builder posts only what is settled: an estimate in a
        // reconciliation column would make a figure nobody has confirmed look like one of three agreeing books.
        $this->recordCost(CostState::Estimated, '250', CostComponent::Shipping, '2026-11-10');
        $this->fakeOdooLines([]);

        $row = $this->reconcile();

        $this->assertNull($row->ledger_amount, 'there is nothing settled to compare');
        $this->assertContains('ledger', $row->missingLegs());
    }

    public function test_an_invoice_nobody_approved_is_not_the_channels_word(): void
    {
        // An unapproved invoice is a claim somebody is still checking. Putting it in the source column would make it look
        // confirmed.
        $entry = $this->settledCost('100');
        $this->anApprovedInvoiceLine('500', approved: false);
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([['debit' => 100.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']]]);

        $row = $this->reconcile();

        $this->assertNull($row->source_amount);
        $this->assertSame(['source'], $row->missingLegs());
    }

    public function test_rebuilding_a_period_replaces_its_row_rather_than_adding_one(): void
    {
        // Otherwise the same period would appear several times over with different figures, which is exactly what the unique key
        // on the table exists to prevent.
        $entry = $this->settledCost('100');
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([['debit' => 100.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']]]);

        $this->reconcile();
        $this->reconcile();

        $this->assertSame(1, OdooReconciliation::count());
    }

    public function test_the_odoo_leg_reads_the_debit_column_and_not_a_signed_amount(): void
    {
        // `account.move.line` carries two non-negative columns, and a cost sits in the debit one. Reading a signed total would
        // silently net a refund against a charge.
        $entry = $this->settledCost('100');
        $this->postedTo($entry, 71);
        $this->fakeOdooLines([
            ['debit' => 100.0, 'credit' => 0.0, 'move_id' => [71, 'MISC/0001']],
            ['debit' => 0.0, 'credit' => 100.0, 'move_id' => [71, 'MISC/0001']],
        ]);

        $row = $this->reconcile();

        $this->assertSame('100.000000', $row->odoo_amount, 'the entry balances, and its cost side is 100');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function reconcile(): OdooReconciliation
    {
        return app(OdooThreeWayReconciliation::class)->build(
            $this->connection,
            CarbonImmutable::parse(self::FROM),
            CarbonImmutable::parse(self::TO),
            CostComponent::Shipping->value,
            'SAR',
        );
    }

    private function settledCost(string $amount): \App\Models\OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-10');
    }

    /** One line of the provider's own invoice, which is the channel's word about what it charged. */
    private function anApprovedInvoiceLine(string $amount, bool $approved = true): void
    {
        $invoiceId = DB::table('operational_cost_invoices')->insertGetId([
            'seller_id' => 0,
            'kind' => 'service',
            'party_type' => 'fulfillment_provider',
            'provider_code' => 'torod_point',
            'party_name' => 'Torod Point',
            'invoice_number' => 'INV-' . $amount . ($approved ? '' : '-draft'),
            'invoice_date' => '2026-11-30',
            'currency' => 'SAR',
            'total' => $amount,
            'status' => $approved ? 'approved' : 'draft',
            'source' => 'manual',
            'approved_at' => $approved ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('operational_cost_invoice_items')->insert([
            'invoice_id' => $invoiceId,
            'line_no' => 1,
            'dedupe_key' => 'line-' . $invoiceId,
            'cost_component' => CostComponent::Shipping->value,
            'amount' => $amount,
            'currency' => 'SAR',
            'service_date' => '2026-11-10',
            'match_status' => 'unmatched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A posted row, as the outbox leaves one once Odoo has reported the entry. */
    private function postedTo(\App\Models\OperationalCostEntry $entry, int $moveId): void
    {
        $row = app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);
        $row->forceFill(['state' => OdooPosting::POSTED, 'odoo_move_id' => $moveId, 'posted_at' => now()])->save();
    }

    /** @param list<array<string, mixed>> $lines */
    private function fakeOdooLines(array $lines): void
    {
        Http::fake([self::ODOO_OBJECT => Http::response($this->odooXml($lines))]);
    }
}
