<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeIndex;
use App\Services\Catalog\ProductCodeIndexBlocked;
use App\Services\Catalog\ProductCodeReadiness;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\QueryException;
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
