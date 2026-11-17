<?php

namespace Tests\Feature\Catalog;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Controllers\Admin\Product\ProductController;
use App\Services\ProductService;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rap2hpoutre\FastExcel\FastExcel;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A9, B1, B2: the bulk import generates a code free in the country the imported products take, and the tax rows of an import go to the
 * rows it just created, never to whichever products carry the generated code.
 */
class BulkImportCodeScopeTest extends TestCase
{
    use BuildsCodeScopeCatalog;
    use RestoresLanguageFiles;

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

    private function createImportTables(): void
    {
        foreach (['shops', 'system_tax_setups', 'taxes', 'taxables'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
        });
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);
        Schema::create('system_tax_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(true);
        });
        DB::table('system_tax_setups')->insert(['id' => 1]);
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
        });
        DB::table('taxes')->insert([['id' => 1], ['id' => 2], ['id' => 9]]);
        Schema::create('taxables', function (Blueprint $table) {
            $table->id();
            $table->string('taxable_type');
            $table->unsignedBigInteger('taxable_id');
            $table->unsignedBigInteger('system_tax_setup_id')->nullable();
            $table->unsignedBigInteger('tax_id');
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });
    }

    /** @param list<string> $taxIds one entry per sheet row, such as '1,2' */
    private function importRequest(array $taxIds): Request
    {
        $rows = array_map(static fn (string $taxes, int $index): array => [
            'name' => 'Imported ' . ($index + 1), 'category_id' => 1, 'sub_category_id' => 2, 'sub_sub_category_id' => 3, 'brand_id' => 4, 'unit' => 'pc',
            'minimum_order_qty' => 1, 'status' => 1, 'refundable' => 0, 'youtube_video_url' => 'u', 'unit_price' => 10, 'tax_ids' => $taxes, 'discount' => 0,
            'discount_type' => 'flat', 'current_stock' => 5, 'details' => 'd', 'thumbnail' => 'p/t.webp',
        ], $taxIds, array_keys($taxIds));
        $path = sys_get_temp_dir() . '/bulk_import_' . getmypid() . '.xlsx';
        (new FastExcel(collect($rows)))->export($path);

        return Request::create('/admin/products/bulk-import', 'POST', [], [], ['products_file' => new UploadedFile($path, 'products.xlsx', null, null, true)]);
    }

    /**
     * The random strings of six letters the import asks for are, in turn, the generated code of a row and the tail of its slug: the codes are
     * the given ones, in order, and the slug tails and every other random string are filler.
     *
     * @param list<string> $codes
     */
    private function generateTheseCodes(array $codes): void
    {
        $asked = 0;
        Str::createRandomStringsUsing(static function (int $length) use ($codes, &$asked): string {
            if ($length !== 6) {
                return str_repeat('r', $length);
            }

            return $asked++ % 2 === 0 ? ($codes[intdiv($asked - 1, 2)] ?? 'zzzzzz') : 'slugtl';
        });
    }

    private function runImport(array $taxIds): void
    {
        app(ProductController::class)->importBulkProduct($this->importRequest($taxIds), app(ProductService::class));
    }

    /** @return list<int> */
    private function taxesOf(int $productId): array
    {
        return DB::table('taxables')->where('taxable_id', $productId)->orderBy('tax_id')->pluck('tax_id')->map(fn ($id) => (int) $id)->all();
    }

    private function idOfImported(string $code): int
    {
        return (int) DB::table('products')->where('code', $code)->where('storefront_country', 'SA')->value('id');
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
