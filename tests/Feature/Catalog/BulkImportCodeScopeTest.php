<?php

namespace Tests\Feature\Catalog;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Services\ProductService;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\RunsBulkImport;
use Tests\TestCase;

/**
 * A9, B1, B2: the bulk import generates a code free in the country the imported products take, and the tax rows of an import go to the
 * rows it just created, never to whichever products carry the generated code.
 */
class BulkImportCodeScopeTest extends TestCase
{
    use BuildsCodeScopeCatalog;
    use RestoresLanguageFiles;
    use RunsBulkImport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        $this->createImportTables();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- A9: the generated code ------------------------------------------------------------------------------------------------

    public function test_the_generated_code_is_not_one_that_any_country_holds_while_the_switch_is_off(): void
    {
        $this->insertCodedProduct(code: 'ABC123', country: 'KW');
        Str::createRandomStringsUsingSequence(['abc123', 'xyz789']);

        $this->assertSame('XYZ789', app(ProductService::class)->getUniqueProductSKUCode());
    }

    public function test_the_generated_code_may_be_one_that_another_country_holds_while_the_switch_is_on(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'ABC123', country: 'KW');
        $this->insertCodedProduct(code: 'DEF456', country: 'SA');
        Str::createRandomStringsUsingSequence(['def456', 'abc123']);

        $this->assertSame('ABC123', app(ProductService::class)->getUniqueProductSKUCode(), 'free where the import creates its products, and only a code held there is skipped');
    }

    // ---- B1, B2: the tax rows ---------------------------------------------------------------------------------------------------

    public function test_the_import_gives_each_new_product_the_taxes_of_its_own_row(): void
    {
        $this->runImport(['1,2', '2', '7']);

        $imported = DB::table('products')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(3, $imported);
        $this->assertSame([1, 2], $this->taxesOf($imported[0]));
        $this->assertSame([2], $this->taxesOf($imported[1]));
        $this->assertSame([], $this->taxesOf($imported[2]), 'a tax that does not exist is skipped, as before');
        $this->assertSame(['tax_for_orders'], DB::table('taxables')->distinct()->pluck('tax_for')->all());
        $this->assertSame(['App\Models\Product'], DB::table('taxables')->distinct()->pluck('taxable_type')->all());
        $this->assertSame([1], DB::table('taxables')->distinct()->pluck('system_tax_setup_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_tax_rows_never_reach_another_countrys_product_that_holds_the_generated_code(): void
    {
        $this->scopeCodesByCountry();
        $kuwaiti = $this->insertCodedProduct(code: 'ABC123', country: 'KW');
        DB::table('taxables')->insert(['taxable_type' => 'App\Models\Product', 'taxable_id' => $kuwaiti, 'system_tax_setup_id' => 1, 'tax_id' => 9, 'tax_for' => 'tax_for_orders']);
        $this->generateTheseCodes(['abc123']);

        $this->runImport(['1,2']);

        $imported = $this->idOfImported('ABC123');
        $this->assertGreaterThan(0, $imported, 'the import created its Saudi product with the code the Kuwaiti product also holds');
        $this->assertNotSame($kuwaiti, $imported);
        $this->assertSame([1, 2], $this->taxesOf($imported));
        $this->assertSame([9], $this->taxesOf($kuwaiti), 'the Kuwaiti product keeps exactly the tax it had');
    }

    public function test_the_taxes_follow_the_rows_whatever_their_generated_codes_are(): void
    {
        $this->generateTheseCodes(['abc123', 'abc123']);

        $this->runImport(['1,2', '2']);

        $imported = DB::table('products')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(2, $imported, 'two rows that were given one code are still two rows: the code is only a label');
        $this->assertSame([1, 2], $this->taxesOf($imported[0]));
        $this->assertSame([2], $this->taxesOf($imported[1]));
    }

    public function test_the_import_inserts_nothing_for_a_product_when_the_sheet_names_no_valid_tax(): void
    {
        $this->runImport(['7']);

        $this->assertSame(0, DB::table('taxables')->count());
        $this->assertSame(1, DB::table('products')->count());
    }

    // ---- the repository --------------------------------------------------------------------------------------------------------

    public function test_the_rows_are_added_and_their_ids_come_back_in_the_order_given(): void
    {
        $repository = app(ProductRepositoryInterface::class);

        $ids = $repository->addArrayAndGetIds(data: [
            ['name' => 'First', 'code' => 'AAA111', 'created_at' => now()],
            ['name' => 'Second', 'code' => 'BBB222', 'created_at' => now()],
        ]);

        $this->assertCount(2, $ids);
        $this->assertSame(['First', 'Second'], array_map(fn (int $id): string => (string) DB::table('products')->where('id', $id)->value('name'), $ids));
    }

    public function test_no_row_is_added_when_one_of_them_cannot_be(): void
    {
        $repository = app(ProductRepositoryInterface::class);

        try {
            $repository->addArrayAndGetIds(data: [
                ['name' => 'First', 'code' => 'AAA111'],
                ['name' => 'Second', 'no_such_column' => 'x'],
            ]);
            $this->fail('the second row has no column to go to');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, DB::table('products')->count(), 'all or nothing, as the single insert was');
        }
    }
}
