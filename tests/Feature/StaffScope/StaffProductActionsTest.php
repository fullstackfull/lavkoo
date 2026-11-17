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
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What a restricted employee can change in the product screens: the products of their country, and nothing of another one (GCC-14c, slice 1). The requests reach the
 * real controllers and write to the database; the tests read the rows afterwards, so a refusal that came too late would show.
 */
class StaffProductActionsTest extends TestCase
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

        $this->insertStaffProduct(id: 10, country: 'KW');
        $this->insertStaffProduct(id: 20, country: 'SA');
        $this->insertStaffProduct(id: 11, country: 'KW');
        DB::table('restock_products')->insert([['id' => 1, 'product_id' => 10, 'variant' => 'a'], ['id' => 2, 'product_id' => 20, 'variant' => 'b']]);

        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 5, countries: ['KW']);
        $this->signInAsStaff(adminId: 5);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function column(int $productId, string $column): mixed
    {
        return DB::table('products')->where('id', $productId)->value($column);
    }

    public function test_a_product_of_the_country_is_published_and_unpublished_and_featured(): void
    {
        $this->postJson('/admin/products/status-update', ['id' => 10, 'status' => 0])->assertOk()->assertJson(['status' => 1]);
        $this->assertSame(0, (int) $this->column(10, 'status'));

        $this->postJson('/admin/products/featured-status', ['id' => 10])->assertOk();
        $this->assertSame(1, (int) $this->column(10, 'featured'));
    }

    public function test_a_product_of_another_country_is_neither_published_nor_featured_nor_given_a_shipping_cost(): void
    {
        $this->postJson('/admin/products/status-update', ['id' => 20, 'status' => 0])->assertNotFound();
        $this->postJson('/admin/products/featured-status', ['id' => 20])->assertNotFound();
        $this->postJson('/admin/products/updated-shipping', ['id' => 20, 'status' => 1])->assertNotFound();
        $this->postJson('/admin/products/deny', ['id' => 20, 'denied_note' => 'no'])->assertNotFound();
        $this->postJson('/admin/products/approve-status', ['id' => 20])->assertNotFound();

        $this->assertSame(1, (int) $this->column(20, 'status'));
        $this->assertSame(0, (int) $this->column(20, 'featured'));
        $this->assertSame(1, (int) $this->column(20, 'request_status'));
        $this->assertNull($this->column(20, 'denied_note'));
    }

    public function test_the_stock_of_a_product_of_another_country_cannot_be_changed_or_read(): void
    {
        $this->post('/admin/products/update-quantity', ['product_id' => 20, 'current_stock' => 0])->assertNotFound();
        $this->getJson('/admin/products/get-variations?id=20')->assertNotFound();

        $this->assertSame(5, (int) $this->column(20, 'current_stock'));
    }

    public function test_a_product_of_another_country_is_not_deleted_and_its_images_are_not_touched(): void
    {
        $this->delete('/admin/products/delete/20')->assertNotFound();
        $this->get('/admin/products/delete-image?id=20&image=a.png&name=a.png')->assertNotFound();

        $this->assertNotNull($this->column(20, 'id'));
    }

    public function test_a_bulk_change_is_all_or_nothing(): void
    {
        $this->postJson('/admin/products/bulk-product-status', ['ids' => [10, 20], 'action' => 'unpublish'])->assertNotFound();
        $this->assertSame(1, (int) $this->column(10, 'status'), 'the product of the country was not touched either');

        $this->postJson('/admin/products/bulk-product-status', ['ids' => [10, 11], 'action' => 'unpublish'])->assertOk()->assertJson(['updated' => 2]);
        $this->assertSame([0, 0], [(int) $this->column(10, 'status'), (int) $this->column(11, 'status')]);
    }

    public function test_the_restock_requests_of_other_countries_cannot_be_deleted(): void
    {
        $this->delete('/admin/products/restock-delete/2')->assertNotFound();
        $this->assertSame(2, DB::table('restock_products')->count());

        $this->delete('/admin/products/restock-delete/1')->assertRedirect();
        $this->assertSame([2], DB::table('restock_products')->pluck('id')->all());
    }

    public function test_what_one_request_is_narrowed_to_never_follows_the_next_request_of_another_employee(): void
    {
        $first = $this->get('/admin/products/list/in_house')->assertOk();
        $this->assertStringNotContainsString('[product:20]', $first->getContent());
        $this->assertStringContainsString('[product:10]', $first->getContent());

        $second = $this->signInAsStaff(adminId: 1)->get('/admin/products/list/in_house')->assertOk();

        $this->assertStringContainsString('[product:20]', $second->getContent());
        $this->assertStringContainsString('[product:10]', $second->getContent());
    }

    public function test_a_restricted_employee_can_still_use_the_screens_they_have_the_permission_for_that_have_no_product(): void
    {
        $this->get('/admin/products/bulk-import')->assertOk();
        $this->getJson('/admin/products/get-categories?parent_id=0')->assertOk();
    }
}
