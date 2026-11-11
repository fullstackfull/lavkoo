<?php

namespace Tests\Feature\Accounting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The uniqueness the Odoo tables depend on — tested against the engine, because it is a property OF the engine.
 *
 * **A unique index does not constrain NULLs.** In MySQL, MariaDB, SQLite and Postgres alike, NULL is not equal to NULL, so every
 * row carrying one satisfies the index. An earlier version of these tables made `sales_channel`, `currency` and
 * `odoo_company_id` nullable and relied on a unique index over them, which meant:
 *
 *  - the GENERAL account mapping — the one that applies to every channel and every currency, and therefore the one most worth
 *    protecting — could be inserted any number of times, each copy naming a different Odoo account for the same fact. Which one
 *    won would depend on row order;
 *  - a reconciliation row for "every company, every channel" could be written once per run, and the three-way report would show
 *    the same period several times over with different figures.
 *
 * So "any" is spelled with a value the index can compare: `''` for a channel or currency, `0` for a company id (Odoo numbers its
 * own from 1). These tests prove the constraint actually bites, and they run on MySQL 8 and MariaDB 11.4.2 in CI as well as on
 * SQLite — the whole point being that the code agreeing with itself proves nothing here.
 */
class OdooUniquenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_15_100000_create_odoo_accounting_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('odoo_reconciliations');
        Schema::dropIfExists('odoo_posting_queue');
        Schema::dropIfExists('odoo_account_mappings');
        parent::tearDown();
    }

    // ---- the general mapping is single ---------------------------------------------------------------------------

    public function test_the_general_mapping_for_a_fact_cannot_be_written_twice(): void
    {
        // The case the nullable columns let through. Both rows mean "the commission account, for every channel and every
        // currency", and they name DIFFERENT Odoo accounts: two truths about one fact, with nothing to say which applies.
        $this->insertMapping(account: 40001);

        $this->expectException(QueryException::class);
        $this->insertMapping(account: 59999);
    }

    public function test_the_same_fact_narrowed_to_a_channel_is_a_different_mapping(): void
    {
        // The feature the sentinel must not break: a general mapping and a channel-specific one coexist, and the narrowest wins
        // at lookup time.
        $this->insertMapping(account: 40001);
        $this->insertMapping(account: 40002, channel: 'noon');
        $this->insertMapping(account: 40003, channel: 'amazon');

        $this->assertSame(3, DB::table('odoo_account_mappings')->count());
    }

    public function test_narrowing_by_currency_is_also_a_different_mapping(): void
    {
        $this->insertMapping(account: 40001);
        $this->insertMapping(account: 40004, currency: 'SAR');

        $this->assertSame(2, DB::table('odoo_account_mappings')->count());
    }

    public function test_a_channel_specific_mapping_cannot_be_written_twice_either(): void
    {
        $this->insertMapping(account: 40002, channel: 'noon');

        $this->expectException(QueryException::class);
        $this->insertMapping(account: 40005, channel: 'noon');
    }

    public function test_two_different_facts_are_two_mappings(): void
    {
        $this->insertMapping(account: 40001, factCode: 'marketplace_commission');
        $this->insertMapping(account: 50001, factCode: 'shipping');

        $this->assertSame(2, DB::table('odoo_account_mappings')->count());
    }

    public function test_the_same_fact_in_another_odoo_company_is_its_own_mapping(): void
    {
        // A chart of accounts belongs to a company, so the same fact maps to a different account in each.
        $this->insertMapping(account: 40001, company: 1);
        $this->insertMapping(account: 70001, company: 2);

        $this->assertSame(2, DB::table('odoo_account_mappings')->count());
    }

    public function test_the_channel_column_holds_an_empty_string_rather_than_a_null(): void
    {
        // Stated as its own test so a later migration cannot quietly make it nullable again and pass everything above.
        $this->insertMapping(account: 40001);

        $row = DB::table('odoo_account_mappings')->first();
        $this->assertSame('', $row->sales_channel);
        $this->assertSame('', $row->currency);
        $this->assertNotNull($row->sales_channel);
    }

    // ---- a reconciliation row is single per period -----------------------------------------------------------------

    public function test_the_general_reconciliation_row_for_a_period_cannot_be_written_twice(): void
    {
        $this->insertReconciliation(ledger: '1200.00');

        $this->expectException(QueryException::class);
        $this->insertReconciliation(ledger: '1500.00');
    }

    public function test_a_reconciliation_row_per_channel_sits_beside_the_general_one(): void
    {
        $this->insertReconciliation(ledger: '1200.00');
        $this->insertReconciliation(ledger: '400.00', channel: 'noon');
        $this->insertReconciliation(ledger: '800.00', channel: 'amazon');

        $this->assertSame(3, DB::table('odoo_reconciliations')->count());
    }

    public function test_another_period_is_another_row(): void
    {
        $this->insertReconciliation(ledger: '1200.00');
        $this->insertReconciliation(ledger: '1300.00', start: '2026-12-01', end: '2026-12-31');

        $this->assertSame(2, DB::table('odoo_reconciliations')->count());
    }

    public function test_another_fact_kind_in_the_same_period_is_another_row(): void
    {
        $this->insertReconciliation(ledger: '1200.00', factKind: 'channel_fee');
        $this->insertReconciliation(ledger: '9000.00', factKind: 'sale');

        $this->assertSame(2, DB::table('odoo_reconciliations')->count());
    }

    public function test_the_company_column_holds_zero_rather_than_a_null(): void
    {
        $this->insertReconciliation(ledger: '1200.00');

        $row = DB::table('odoo_reconciliations')->first();
        $this->assertSame(0, (int) $row->odoo_company_id);
        $this->assertNotNull($row->odoo_company_id);
    }

    // ---- the posting queue's own key, which is what stops a duplicate entry ----------------------------------------

    public function test_the_same_fact_at_the_same_version_cannot_be_queued_twice(): void
    {
        // The first of the three defences against a duplicate journal entry. All three columns are NOT NULL, so this index has
        // always bitten -- this test is here so it keeps doing so.
        $this->insertQueued(version: 'v1');

        $this->expectException(QueryException::class);
        $this->insertQueued(version: 'v1');
    }

    public function test_a_new_version_of_the_same_fact_is_a_new_row(): void
    {
        // A corrected figure is a new version and therefore a new entry, by reversal. It must not collide with the first.
        $this->insertQueued(version: 'v1');
        $this->insertQueued(version: 'v2');

        $this->assertSame(2, DB::table('odoo_posting_queue')->count());
    }

    public function test_two_different_entries_are_two_rows(): void
    {
        $this->insertQueued(sourceId: 7);
        $this->insertQueued(sourceId: 8);

        $this->assertSame(2, DB::table('odoo_posting_queue')->count());
    }

    public function test_a_queue_row_starts_pending_with_nothing_posted(): void
    {
        $this->insertQueued();

        $row = DB::table('odoo_posting_queue')->first();
        $this->assertSame('pending', $row->state);
        $this->assertNull($row->odoo_move_id, 'a row with no move id was never posted');
        $this->assertSame(0, (int) $row->attempts);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function insertMapping(
        int $account,
        string $channel = '',
        string $currency = '',
        int $company = 1,
        string $factCode = 'marketplace_commission',
    ): void {
        DB::table('odoo_account_mappings')->insert([
            'seller_id' => 0,
            'odoo_company_id' => $company,
            'fact_kind' => 'cost_component',
            'fact_code' => $factCode,
            'sales_channel' => $channel,
            'currency' => $currency,
            'odoo_account_id' => $account,
            'odoo_journal_id' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReconciliation(
        string $ledger,
        string $channel = '',
        int $company = 0,
        string $start = '2026-11-01',
        string $end = '2026-11-30',
        string $factKind = 'channel_fee',
    ): void {
        DB::table('odoo_reconciliations')->insert([
            'seller_id' => 0,
            'odoo_company_id' => $company,
            'sales_channel' => $channel,
            'period_start' => $start,
            'period_end' => $end,
            'currency' => 'SAR',
            'fact_kind' => $factKind,
            'ledger_amount' => $ledger,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertQueued(int $sourceId = 7, string $version = 'v1'): void
    {
        DB::table('odoo_posting_queue')->insert([
            'seller_id' => 0,
            'source_type' => 'operational_cost_entry',
            'source_id' => $sourceId,
            'source_version' => $version,
            'odoo_ref' => 'PKSA-COST-' . $sourceId . '-' . $version,
            'state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
