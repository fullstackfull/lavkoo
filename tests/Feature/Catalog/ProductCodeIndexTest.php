<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeIndex;
use App\Services\Catalog\ProductCodeIndexBlocked;
use App\Services\Catalog\ProductCodeReadiness;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * B-04: the DATABASE refuses two products of one country that share a code or a barcode — a constraint, not a validator, so a path that
 * never reaches a form cannot create the pair either.
 *
 * The empty case is first on purpose. Most of a real catalogue has no barcode, and a plain unique index would read every one of those
 * rows as the same product; the rule is that uniqueness holds among non-empty values only, and these tests are what says so.
 */
class ProductCodeIndexTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        // The index migration is gated on the switch, so every test that applies it through the migration turns the switch on first.
        $this->scopeCodesByCountry();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- the absent code, first --------------------------------------------------------------------------------------------------

    public function test_many_products_of_one_country_may_have_no_code_and_no_barcode_at_all(): void
    {
        $this->applyIndexes();

        $this->insertRow(code: null, barcode: null);
        $this->insertRow(code: null, barcode: null);
        $this->insertRow(code: null, barcode: null);

        $this->assertSame(3, DB::table('products')->count());
    }

    public function test_many_products_of_one_country_may_have_an_empty_code_and_an_empty_barcode(): void
    {
        $this->applyIndexes();

        $this->insertRow(code: '', barcode: '');
        $this->insertRow(code: '', barcode: '');

        $this->assertSame(2, DB::table('products')->count());
    }

    public function test_an_empty_code_and_a_missing_one_do_not_collide_with_each_other(): void
    {
        $this->applyIndexes();

        $this->insertRow(code: '', barcode: null);
        $this->insertRow(code: null, barcode: '');

        $this->assertSame(2, DB::table('products')->count());
    }

    public function test_a_catalogue_whose_codes_are_all_empty_can_still_take_the_index(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->insertRow(code: '', barcode: null);
        }

        $this->assertSame(0, ProductCodeIndex::blockers('code'));
        $this->applyIndexes();

        $this->assertTrue(ProductCodeIndex::applied('code'));
    }

    // ---- the same code inside one country, and across two ------------------------------------------------------------------------

    public function test_the_database_refuses_two_products_of_one_country_with_the_same_code(): void
    {
        $this->applyIndexes();
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->expectException(QueryException::class);
        $this->insertRow(code: 'SKU-1', country: 'SA');
    }

    public function test_the_database_refuses_two_products_of_one_country_with_the_same_barcode(): void
    {
        $this->applyIndexes();
        $this->insertRow(code: 'SKU-1', barcode: '6281000', country: 'SA');

        $this->expectException(QueryException::class);
        $this->insertRow(code: 'SKU-2', barcode: '6281000', country: 'SA');
    }

    public function test_two_countries_may_hold_the_same_code_and_the_same_barcode(): void
    {
        $this->applyIndexes();

        $this->insertRow(code: 'SKU-1', barcode: '6281000', country: 'SA');
        $this->insertRow(code: 'SKU-1', barcode: '6281000', country: 'KW');

        $this->assertSame(2, DB::table('products')->count());
    }

    public function test_the_code_index_and_the_barcode_index_do_not_constrain_each_other(): void
    {
        $this->applyIndexes();

        $this->insertRow(code: '6281000', barcode: null, country: 'SA');
        $this->insertRow(code: 'SKU-2', barcode: '6281000', country: 'SA');

        $this->assertSame(2, DB::table('products')->count());
    }

    // ---- the guard: live data the index would reject -----------------------------------------------------------------------------

    public function test_the_check_counts_the_groups_the_index_would_refuse(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-2', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'KW');

        $this->assertSame(1, ProductCodeIndex::blockers('code'));
    }

    public function test_the_index_is_refused_rather_than_applied_when_the_data_holds_a_duplicate(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'SA');

        try {
            ProductCodeIndex::create(kind: 'code');
            $this->fail('the index was created over data that holds the same code twice in one country');
        } catch (ProductCodeIndexBlocked $blocked) {
            $this->assertSame(1, $blocked->groups);
            $this->assertStringContainsString('gcc:catalog:code-readiness', $blocked->getMessage());
        }

        $this->assertFalse(ProductCodeIndex::applied('code'));
        $this->assertSame(2, DB::table('products')->count(), 'no row may be deleted or merged to make an index fit');
    }

    /**
     * The migration is gated because `php artisan migrate` is this project's upgrade step. Measured on MariaDB 10.11.14 with the gate off and
     * one in-country duplicate: migrate exits 1, nothing is recorded in the migrations table, and a later pending migration never runs. A
     * branch that ships dormant must not be able to stop an upgrade that way.
     */
    public function test_the_migration_creates_nothing_while_the_switch_is_off(): void
    {
        $this->scopeCodesByCountry(on: false);

        $this->runIndexMigration();

        $this->assertFalse(ProductCodeIndex::applied('code'));
        $this->assertFalse(ProductCodeIndex::applied('barcode'));
    }

    public function test_the_migration_does_not_abort_an_upgrade_when_a_country_holds_a_code_twice(): void
    {
        $this->scopeCodesByCountry(on: false);
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->runIndexMigration();

        $this->assertFalse(ProductCodeIndex::applied('code'), 'nothing is applied, and nothing is thrown at the migrator either');
        $this->assertSame(2, DB::table('products')->count());
    }

    public function test_the_command_applies_the_indexes_the_catalogue_accepts(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->artisan('gcc:catalog:code-readiness', ['--apply' => true])
            ->expectsOutputToContain('code: applied.')
            ->assertExitCode(0);

        $this->assertTrue(ProductCodeIndex::applied('code'));
        $this->assertTrue(ProductCodeIndex::applied('barcode'));
    }

    /**
     * The exit code alone does NOT prove the refusal. Measured on this head with the command's refusal taken out (the catch kept going
     * instead of returning false): the command still exits 1, because the readiness verdict is Not ready on its own, and this test was
     * green. So it names the refusal itself — the message, the absent index, and the absent generated column the MySQL and MariaDB form
     * of the index needs, which is the artifact a half-applied index leaves on a live table.
     */
    public function test_the_command_refuses_to_apply_an_index_the_catalogue_blocks_and_changes_nothing(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->artisan('gcc:catalog:code-readiness', ['--apply' => true])
            ->expectsOutputToContain('1 code(s) are held more than once inside one country')
            ->doesntExpectOutputToContain('code: applied.')
            ->assertExitCode(1);

        $this->assertIndexAndItsColumnAreAbsent('code');
        $this->assertSame(2, DB::table('products')->count(), 'no row may be deleted or merged to make an index fit');
    }

    // ---- --apply is behind the same switch the migration is behind ----------------------------------------------------------------

    /**
     * `--apply` is the ONLY path that applies the index on an installation that already migrated with the switch off, so an ungated
     * `--apply` is the hole in the migration's gate. Measured on the previous head of this branch, with the switch off and one clean
     * product: the command exited 0 and created both `products_country_code_unique` and `products_country_barcode_unique`.
     */
    public function test_apply_refuses_and_creates_nothing_while_the_switch_is_off(): void
    {
        $this->scopeCodesByCountry(on: false);
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->artisan('gcc:catalog:code-readiness', ['--apply' => true])
            ->expectsOutputToContain('Refusing to apply: country-scoped codes are off')
            ->doesntExpectOutputToContain('code: applied.')
            ->assertExitCode(1);

        $this->assertIndexAndItsColumnAreAbsent('code');
        $this->assertIndexAndItsColumnAreAbsent('barcode');
    }

    /** The report must not send an operator to an apply the command refuses, which is where it sent them to an ungated one. */
    public function test_the_report_does_not_invite_apply_while_the_switch_is_off(): void
    {
        $this->scopeCodesByCountry(on: false);
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->artisan('gcc:catalog:code-readiness')
            ->expectsOutputToContain('the switch is off, so --apply refuses')
            ->doesntExpectOutputToContain('run this command again with --apply')
            ->assertExitCode(0);
    }

    /**
     * A product with an empty country makes the report say Not ready, and the index would accept it all the same: the rows of one empty
     * country are compared only with each other. Measured on the previous head: the command created the index and then printed "Not
     * ready" and exited 1 in the same run.
     */
    public function test_apply_refuses_while_a_product_has_an_empty_country(): void
    {
        $this->insertRow(code: 'SKU-1', country: '');

        $this->artisan('gcc:catalog:code-readiness', ['--apply' => true])
            ->expectsOutputToContain('Refusing to apply: 1 product(s) have an empty country')
            ->doesntExpectOutputToContain('code: applied.')
            ->assertExitCode(1);

        $this->assertIndexAndItsColumnAreAbsent('code');
        $this->assertSame(1, DB::table('products')->count(), 'no row may be given a country to make an index fit');
    }

    /**
     * `blockers()` counts, and then the index is built; a row that arrives in between is refused by the index itself, not by the count.
     * That is the only way the generated-column form of the index can half-apply, and it is a real one — an import, or a second operator.
     * The row here is inserted by a listener on the counting query itself, so the duplicate is NOT present when `create()` checks and IS
     * present when the engine builds the index: the refusal below is the database's own, not one this test arranged.
     *
     * With the column and the index as two statements the column stayed on the table when the index was refused. They are one
     * `ALTER TABLE` for that reason, and this is what says so.
     */
    public function test_a_row_that_arrives_after_the_count_is_refused_and_leaves_no_generated_column_behind(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('only the generated-column engines add a column and an index in one statement');
        }

        $this->insertRow(code: 'SKU-1', country: 'SA');
        $concurrentWriteDone = false;
        DB::listen(function (QueryExecuted $query) use (&$concurrentWriteDone): void {
            if ($concurrentWriteDone || !str_contains($query->sql, 'having count(*) > 1')) {
                return;
            }

            $concurrentWriteDone = true;
            $this->insertRow(code: 'SKU-1', country: 'SA');
        });

        try {
            ProductCodeIndex::create(kind: 'code');
            $this->fail('the index was created although one country held the same code twice by the time it was built');
        } catch (UniqueConstraintViolationException) {
            // the engine's own refusal: create() had counted zero blockers a moment earlier
        }

        $this->assertTrue($concurrentWriteDone, 'the race never happened, so this test proved nothing');
        $this->assertIndexAndItsColumnAreAbsent('code');
        $this->assertSame(2, DB::table('products')->count());
    }

    public function test_applying_twice_is_a_no_op(): void
    {
        $this->applyIndexes();

        $this->artisan('gcc:catalog:code-readiness', ['--apply' => true])
            ->expectsOutputToContain('code: already applied.')
            ->assertExitCode(0);

        $this->assertTrue(ProductCodeIndex::applied('code'));
    }

    public function test_the_migration_creates_nothing_before_the_country_column_exists(): void
    {
        $this->dropStorefrontCountryColumn();

        $this->runIndexMigration();

        $this->assertFalse(Schema::hasIndex('products', ProductCodeIndex::indexName('code')));
    }

    public function test_the_migration_is_a_no_op_when_the_indexes_are_already_there(): void
    {
        $this->applyIndexes();

        $this->runIndexMigration();

        $this->assertTrue(ProductCodeIndex::applied('code'));
        $this->assertTrue(ProductCodeIndex::applied('barcode'));
    }

    public function test_the_migration_can_be_rolled_back_and_applied_again(): void
    {
        $this->applyIndexes();
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->indexMigration()->down();
        $this->assertFalse(ProductCodeIndex::applied('code'));
        $this->insertRow(code: 'SKU-1', country: 'SA');

        DB::table('products')->orderByDesc('id')->limit(1)->delete();
        $this->runIndexMigration();
        $this->assertTrue(ProductCodeIndex::applied('code'));
    }

    public function test_each_engine_leaves_the_absent_values_out_the_way_that_engine_allows(): void
    {
        $this->applyIndexes();

        $generated = Schema::hasColumn('products', ProductCodeIndex::generatedColumn('code'));
        $this->assertSame(
            in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true),
            $generated,
            'MySQL and MariaDB have no partial index, so the index is laid over a generated column; the other engines write the rule directly',
        );
    }

    public function test_two_long_codes_that_agree_up_to_the_default_length_are_still_two_codes(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('only the generated-column engines can get the length of the indexed value wrong');
        }

        DB::statement('ALTER TABLE products MODIFY code VARCHAR(500) NULL');
        $this->applyIndexes();
        $shared = str_repeat('A', 200);

        $this->insertRow(code: $shared . '-ONE', country: 'SA');
        $this->insertRow(code: $shared . '-TWO', country: 'SA');

        $this->assertSame(2, DB::table('products')->count(), 'a generated column shorter than the stored one would read these as one code');
    }

    // ---- the read-only report ----------------------------------------------------------------------------------------------------

    public function test_the_readiness_report_says_whether_each_index_can_be_applied(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-3', barcode: null, country: 'SA');

        $report = app(ProductCodeReadiness::class)->report();
        $indexes = collect($report['indexes'])->keyBy('kind');

        $this->assertFalse($indexes['code']['applied']);
        $this->assertSame(1, $indexes['code']['blocking_groups']);
        $this->assertSame(0, $indexes['barcode']['blocking_groups']);
        $this->assertSame(3, $indexes['barcode']['absent_values'], 'the rows with no barcode are outside the index, so they never block it');
        $this->assertSame(ProductCodeIndex::indexName('code'), $indexes['code']['name']);
    }

    public function test_the_report_reads_the_database_without_creating_an_index(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');

        app(ProductCodeReadiness::class)->report();

        $this->assertFalse(ProductCodeIndex::applied('code'));
        $this->assertFalse(ProductCodeIndex::applied('barcode'));
    }

    public function test_the_command_names_each_index_and_what_blocks_it(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'SA');

        $this->artisan('gcc:catalog:code-readiness')
            ->expectsOutputToContain('not applied; 1 value(s) held twice inside one country block it')
            ->assertExitCode(1);
    }

    public function test_the_command_says_the_data_would_accept_an_index_that_is_not_there_yet(): void
    {
        $this->insertRow(code: 'SKU-1', country: 'SA');
        $this->insertRow(code: 'SKU-1', country: 'KW');

        $this->artisan('gcc:catalog:code-readiness')
            ->expectsOutputToContain('the data would accept it')
            ->assertExitCode(0);
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------

    /**
     * Nothing of the index is on the table: neither the index nor, on MySQL and MariaDB, the generated column it is laid over. The second
     * half is what separates "refused" from "half applied": the column outlives a failed index and is a real change to a live catalogue.
     */
    private function assertIndexAndItsColumnAreAbsent(string $kind): void
    {
        $this->assertFalse(Schema::hasIndex('products', ProductCodeIndex::indexName($kind)), "the {$kind} index was created");
        $this->assertFalse(ProductCodeIndex::applied($kind), "the {$kind} index reads as applied");
        $this->assertFalse(
            Schema::hasColumn('products', ProductCodeIndex::generatedColumn($kind)),
            "the generated column of the {$kind} index was left on the table",
        );
    }

    private function applyIndexes(): void
    {
        $this->runIndexMigration();

        $this->assertTrue(ProductCodeIndex::applied('code'));
        $this->assertTrue(ProductCodeIndex::applied('barcode'));
    }

    private function runIndexMigration(): void
    {
        $this->indexMigration()->up();
    }

    private function indexMigration(): object
    {
        return require database_path('migrations/2026_12_12_100000_add_country_unique_codes_to_products.php');
    }

    private function insertRow(?string $code = null, ?string $barcode = null, string $country = 'SA'): void
    {
        DB::table('products')->insert([
            'name' => 'Product ' . ($code ?? 'no code'),
            'code' => $code,
            'barcode' => $barcode,
            'storefront_country' => $country,
        ]);
    }
}
