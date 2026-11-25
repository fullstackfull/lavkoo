<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The promotion screens show a restricted employee the products of their countries and nothing else, and no action of theirs reaches a
 * product of another market (GCC-14c, slice 4).
 *
 * The requests go through the real route table, the real sign-in check and module permission, the real controller and the real repositories;
 * only the markup of the pages is a test stand-in that prints the ids the controller handed it.
 *
 * The world: products 1 and 7 are Saudi, 2 and 3 are Kuwaiti, 4 is Emirati. Employee 70 holds the catalog and marketing and is restricted to
 * Kuwait; employee 1 is the master admin; employee 3 is a Kuwait employee without the marketing permission.
 */
class StaffPromotionScreensTest extends TestCase
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
        $this->buildStaffPromotionTables();
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 4, country: 'AE');
        $this->insertStaffProduct(id: 7, country: 'SA');

        DB::table('flash_deals')->insert([
            ['id' => 1, 'title' => 'Mixed deal', 'deal_type' => 'flash_deal', 'status' => 1, 'start_date' => '2026-01-01', 'end_date' => '2099-01-01'],
        ]);
        DB::table('flash_deal_products')->insert([
            ['id' => 1, 'flash_deal_id' => 1, 'product_id' => 1],
            ['id' => 2, 'flash_deal_id' => 1, 'product_id' => 2],
            ['id' => 3, 'flash_deal_id' => 1, 'product_id' => 4],
        ]);
        DB::table('deal_of_the_days')->insert([
            ['id' => 1, 'title' => 'Saudi day', 'product_id' => 1, 'status' => 1],
            ['id' => 2, 'title' => 'Kuwaiti day', 'product_id' => 2, 'status' => 0],
        ]);
        DB::table('stock_clearance_setups')->insert([['id' => 1, 'setup_by' => 'admin', 'is_active' => 1, 'discount_type' => 'percentage', 'discount_amount' => 10]]);
        DB::table('stock_clearance_products')->insert([
            ['id' => 1, 'product_id' => 1, 'added_by' => 'admin', 'setup_id' => 1, 'is_active' => 1],
            ['id' => 2, 'product_id' => 2, 'added_by' => 'admin', 'setup_id' => 1, 'is_active' => 1],
            ['id' => 3, 'product_id' => 4, 'added_by' => 'admin', 'setup_id' => 1, 'is_active' => 1],
        ]);
        DB::table('banners')->insert([
            ['id' => 1, 'theme' => 'default', 'banner_type' => 'Main Banner', 'resource_type' => 'product', 'resource_id' => 1, 'published' => 1],
        ]);
        DB::table('most_demandeds')->insert([
            ['id' => 1, 'banner' => 'a.webp', 'product_id' => 1, 'status' => 1],
            ['id' => 2, 'banner' => 'b.webp', 'product_id' => 2, 'status' => 0],
        ]);
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);
        // The in-house shop page is a business setting, which neither of the roles the shared world defines holds, so this test makes the
        // one employee it needs: the same Kuwait scope, a role that holds the page.
        DB::table('admin_roles')->insert(['id' => 60, 'name' => 'Marketing and business settings', 'module_access' => json_encode(['catalog', 'marketing', 'business_settings']), 'status' => 1]);
        DB::table('admins')->insert(['id' => 80, 'name' => 'Shop keeper', 'email' => 'eighty@example.test', 'admin_role_id' => 60]);
        $this->insertStaffOrderOfProduct(orderId: 101, productId: 1);
        $this->insertStaffOrderOfProduct(orderId: 102, productId: 2);
        foreach ([1, 2, 3, 4, 7] as $productId) {
            DB::table('reviews')->insert(['product_id' => $productId]);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Employee 70 holds the catalog and marketing, which is the permission these screens ask for, and works with Kuwait only. */
    private function asKuwaitMarketer(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 70, countries: ['KW']);

        return $this->signInAsStaff(adminId: 70);
    }

    /** Employee 80 holds the in-house shop page as well, and works with Kuwait only. */
    private function asKuwaitShopKeeper(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 80, countries: ['KW']);

        return $this->signInAsStaff(adminId: 80);
    }

    /** @return list<int> the ids of the products a stand-in page printed */
    private function productsOf(TestResponse|string $page): array
    {
        if ($page instanceof TestResponse) {
            $page->assertOk();
            $page = (string) $page->getContent();
        }

        preg_match_all('/\[product:(\d+)\]/', $page, $matches);

        return array_map('intval', $matches[1]);
    }

    // ---- the flash deals ---------------------------------------------------------------------------------------------------------

    public function test_the_products_of_a_deal_and_the_count_the_deal_list_prints_hold_only_the_employees_products(): void
    {
        $list = $this->asKuwaitMarketer()->get('/admin/deal/flash');

        $list->assertOk();
        $this->assertStringContainsString('[deal:1 products:1]', (string) $list->getContent(), 'the deal holds three products and the employee may see one of them');

        $page = $this->get('/admin/deal/add-product/1');

        $page->assertOk();
        $this->assertSame([3], $this->productsOf($page), 'the picker offers the Kuwaiti products not already in the deal');
        $this->assertStringContainsString('[in-deal:2]', (string) $page->getContent());
        $this->assertStringNotContainsString('[in-deal:1]', (string) $page->getContent());
        $this->assertStringNotContainsString('[in-deal:4]', (string) $page->getContent());
    }

    public function test_the_master_admin_sees_every_country_in_the_same_deal(): void
    {
        $this->switchStaffScopesOn();
        $list = $this->signInAsStaff(adminId: 1)->get('/admin/deal/flash');

        $list->assertOk();
        $this->assertStringContainsString('[deal:1 products:3]', (string) $list->getContent());
    }

    public function test_the_deal_search_offers_only_the_employees_products(): void
    {
        $answer = $this->asKuwaitMarketer()->get('/admin/deal/search-product?searchValue=Product');

        $answer->assertOk();
        $this->assertSame([3, 2], $this->productsOf((string) $answer->json('result')));
    }

    public function test_adding_a_product_of_another_country_to_a_deal_is_refused_whole(): void
    {
        $this->asKuwaitMarketer()->post('/admin/deal/add-product/1', ['product_id' => [3, 7]])->assertNotFound();

        $this->assertSame(3, DB::table('flash_deal_products')->count(), 'nothing was added: one foreign id refuses the whole list');

        $this->post('/admin/deal/add-product/1', ['product_id' => [3]])->assertRedirect();
        $this->assertSame(1, DB::table('flash_deal_products')->where('product_id', 3)->count());
    }

    public function test_removing_a_product_of_another_country_from_the_deals_is_answered_like_an_unknown_product(): void
    {
        $this->asKuwaitMarketer()->post('/admin/deal/delete-product', ['id' => 1])->assertNotFound();
        $this->post('/admin/deal/delete-product', ['id' => 999])->assertNotFound();
        $this->post('/admin/deal/delete-product', ['id' => 'abc'])->assertNotFound();

        $this->assertSame(3, DB::table('flash_deal_products')->count());

        $this->post('/admin/deal/delete-product', ['id' => 2])->assertOk();
        $this->assertSame(2, DB::table('flash_deal_products')->count());
    }

    // ---- the deal of the day -----------------------------------------------------------------------------------------------------

    public function test_the_deal_of_the_day_list_holds_only_the_rows_whose_product_is_the_employees(): void
    {
        $page = $this->asKuwaitMarketer()->get('/admin/deal/day');

        $page->assertOk();
        $this->assertStringContainsString('[day:2 product:2]', (string) $page->getContent());
        $this->assertStringNotContainsString('[day:1 ', (string) $page->getContent());
        $this->assertSame([2, 3], $this->productsOf($page), 'the picker offers the Kuwaiti products');
    }

    public function test_a_deal_of_the_day_of_another_country_cannot_be_opened_changed_or_deleted(): void
    {
        $this->asKuwaitMarketer()->get('/admin/deal/day-update/1')->assertNotFound();
        $this->post('/admin/deal/day-update/1', ['product_id' => 2])->assertNotFound();
        $this->post('/admin/deal/day-status-update', ['id' => 1, 'status' => 1])->assertNotFound();
        $this->post('/admin/deal/day-delete', ['id' => 1])->assertNotFound();

        $this->assertSame(1, (int) DB::table('deal_of_the_days')->where('id', 1)->value('status'), 'the Saudi deal of the day was not touched');
        $this->assertSame(2, DB::table('deal_of_the_days')->count());
    }

    public function test_a_deal_of_the_day_cannot_be_moved_onto_a_product_of_another_country(): void
    {
        $this->asKuwaitMarketer()->post('/admin/deal/day-update/2', ['product_id' => 7])->assertNotFound();
        $this->post('/admin/deal/day', ['product_id' => 7])->assertNotFound();

        $this->assertSame(2, (int) DB::table('deal_of_the_days')->where('id', 2)->value('product_id'));
        $this->assertSame(2, DB::table('deal_of_the_days')->count());
    }

    // ---- the most-demanded entries -----------------------------------------------------------------------------------------------

    public function test_a_most_demanded_entry_of_another_country_cannot_be_changed_or_deleted(): void
    {
        $this->asKuwaitMarketer()->post('/admin/most-demanded/status', ['id' => 1, 'status' => 0])->assertNotFound();
        $this->post('/admin/most-demanded/delete', ['id' => 1])->assertNotFound();
        $this->post('/admin/most-demanded/store', ['product_id' => 7])->assertNotFound();

        $this->assertSame(2, DB::table('most_demandeds')->count());
        $this->assertSame(1, (int) DB::table('most_demandeds')->where('id', 1)->value('status'));
    }

    // ---- the clearance sale ------------------------------------------------------------------------------------------------------

    public function test_the_clearance_screens_offer_and_list_only_the_employees_products(): void
    {
        $page = $this->asKuwaitMarketer()->get('/admin/deal/clearance-sale');

        $page->assertOk();
        $this->assertSame([3], $this->productsOf($page), 'the picker offers the Kuwaiti products that hold no offer yet');
        $this->assertStringContainsString('[offer:2 product:2]', (string) $page->getContent());
        $this->assertStringNotContainsString('[offer:1 ', (string) $page->getContent());
        $this->assertStringNotContainsString('[offer:3 ', (string) $page->getContent());

        $search = $this->get('/admin/deal/clearance-sale/search?searchValue=Product');
        $this->assertSame([3], $this->productsOf((string) $search->json('result')));
    }

    public function test_an_offer_of_another_country_cannot_be_changed_or_removed_one_at_a_time(): void
    {
        $this->asKuwaitMarketer()->post('/admin/deal/clearance-sale/update-discount', ['product_id' => 1, 'discount_amount' => 5, 'discount_type' => 'percentage'])->assertNotFound();
        $this->post('/admin/deal/clearance-sale/clearance-product-status-update', ['product_id' => 1, 'status' => 0])->assertNotFound();
        $this->delete('/admin/deal/clearance-sale/clearance-delete/1')->assertNotFound();

        $this->assertSame(3, DB::table('stock_clearance_products')->count());
    }

    /**
     * The action that names no id at all. It deletes every offer an administrator made, so what keeps it inside the employee's countries is
     * the scope on the offers themselves, not a check of a request parameter.
     */
    public function test_removing_every_clearance_offer_at_once_removes_only_the_employees(): void
    {
        $this->asKuwaitMarketer()->delete('/admin/deal/clearance-sale/clearance-products-delete')->assertRedirect();

        $this->assertSame([1, 3], DB::table('stock_clearance_products')->orderBy('id')->pluck('id')->map('intval')->all(), 'the Saudi and Emirati offers stayed');
    }

    public function test_the_master_admin_removing_every_clearance_offer_still_removes_them_all(): void
    {
        $this->switchStaffScopesOn();

        $this->signInAsStaff(adminId: 1)->delete('/admin/deal/clearance-sale/clearance-products-delete')->assertRedirect();

        $this->assertSame(0, DB::table('stock_clearance_products')->count());
    }

    // ---- the banners -------------------------------------------------------------------------------------------------------------

    public function test_the_banner_pickers_offer_only_the_employees_products(): void
    {
        $list = $this->asKuwaitMarketer()->get('/admin/banner/list');

        $this->assertSame([2, 3], $this->productsOf($list));

        $edit = $this->get('/admin/banner/update/1');
        $this->assertSame([2, 3], $this->productsOf($edit));
    }

    public function test_a_banner_that_points_at_a_product_of_another_country_is_refused(): void
    {
        $this->asKuwaitMarketer()->post('/admin/banner/add', ['resource_type' => 'product', 'product_id' => 1, 'banner_type' => 'Main Banner'])->assertNotFound();
        $this->post('/admin/banner/update/1', ['resource_type' => 'product', 'product_id' => 7, 'banner_type' => 'Main Banner'])->assertNotFound();

        $this->assertSame(1, DB::table('banners')->count());
    }

    /**
     * A banner form posts one id per kind of resource, so `product_id` is only a product id when the type says so: a category banner that
     * carries a stale `product_id` in the form must not be refused for a product it does not point at.
     */
    public function test_a_banner_of_another_kind_is_not_judged_by_the_product_id_its_form_carries(): void
    {
        $this->asKuwaitMarketer()->post('/admin/banner/add', ['resource_type' => 'category', 'category_id' => 5, 'product_id' => 1, 'banner_type' => 'Main Banner'])->assertRedirect();
    }

    // ---- the in-house shop page --------------------------------------------------------------------------------------------------

    public function test_the_in_house_shop_page_counts_the_employees_products_reviews_and_orders(): void
    {
        $page = $this->asKuwaitShopKeeper()->get('/admin/business-settings/inhouse-shop');

        $page->assertOk();
        $this->assertStringContainsString('[products:2][orders:1][reviews:2]', (string) $page->getContent());
        // The page's own order count happens to be right either way, because the in-house filter counts orders through
        // `whereHas('details.product')` and the product scope narrows that. `list:orders` is what makes it right ON PURPOSE: it narrows the
        // ORDER subject for the request, so any read of the orders on this page is the employee's, joined to a product or not.
        $this->assertSame(1, \App\Models\Order::query()->count());
    }

    public function test_the_master_admin_sees_the_whole_platforms_counters_on_the_same_page(): void
    {
        $this->switchStaffScopesOn();

        $page = $this->signInAsStaff(adminId: 1)->get('/admin/business-settings/inhouse-shop');

        $page->assertOk();
        $this->assertStringContainsString('[products:5][orders:2][reviews:5]', (string) $page->getContent());
        $this->assertSame(2, \App\Models\Order::query()->count());
    }

    // ---- the rows that belong to a product, read directly -------------------------------------------------------------------------

    /**
     * The screens above are narrowed twice over, and on purpose. Each repository that lists a promotion's products happens to count or list
     * them THROUGH the product (`whereHas('product')`), which the product scope of slice 1 narrows, so taking
     * `StaffCountryProductRowScope` off those models leaves most of the screens right -- and leaves them right by accident, one
     * `whereHas` away from widening again. These are the tests of the scope itself: any read of these tables inside a bound request reaches
     * only the rows whose product is the employee's, whether it joins the products or not. The delete of every clearance offer at once is
     * the one SCREEN that has nothing else holding it (above).
     */
    public function test_a_read_of_the_rows_that_belong_to_a_product_is_narrowed_even_when_it_does_not_join_the_products(): void
    {
        $this->asKuwaitMarketer()->get('/admin/deal/flash')->assertOk();

        $this->assertSame([2], \App\Models\FlashDealProduct::query()->where('flash_deal_id', 1)->pluck('product_id')->map('intval')->all());
        $this->assertSame(1, \App\Models\FlashDealProduct::query()->where('flash_deal_id', 1)->count());
        $this->assertSame([2], \App\Models\DealOfTheDay::query()->pluck('product_id')->map('intval')->all());
        $this->assertSame([2], \App\Models\MostDemanded::query()->pluck('product_id')->map('intval')->all());
        $this->assertSame([2], \App\Models\StockClearanceProduct::query()->pluck('product_id')->map('intval')->all());
    }

    public function test_a_row_whose_product_is_gone_belongs_to_no_market_and_is_hidden_from_a_restricted_employee(): void
    {
        DB::table('flash_deal_products')->insert(['id' => 9, 'flash_deal_id' => 1, 'product_id' => 404]);
        DB::table('deal_of_the_days')->insert(['id' => 9, 'title' => 'Orphan', 'product_id' => null, 'status' => 0]);

        $this->asKuwaitMarketer()->get('/admin/deal/flash')->assertOk();

        $this->assertSame([2], \App\Models\FlashDealProduct::query()->where('flash_deal_id', 1)->pluck('product_id')->map('intval')->all(), 'it fails closed');
        $this->assertSame([2], \App\Models\DealOfTheDay::query()->pluck('product_id')->map('intval')->all());
    }

    public function test_the_master_admin_reads_every_market_s_rows_through_the_same_models(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 1)->get('/admin/deal/flash')->assertOk();

        $this->assertSame([1, 2, 4], \App\Models\FlashDealProduct::query()->where('flash_deal_id', 1)->pluck('product_id')->map('intval')->all());
        $this->assertSame([1, 2], \App\Models\DealOfTheDay::query()->pluck('product_id')->map('intval')->all());
    }

    // ---- nothing changes while the switch is off ----------------------------------------------------------------------------------

    public function test_with_the_switch_off_a_restricted_employee_sees_every_country(): void
    {
        $this->giveCountries(adminId: 70, countries: ['KW']);

        $list = $this->signInAsStaff(adminId: 70)->get('/admin/deal/flash');

        $this->assertStringContainsString('[deal:1 products:3]', (string) $list->getContent());
        $this->get('/admin/deal/day-update/1')->assertOk();
        $this->delete('/admin/deal/clearance-sale/clearance-products-delete')->assertRedirect();
        $this->assertSame(0, DB::table('stock_clearance_products')->count());
    }

    public function test_an_employee_with_no_country_row_is_not_restricted(): void
    {
        $this->switchStaffScopesOn();

        $list = $this->signInAsStaff(adminId: 70)->get('/admin/deal/flash');

        $this->assertStringContainsString('[deal:1 products:3]', (string) $list->getContent(), 'no row means unrestricted, which is what turning the switch on must not change');
    }
}
