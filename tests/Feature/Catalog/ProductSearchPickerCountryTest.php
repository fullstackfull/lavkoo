<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\Admin\OperationalCosts\ProductCostController;
use App\Http\Controllers\Admin\SalesChannels\ChannelProductController;
use App\Http\Controllers\Admin\SalesChannels\Support\ProductDirectory;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * D7, F1: the pickers of the listing-link field (and of the routing rule that shares it) and of the cost-layer form answer JSON that a script turns
 * into buttons. They offer the products of every country, because a channel link and a cost layer are saved by the picked id, so while the switch
 * is on every row also carries the label of its country (` · KW`) for the script to append. With the switch off, or before the column exists, the
 * rows are exactly what they were.
 */
class ProductSearchPickerCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->insertCodedProduct(code: 'SKU100', country: 'KW', barcode: '6291234567890', extra: ['name' => 'Serum']);
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890', extra: ['name' => 'Serum']);
        $this->insertCodedProduct(code: 'SKU900', country: 'SA', extra: ['name' => 'A seller product', 'added_by' => 'seller']);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function pickers(): array
    {
        return ['the listing-link picker' => ['link'], 'the cost-layer picker' => ['cost']];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $picker, string $term = 'Serum'): array
    {
        $request = Request::create('/admin/search', 'GET', ['q' => $term]);
        $this->app->instance('request', $request);
        $controller = $picker === 'link' ? ChannelProductController::class : ProductCostController::class;

        return app($controller)->productSearch($request)->getData(true);
    }

    #[DataProvider('pickers')]
    public function test_with_the_switch_off_the_rows_are_the_old_ones(string $picker): void
    {
        $rows = $this->rows($picker);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['id', 'name', 'code', 'barcode'], array_keys($row));
        }
    }

    #[DataProvider('pickers')]
    public function test_with_the_switch_on_each_row_carries_the_label_of_its_country_and_keeps_its_columns(string $picker): void
    {
        $this->scopeCodesByCountry();

        $rows = $this->rows($picker);

        $this->assertSame([' · KW', ' · SA'], array_column($rows, 'country_tag'));
        foreach ($rows as $row) {
            $this->assertSame(['id', 'name', 'code', 'barcode', 'country_tag'], array_keys($row), 'the country column itself is not handed to the script');
        }
    }

    #[DataProvider('pickers')]
    public function test_the_switch_changes_nothing_but_the_label(string $picker): void
    {
        $off = $this->rows($picker);
        $this->scopeCodesByCountry();
        $on = $this->rows($picker);

        $this->assertSame($off, array_map(static fn (array $row): array => array_diff_key($row, ['country_tag' => 1]), $on));
    }

    #[DataProvider('pickers')]
    public function test_a_missing_column_gives_the_old_rows_and_no_error(string $picker): void
    {
        $off = $this->rows($picker);
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame($off, $this->rows($picker));
    }

    #[DataProvider('pickers')]
    public function test_a_product_of_no_known_country_gets_an_empty_label(string $picker): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->where('code', 'SKU100')->where('storefront_country', 'KW')->update(['storefront_country' => '']);

        $this->assertSame(['', ' · SA'], array_column($this->rows($picker), 'country_tag'));
    }

    public function test_the_directory_that_serves_the_listing_picker_tags_its_rows_the_same_way(): void
    {
        $this->scopeCodesByCountry();

        $rows = app(ProductDirectory::class)->search('Serum');

        $this->assertSame([' · KW', ' · SA'], $rows->pluck('country_tag')->all());
    }

    public function test_the_scripts_of_both_pickers_append_the_label_as_text(): void
    {
        $linkScript = (string) file_get_contents(resource_path('views/admin-views/sales-channels/partials/_product-picker-script.blade.php'));
        $costPage = (string) file_get_contents(resource_path('views/admin-views/operational-costs/product-costs/index.blade.php'));

        $this->assertStringContainsString("choice.textContent = '#' + product.id + ' ' + product.name + (product.code ? ' (' + product.code + ')' : '') + (product.country_tag || '');", $linkScript, 'a row of the listing-link picker');
        $this->assertStringContainsString("button.textContent = '#' + product.id + ' ' + product.name + (product.code ? ' · ' + product.code : '') + (product.country_tag || '');", $costPage, 'a row of the cost-layer picker');
        $this->assertStringContainsString("chosen.textContent = product.name + (product.country_tag || '');", $costPage, 'the product the cost form chose');
        $this->assertStringNotContainsString('innerHTML', $linkScript . $costPage, 'the label is text, never markup');
    }

    // ---- the two helpers of the scope ------------------------------------------------------------------------------------------

    public function test_the_columns_of_a_picker_gain_the_country_only_while_the_scope_applies(): void
    {
        $scope = app(ProductCodeScope::class);

        $this->assertSame(['id', 'name'], $scope->pickerColumns(['id', 'name']));

        $this->scopeCodesByCountry();
        $this->assertSame(['id', 'name', 'storefront_country'], $scope->pickerColumns(['id', 'name']));

        $this->dropStorefrontCountryColumn();
        $this->assertSame(['id', 'name'], $scope->pickerColumns(['id', 'name']));
    }

    public function test_rows_are_returned_as_they_are_while_the_scope_does_not_apply(): void
    {
        $rows = new Collection([(object) ['id' => 1, 'storefront_country' => 'KW']]);

        $this->assertSame($rows, app(ProductCodeScope::class)->tagPickerRows($rows));
    }
}
