<?php

namespace Tests\Feature\Catalog;

use App\Exports\ProductListExport;
use App\Http\Controllers\Admin\Product\ProductController as AdminProductController;
use App\Models\Seller;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Sheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C3: the admin product export lists the products of every country, so while the switch is on it ends with a country column (after the last column
 * the sheet already had, so no column moves). The seller's export lists the seller's own products, which are all in the table default country, and
 * is not touched. With the switch off the sheet is what it was.
 */
class ProductExportCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsProductCatalog, RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCatalogTables();
        Schema::table('products', function ($table) {
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
        });
        $this->addStorefrontCountryColumn();
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'system_default_currency', 'value' => '1'],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        Cache::flush();
        $this->insertProduct(id: 1, name: 'Serum', extra: ['code' => 'SKU100', 'storefront_country' => 'KW']);
        $this->insertProduct(id: 2, name: 'Serum', extra: ['code' => 'SKU100', 'storefront_country' => 'SA']);
        Excel::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function taxIs(bool $productWise): void
    {
        if ($productWise) {
            foreach (['taxables', 'taxes'] as $table) {
                Schema::dropIfExists($table);
            }
            Schema::create('taxables', function ($table) {
                $table->id();
                $table->string('taxable_type');
                $table->unsignedBigInteger('taxable_id');
                $table->unsignedBigInteger('tax_id')->nullable();
            });
            Schema::create('taxes', function ($table) {
                $table->id();
                $table->boolean('is_active')->default(true);
            });
        }
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => ['tax_ids' => [], 'additionalData' => [], 'tax_type' => 'order_wise'],
            'SystemTaxVatType' => 'order_wise', 'is_included' => 0, 'productWiseTax' => $productWise, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
    }

    /** The sheet the admin panel hands to the Excel writer for the in-house list. */
    private function adminExport(): ProductListExport
    {
        $request = Request::create('/admin/products/export-excel/in-house', 'GET');
        $this->app->instance('request', $request);

        app(AdminProductController::class)->exportList($request, 'in-house');
        $export = null;
        Excel::assertDownloaded('In-house-product-list.xlsx', static function (ProductListExport $given) use (&$export): bool {
            $export = $given;

            return true;
        });

        return $export;
    }

    /** @return list<list<string>> the text of the cells of every row of the sheet's table */
    private function rowsOf(ProductListExport $export): array
    {
        $html = $export->view()->render();
        preg_match_all('/<tr>(.*?)<\/tr>/s', $html, $rows);

        return array_map(static function (string $row): array {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/s', $row, $cells);

            return array_map(static fn (string $cell): string => trim(html_entity_decode(strip_tags($cell))), $cells[1]);
        }, $rows[1]);
    }

    // ---- the admin export --------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_sheet_has_no_country_column(): void
    {
        $this->taxIs(productWise: false);

        $rows = $this->rowsOf($this->adminExport());

        $this->assertCount(18, $rows[2], 'the header row of today');
        $this->assertNotContains('Country', $rows[2]);
        $this->assertCount(18, $rows[3]);
    }

    public function test_with_the_switch_on_the_sheet_ends_with_the_country_of_each_product(): void
    {
        $this->taxIs(productWise: false);
        $off = $this->rowsOf($this->adminExport());

        $this->scopeCodesByCountry();
        $on = $this->rowsOf($this->adminExport());

        $this->assertSame('Country', end($on[2]), 'the header');
        $this->assertSame(['SA', 'KW'], array_map(static fn (array $row): string => end($row), array_slice($on, 3)), 'one country per product row, the newest product first as the list sorts');
        foreach ($on as $index => $row) {
            if ($index >= 2) {
                $this->assertSame($off[$index], array_slice($row, 0, -1), 'every column that was there is where it was');
            }
        }
    }

    public function test_the_country_follows_the_tax_column_when_there_is_one(): void
    {
        $this->taxIs(productWise: true);
        $this->scopeCodesByCountry();

        $rows = $this->rowsOf($this->adminExport());

        $this->assertSame(['Vat/Tax', 'Country'], array_slice($rows[2], -2));
        $this->assertSame('SA', end($rows[3]));
    }

    public function test_a_missing_column_gives_the_old_sheet(): void
    {
        $this->taxIs(productWise: false);
        $off = $this->rowsOf($this->adminExport());
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame($off, $this->rowsOf($this->adminExport()));
    }

    // ---- the layout of the sheet -------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: bool, 1: bool, 2: string}> the country column, the tax column, the last column of the sheet */
    public static function lastColumns(): array
    {
        return [
            'as it was' => [false, false, 'S'],
            'as it was, with the tax column' => [false, true, 'S'],
            'a country in the column that was spare' => [true, false, 'S'],
            'a country after the tax column' => [true, true, 'T'],
        ];
    }

    #[DataProvider('lastColumns')]
    public function test_the_styles_cover_the_last_column_of_the_sheet(bool $country, bool $tax, string $last): void
    {
        $export = new ProductListExport(['products' => collect([1, 2]), 'type' => 'in_house', 'productWiseTax' => $tax, 'countryColumn' => $country]);

        $sheet = (new Spreadsheet())->getActiveSheet();
        $next = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($last) + 1);

        $styles = $export->styles($sheet);

        $this->assertSame(["A1:{$last}5"], array_keys($styles));
        $this->assertSame('063C93', $sheet->getStyle("{$last}3")->getFill()->getStartColor()->getRGB(), 'the header fill reaches the last column');
        $this->assertTrue($sheet->getStyle("{$last}3")->getFont()->getBold(), 'and so does the bold header');
        $this->assertSame(Fill::FILL_NONE, $sheet->getStyle("{$next}3")->getFill()->getFillType(), 'the fill stops there');
        $this->assertFalse($sheet->getStyle("{$next}3")->getFont()->getBold());
    }

    #[DataProvider('lastColumns')]
    public function test_the_title_and_the_filter_rows_are_merged_up_to_the_last_column_of_the_sheet(bool $country, bool $tax, string $last): void
    {
        $export = new ProductListExport(['products' => collect(), 'type' => 'in_house', 'productWiseTax' => $tax, 'countryColumn' => $country]);
        $worksheet = (new Spreadsheet())->getActiveSheet();

        $export->registerEvents()[AfterSheet::class](new AfterSheet(new Sheet($worksheet), $export));
        $next = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($last) + 1);

        $this->assertSame(["A1:{$last}1", 'A2:B2', "C2:{$last}2", "D2:{$last}2", 'F3:G3'], array_keys($worksheet->getMergeCells()));
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $worksheet->getStyle("{$last}1")->getAlignment()->getHorizontal(), 'the title is centred over the last column');
        $this->assertSame(Alignment::HORIZONTAL_LEFT, $worksheet->getStyle("{$last}2")->getAlignment()->getHorizontal(), 'the filters are left aligned over it');
        $this->assertSame(Alignment::HORIZONTAL_CENTER, $worksheet->getStyle("{$last}3")->getAlignment()->getHorizontal(), 'the header is centred over it');
        $this->assertSame(Alignment::HORIZONTAL_GENERAL, $worksheet->getStyle("{$next}1")->getAlignment()->getHorizontal(), 'nothing is aligned past it');
    }

    // ---- the seller's export -----------------------------------------------------------------------------------------------------

    private function vendorSheet(): ProductListExport
    {
        DB::table('products')->update(['added_by' => 'seller', 'user_id' => 5]);
        $this->actingAs((new Seller())->forceFill(['id' => 5]), 'seller');
        $request = Request::create('/vendor/products/export-excel/all', 'GET');
        $this->app->instance('request', $request);
        $this->taxIs(productWise: false);

        app(\App\Http\Controllers\Vendor\Product\ProductController::class)->exportList($request, 'all');
        $export = null;
        Excel::assertDownloaded('All-product-list.xlsx', static function (ProductListExport $given) use (&$export): bool {
            $export = $given;

            return true;
        });

        return $export;
    }

    public function test_the_sellers_own_export_is_the_same_with_the_switch_on_and_off(): void
    {
        $off = $this->rowsOf($this->vendorSheet());
        $this->scopeCodesByCountry();

        $this->assertSame($off, $this->rowsOf($this->vendorSheet()));
        $this->assertNotContains('Country', $off[2]);
    }
}
