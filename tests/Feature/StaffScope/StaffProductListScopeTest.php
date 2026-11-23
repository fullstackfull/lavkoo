<?php

namespace Tests\Feature\StaffScope;

use App\Exports\ProductListExport;
use App\Exports\RestockProductListExport;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Product;
use App\Repositories\RestockProductRepository;
use App\Contracts\Repositories\ProductRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Every list, counter, picker and export of the product screens shows a restricted employee the products of their countries and nothing else (GCC-14c, slice 1).
 *
 * The requests go through the real route table, the real sign-in check and module permission, the real controller and the real repository; only the markup of the
 * pages is a test stand-in that prints the ids the controller handed it. Employee 2 holds Kuwait, employee 3 holds nothing, employee 1 is the master admin.
 */
class StaffProductListScopeTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $this->insertStaffProduct(id: 5, country: 'KW', extra: ['added_by' => 'seller', 'user_id' => 9]);
        $this->insertStaffProduct(id: 6, country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 9]);
        DB::table('sellers')->insert(['id' => 9, 'status' => 'approved']);
        foreach ([1, 2, 3, 4, 5, 6] as $productId) {
            DB::table('reviews')->insert(['product_id' => $productId]);
        }
        DB::table('restock_products')->insert([
            ['id' => 1, 'product_id' => 1, 'variant' => 'a'],
            ['id' => 2, 'product_id' => 2, 'variant' => 'b'],
            ['id' => 3, 'product_id' => 4, 'variant' => 'c'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Employee 2 works with Kuwait only. */
    private function asKuwaitEmployee(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);

        return $this->signInAsStaff(adminId: 2);
    }

    /** The searches that the reviews filter and the deal page share are for the people and marketing screens, so the employee who calls them holds marketing too. */
    private function asKuwaitEmployeeWhoMayAlsoSearch(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 70, countries: ['KW']);

        return $this->signInAsStaff(adminId: 70);
    }
    /**
     * @param TestResponse|string $page the answer of a page, which must have been a success (the page of an error holds no id, and would pass for an empty list), or the
     *                                  markup a JSON answer carried
     * @return list<int> the ids of the products a stand-in page printed
     */
    private function productsOf(TestResponse|string $page): array
    {
        if ($page instanceof TestResponse) {
            $page->assertOk();
            $page = (string) $page->getContent();
        }

        preg_match_all('/\[product:(\d+)\]/', $page, $matches);

        return array_map('intval', $matches[1]);
    }

    // ---- the lists ---------------------------------------------------------------------------------------------------------------

    public function test_the_in_house_list_shows_a_restricted_employee_the_products_of_their_country_only(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/products/list/in_house');

        $this->assertSame([3, 2], $this->productsOf($response), 'newest first, Kuwait only');
    }

    public function test_the_vendor_list_is_narrowed_the_same_way(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/products/list/vendor');

        $this->assertSame([5], $this->productsOf($response));
    }

    public function test_the_list_search_cannot_bring_back_a_product_of_another_country(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/products/list/in_house?searchValue=Product');

        $this->assertSame([3, 2], $this->productsOf($response));

        $byCode = $this->get('/admin/products/list/in_house?searchValue=SKU1');
        $this->assertSame([], $this->productsOf($byCode), 'the code of a product of another country finds nothing');
    }

    public function test_the_country_filter_cannot_widen_the_list_past_the_scope(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $foreign = $this->asKuwaitEmployee()->get('/admin/products/list/in_house?filter_storefront_countries[]=SA');
        $both = $this->get('/admin/products/list/in_house?filter_storefront_countries[]=SA&filter_storefront_countries[]=KW');

        $this->assertSame([3, 2], $this->productsOf($foreign), 'a country outside the scope is dropped from the filter, which then is no filter');
        $this->assertSame([3, 2], $this->productsOf($both));
    }

    public function test_the_stock_limit_list_is_narrowed(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/products/stock-limit-list/in_house');

        $this->assertEqualsCanonicalizing([2, 3], $this->productsOf($response));
    }

    public function test_the_stock_limit_count_is_narrowed(): void
    {
        $this->asKuwaitEmployee();

        $this->getJson('/admin/products/stock-limit-status/in_house')->assertOk()->assertJson(['status' => 'multiple_product', 'product_count' => 2]);

        DB::table('products')->where('id', 3)->update(['current_stock' => 50]);
        $one = $this->getJson('/admin/products/stock-limit-status/in_house')->assertOk();

        $this->assertSame('one_product', $one->json('status'));
        $this->assertSame(2, $one->json('product.id'));
    }

    public function test_the_updated_shipping_list_is_narrowed(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/products/updated-product-list/seller');

        $this->assertSame([5], $this->productsOf($response));
    }

    public function test_the_product_gallery_is_narrowed(): void
    {
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);

        $response = $this->asKuwaitEmployee()->get('/admin/products/product-gallery');

        $this->assertEqualsCanonicalizing([2, 3, 5], $this->productsOf($response));
    }

    public function test_the_restock_requests_and_their_count_are_narrowed(): void
    {
        $response = $this->asKuwaitEmployee()->get('/admin/products/request-restock-list');

        $this->assertStringContainsString('[restock:2 product:2]', $response->getContent());
        $this->assertStringNotContainsString('product:1]', $response->getContent());
        $this->assertStringNotContainsString('product:4]', $response->getContent());
        $this->assertStringContainsString('[total:1]', $response->getContent());
    }

    // ---- the pickers --------------------------------------------------------------------------------------------------------------

    public function test_the_product_search_offers_only_the_products_of_the_country(): void
    {
        $response = $this->asKuwaitEmployeeWhoMayAlsoSearch()->getJson('/admin/products/search?searchValue=Product')->assertOk();

        $this->assertEqualsCanonicalizing([2, 3, 5], $this->productsOf($response->json('result')));
        $this->assertSame(3, $response->json('count'));
    }

    public function test_the_search_with_the_code_of_a_product_of_another_country_finds_nothing_while_the_master_admin_finds_it(): void
    {
        $this->asKuwaitEmployeeWhoMayAlsoSearch();

        $restricted = $this->getJson('/admin/products/search?searchValue=zzz&name=SKU1')->assertOk();
        $master = $this->signInAsStaff(adminId: 1)->getJson('/admin/products/search?searchValue=zzz&name=SKU1')->assertOk();

        $this->assertSame([], $this->productsOf($restricted->json('result')));
        $this->assertSame([1], $this->productsOf($master->json('result')), 'the control: the product is there to be found');
    }

    public function test_the_deal_picker_search_is_narrowed(): void
    {
        $response = $this->asKuwaitEmployeeWhoMayAlsoSearch()->getJson('/admin/products/search-all-product?searchValue=Product')->assertOk();

        $this->assertEqualsCanonicalizing([2, 3, 5], $this->productsOf($response->json('result')));
    }

    public function test_the_product_form_picker_options_are_narrowed(): void
    {
        $response = $this->asKuwaitEmployee()->getJson('/admin/products/picker-options?searchValue=Product')->assertOk();

        $this->assertEqualsCanonicalizing([2, 3, 5], array_column($response->json('options'), 'value'));
    }

    public function test_the_picker_labels_leave_out_the_ids_of_other_countries_like_unknown_ones(): void
    {
        $this->asKuwaitEmployee();

        $response = $this->getJson('/admin/products/picker-labels?ids=1,2,4,3,99')->assertOk();

        $this->assertSame([2, 3], array_column($response->json('options'), 'value'));
        $this->assertSame($this->getJson('/admin/products/picker-labels?ids=2,3')->json(), $response->json());
    }

    public function test_the_selected_products_of_a_deal_page_leave_out_the_ids_of_other_countries(): void
    {
        $response = $this->asKuwaitEmployeeWhoMayAlsoSearch()->getJson('/admin/products/multiple-product-details?productIds[]=1&productIds[]=2&productIds[]=4&productIds[]=5')->assertOk();

        $this->assertEqualsCanonicalizing([2, 5], $this->productsOf($response->json('result')));
    }

    // ---- the exports ---------------------------------------------------------------------------------------------------------------

    public function test_the_product_export_holds_only_the_products_of_the_country(): void
    {
        Excel::fake();

        $this->asKuwaitEmployee()->get('/admin/products/export-excel/in_house')->assertOk();

        Excel::assertDownloaded('In_house-product-list.xlsx', function (ProductListExport $export): bool {
            $this->assertEqualsCanonicalizing([2, 3], $export->view()->getData()['data']['products']->pluck('id')->all());

            return true;
        });
    }

    public function test_the_restock_export_holds_only_the_requests_of_products_of_the_country(): void
    {
        Excel::fake();

        $this->asKuwaitEmployee()->get('/admin/products/export-restock')->assertOk();

        Excel::assertDownloaded('restock-product-list.xlsx', function (RestockProductListExport $export): bool {
            $this->assertSame([2], $export->view()->getData()['data']['products']->pluck('id')->all());

            return true;
        });
    }

    // ---- who is not narrowed -------------------------------------------------------------------------------------------------------

    public function test_the_master_admin_sees_every_product(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 1, countries: ['KW']);

        $response = $this->signInAsStaff(adminId: 1)->get('/admin/products/list/in_house');

        $this->assertSame([4, 3, 2, 1], $this->productsOf($response));
    }

    public function test_an_employee_with_no_country_sees_every_product_so_turning_the_switch_on_changes_nothing(): void
    {
        $this->switchStaffScopesOn();

        $response = $this->signInAsStaff(adminId: 3)->get('/admin/products/list/in_house');

        $this->assertSame([4, 3, 2, 1], $this->productsOf($response));
    }

    public function test_with_the_switch_off_an_employee_who_has_countries_sees_every_product(): void
    {
        $this->giveCountries(adminId: 2, countries: ['KW']);

        $response = $this->signInAsStaff(adminId: 2)->get('/admin/products/list/in_house');

        $this->assertSame([4, 3, 2, 1], $this->productsOf($response));
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5, 6], array_column($this->getJson('/admin/products/picker-options?searchValue=Product')->json('options'), 'value'));
    }

    public function test_an_employee_whose_every_value_is_damaged_sees_no_product_at_all(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 70, countries: ['zz']);

        $this->signInAsStaff(adminId: 70);

        $this->assertSame([], $this->productsOf($this->get('/admin/products/list/in_house')));
        $this->assertSame([], $this->productsOf($this->getJson('/admin/products/search?searchValue=Product')->json('result')));
        $this->assertSame([], $this->getJson('/admin/products/picker-options?searchValue=Product')->json('options'));
    }

    public function test_a_scope_assigned_between_two_requests_applies_to_the_second(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 2);

        $this->assertSame([4, 3, 2, 1], $this->productsOf($this->get('/admin/products/list/in_house')));

        $this->giveCountries(adminId: 2, countries: ['AE']);

        $this->assertSame([4], $this->productsOf($this->get('/admin/products/list/in_house')));
    }

    // ---- the repository ------------------------------------------------------------------------------------------------------------

    public function test_every_list_method_of_the_repository_is_narrowed_while_a_request_is_bound(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->bindRequestFor(adminId: 2);
        $repository = app(ProductRepositoryInterface::class);

        $ids = fn ($products): array => collect($products)->pluck('id')->sort()->values()->all();

        $this->assertSame([2, 3, 5], $ids($repository->getList(dataLimit: 'all')));
        $this->assertSame([2, 3, 5], $ids($repository->getListWhere(dataLimit: 'all')));
        $this->assertSame([2, 3, 5], $ids($repository->getListWhere(searchValue: 'Product', dataLimit: 'all')), 'a search ends in an or condition');
        $this->assertSame([2, 3, 5], $ids($repository->getListWithScope(dataLimit: 'all')));
        $this->assertSame([2, 3], $ids($repository->getWebListWithScope(filters: ['added_by' => 'in_house'], dataLimit: 'all')));
        $this->assertSame([2, 3, 5], $ids($repository->getListWhereNotIn(whereNotIn: ['id' => [99]])));
        $this->assertSame([2, 3, 5], collect($repository->getProductIds())->sort()->values()->all());
        $this->assertSame([2, 3], $ids($repository->getStockLimitListWhere(filters: ['added_by' => 'in_house', 'current_stock' => 10], dataLimit: 'all')));
        $this->assertNull($repository->getFirstWhere(params: ['id' => 1]));
        $this->assertNull($repository->getFirstWhereWithoutGlobalScope(params: ['id' => 1]), 'the by-id lookup that drops the scopes of the model keeps this one');
        $this->assertSame(2, $repository->getFirstWhereWithoutGlobalScope(params: ['id' => 2])->id);
    }

    public function test_a_query_of_the_model_or_of_a_relation_is_narrowed_too(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->bindRequestFor(adminId: 2);
        DB::table('stock_clearance_products')->insert(['product_id' => 1]);

        $this->assertSame(3, Product::query()->count());
        $this->assertSame([2], Product::query()->whereIn('id', [1, 2, 4])->pluck('id')->all());
        $this->assertNull(Product::query()->find(4));
        $this->assertSame(0, \App\Models\RestockProduct::query()->whereHas('product')->where('product_id', 1)->count());
        $this->assertSame(1, \App\Models\RestockProduct::query()->whereHas('product')->count());
        $this->assertSame(3, Product::withoutGlobalScope('translate')->count());
    }

    public function test_a_search_that_ends_in_an_or_condition_cannot_let_another_country_through(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->bindRequestFor(adminId: 2);
        DB::table('translations')->insert(['translationable_type' => 'App\Models\Product', 'translationable_id' => 1, 'locale' => 'ar', 'key' => 'name', 'value' => 'Needle']);

        $found = app(ProductRepositoryInterface::class)->getListWhere(searchValue: 'Needle', filters: ['added_by' => 'in_house', 'code' => 'SKU1'], dataLimit: 'all');

        $this->assertSame([], collect($found)->pluck('id')->all());
    }

    public function test_the_scope_is_not_bound_to_a_queue_worker_or_a_console_command_that_runs_after_the_request(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->bindRequestFor(adminId: 2);
        $this->assertSame(3, Product::query()->count());

        $this->app->instance('request', Request::create('/'));

        $this->assertSame(6, Product::query()->count());
    }

    public function test_the_restock_repository_follows_the_scope_through_the_product_of_each_request(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 2, countries: ['KW']);
        $this->bindRequestFor(adminId: 2);

        $restock = app(RestockProductRepository::class)->getListWhere(filters: ['added_by' => 'in_house'], relations: ['product'], dataLimit: 'all');

        $this->assertSame([2], $restock->pluck('id')->all());
    }

    private function bindRequestFor(int $adminId): void
    {
        $request = Request::create('/admin/products/list/in_house');
        $this->app->instance('request', $request);
        app(\App\Services\Staff\StaffCountryScope::class)->bind(request: $request, admin: \App\Models\Admin::query()->findOrFail($adminId));
    }
}
