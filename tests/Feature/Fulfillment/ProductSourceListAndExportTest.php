<?php

namespace Tests\Feature\Fulfillment;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\Fulfillment\ProviderCode;
use App\Exports\ProductListExport;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ProductSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use ReflectionMethod;
use Tests\Support\RestoresLanguageFiles;

/**
 * The admin product list and its export show and filter each product's execution source, beside its country (C-01).
 *
 * `products.fulfillment_provider` has decided who fulfils a product since the carriers were added, and no screen showed it: an operator
 * could see which country a product belongs to and had no way to see, or to ask for, the source it ships from.
 *
 * THREE answers, not two. In-house is an empty value, a carrier is its code -- and a stored value this installation does not know is its
 * own answer, `unknown`, shown as such. Printing "in house" for it would hide exactly the state C-01's first criterion is about (a
 * carrier taken out of the configuration while products still name it), and the panel is where an operator would find out.
 */
class ProductSourceListAndExportTest extends FulfillmentTestCase
{
    use RestoresLanguageFiles;

    private const UNKNOWN_CARRIER = 'dhl';

    private const MIGRATION = '2026_11_05_100100_add_fulfillment_provider_to_products_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildReviewsForTheExportView();
    }

    /**
     * The export prints each product's average rating and its tags, so the tables those come from have to exist. Both come from the
     * installation dump rather than from a migration, so absent IS their migrated state and dropping them is the restore.
     */
    private function buildReviewsForTheExportView(): void
    {
        Schema::dropIfExists('reviews');
        Schema::create('reviews', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('rating', 5, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('tags');
        Schema::create('tags', function ($table): void {
            $table->id();
            $table->string('tag')->nullable();
            $table->timestamps();
        });
        Schema::create('product_tag', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('tag_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        foreach (['product_tag', 'tags', 'reviews'] as $fromTheDump) {
            Schema::dropIfExists($fromTheDump);
        }

        parent::tearDown();
    }

    /**
     * Dormant before the column: the real migration is rolled back and run again, so the column the rest of this class reads is the one
     * production gets, index and all -- and so the table is put BACK for whatever class runs after this one in the same process.
     */
    public function test_with_no_source_column_nothing_is_shown_filtered_or_offered(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        $migration->down();
        FulfillmentSchema::flush();

        $this->assertFalse(ProductSource::isTracked());
        $this->assertSame([], ProductSource::options());
        $this->assertSame([], ProductSource::filterOf([ProductSource::IN_HOUSE]));
        $this->assertStringNotContainsString('fulfillment_source', $this->exportHtml(sourceColumn: false));

        $migration->up();
        FulfillmentSchema::flush();
        $this->assertTrue(Schema::hasColumn('products', ProductSource::COLUMN), 'and the column is put back for whatever runs next');
    }

    public function test_a_stored_value_this_installation_does_not_know_reads_as_unknown_and_never_as_in_house(): void
    {
        $this->assertSame(ProductSource::IN_HOUSE, ProductSource::of(null));
        $this->assertSame(ProductSource::IN_HOUSE, ProductSource::of(''));
        $this->assertSame(ProviderCode::TorodPoint->value, ProductSource::of(ProviderCode::TorodPoint->value));
        $this->assertSame(ProductSource::UNKNOWN, ProductSource::of(self::UNKNOWN_CARRIER));
    }

    /** The filter offers `unknown` only while a product actually names a carrier nobody knows, so it never offers an empty answer. */
    public function test_unknown_is_offered_only_while_a_product_names_a_carrier_nobody_knows(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);

        $withoutUnknown = ProductSource::options();
        $this->assertSame(ProductSource::IN_HOUSE, $withoutUnknown[0], 'in-house is the default, so it is offered first');
        $this->assertContains(ProviderCode::TorodPoint->value, $withoutUnknown);
        $this->assertNotContains(ProductSource::UNKNOWN, $withoutUnknown);

        $this->productNamingAnUnknownCarrier(2);

        $this->assertContains(ProductSource::UNKNOWN, ProductSource::options());
        $this->assertSame(ProductSource::UNKNOWN, array_slice(ProductSource::options(), -1)[0], 'and it is offered last');
    }

    /**
     * The repository is what the list screen and the export BOTH run, so the filter is proved once, where they share it.
     *
     * In-house is an empty value OR null -- a product that has never been assigned, and one that was assigned and cleared, are the same
     * answer and a plain `whereIn` would find neither.
     */
    public function test_the_list_and_the_export_can_be_narrowed_to_one_source(): void
    {
        $this->makeProduct(1);
        DB::table('products')->where('id', 1)->update([ProductSource::COLUMN => '']);
        $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->makeProduct(3, ProviderCode::M5azn);
        $this->productNamingAnUnknownCarrier(4);
        $this->makeProduct(5);

        $this->assertSame([1, 5], $this->listedWith([ProductSource::IN_HOUSE]), 'an empty value and a null are both in-house');
        $this->assertSame([2], $this->listedWith([ProviderCode::TorodPoint->value]));
        $this->assertSame([4], $this->listedWith([ProductSource::UNKNOWN]));
        $this->assertSame([2, 3], $this->listedWith([ProviderCode::TorodPoint->value, ProviderCode::M5azn->value]));
        $this->assertSame([1, 2, 5], $this->listedWith([ProductSource::IN_HOUSE, ProviderCode::TorodPoint->value]));
        $this->assertSame([1, 2, 3, 4, 5], $this->listedWith([]), 'no boxes ticked narrows nothing, as every other filter of this panel');
        $this->assertSame([1, 2, 3, 4, 5], $this->listedWith(['no_such_source']), 'a source this installation does not offer is not a filter');
    }

    public function test_the_export_carries_the_source_of_every_product_it_lists(): void
    {
        $this->makeProduct(1);
        $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->productNamingAnUnknownCarrier(3);

        $html = $this->exportHtml(sourceColumn: true);

        $this->assertStringContainsString(ProductSource::label(ProductSource::IN_HOUSE), $html);
        $this->assertStringContainsString(ProductSource::label(ProviderCode::TorodPoint->value), $html);
        $this->assertStringContainsString(ProductSource::label(ProductSource::UNKNOWN), $html);
        $this->assertStringNotContainsString(self::UNKNOWN_CARRIER, $html, 'the stored code is not what a person is shown; the refusal is');
    }

    /**
     * The sheet's styled range follows the number of optional columns. The two combinations the old two-way test produced are unchanged,
     * which is what matters: an export that exists today is bordered exactly as it was.
     */
    public function test_the_styled_range_grows_with_the_optional_columns_and_keeps_the_ranges_it_had(): void
    {
        $this->assertSame('S', $this->lastColumnOf([]), 'no optional column: the floor the sheet has always used');
        $this->assertSame('S', $this->lastColumnOf(['countryColumn' => true]));
        $this->assertSame('S', $this->lastColumnOf(['productWiseTax' => true]));
        $this->assertSame('T', $this->lastColumnOf(['countryColumn' => true, 'productWiseTax' => true]), 'the old two-way answer');
        $this->assertSame('T', $this->lastColumnOf(['countryColumn' => true, 'sourceColumn' => true]));
        $this->assertSame('U', $this->lastColumnOf(['countryColumn' => true, 'productWiseTax' => true, 'sourceColumn' => true]));
    }

    /** The screen's checkbox and the controller's read must agree on one request key, or the filter is rendered and never applied. */
    public function test_the_filter_the_screen_renders_is_the_filter_the_code_reads(): void
    {
        $this->productNamingAnUnknownCarrier(1);

        $rendered = View::make('admin-views.partials._product-filters-sections', [
            'filterSection' => ['fulfillment_source'],
            'productBrands' => collect(),
            'productCategories' => collect(),
        ])->render();

        $this->assertStringContainsString('name="filter_fulfillment_sources[]"', $rendered);
        foreach (ProductSource::options() as $offered) {
            $this->assertStringContainsString('value="' . $offered . '"', $rendered);
        }

        $request = Request::create('/admin/products/list/in_house', 'GET', ['filter_fulfillment_sources' => [ProductSource::UNKNOWN]]);

        $this->assertSame([ProductSource::UNKNOWN], ProductSource::filterOf($request['filter_fulfillment_sources'] ?? null));
    }

    /** @param list<string> $sources @return list<int> the ids the shared query returns, in id order */
    private function listedWith(array $sources): array
    {
        $listed = app(ProductRepositoryInterface::class)->getWebListWithScope(
            filters: ['added_by' => 'in_house', 'fulfillment_sources' => ProductSource::filterOf($sources)],
            dataLimit: 'all',
        );

        return $listed->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
    }

    /** @param array<string, bool> $optional */
    private function lastColumnOf(array $optional): string
    {
        $export = new ProductListExport($optional + ['products' => collect()]);
        $method = new ReflectionMethod($export, 'lastColumn');

        return $method->invoke($export);
    }

    private function exportHtml(bool $sourceColumn): string
    {
        $data = [
            'products' => app(ProductRepositoryInterface::class)->getWebListWithScope(filters: ['added_by' => 'in_house'], dataLimit: 'all'),
            'type' => 'in_house',
            'searchValue' => null,
            'category' => 'all',
            'sub_category' => 'all',
            'sub_sub_category' => 'all',
            'brand' => 'all',
            'seller' => '',
            'status' => '',
            'countryColumn' => false,
            'sourceColumn' => $sourceColumn,
            'productWiseTax' => false,
        ];

        return View::make('file-exports.product-list', ['data' => $data])->render();
    }

    private function productNamingAnUnknownCarrier(int $id): void
    {
        $this->makeProduct($id);
        DB::table('products')->where('id', $id)->update([ProductSource::COLUMN => self::UNKNOWN_CARRIER]);
    }
}
