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
use App\Models\Admin;
use App\Services\ProductService;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Rap2hpoutre\FastExcel\FastExcel;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What a restricted employee's bulk import does (GCC-14c, slice 1). The sheet has no country column, so every row takes one country: the employee's only country when
 * countries are tracked, and the default of the table, which has to be one of theirs, when they are not. An employee with several countries, or none, may not import:
 * the sheet is refused before it is read and nothing is written. Everyone who is not restricted imports exactly as before.
 */
class StaffBulkImportTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const SEVERAL = 'the_import_sheet_has_no_country_and_your_account_works_with_more_than_one';

    private const UNUSABLE = 'the_products_of_this_sheet_would_be_saved_in_a_country_your_account_does_not_work_with';

    /** @var list<string> */
    private array $sheets = [];

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
        $this->buildTaxTables();
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();
        $this->switchStaffScopesOn();
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->sheets, 'file_exists'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildTaxTables(): void
    {
        foreach (['taxables', 'taxes', 'system_tax_setups'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('system_tax_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('taxables', function (Blueprint $table) {
            $table->id();
            $table->string('taxable_type');
            $table->unsignedBigInteger('taxable_id');
            $table->string('tax_for')->nullable();
        });
    }

    /** A sheet of two rows in the format of the import. */
    private function sheet(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'staff-import') . '.xlsx';
        $this->sheets[] = $path;
        $row = ['name' => 'Imported', 'category_id' => 1, 'sub_category_id' => '', 'sub_sub_category_id' => '', 'brand_id' => '', 'unit' => 'pc', 'minimum_order_qty' => 1, 'status' => 1,
            'refundable' => 0, 'youtube_video_url' => '', 'unit_price' => 10, 'tax_ids' => '999', 'discount' => 0, 'discount_type' => 'flat', 'current_stock' => 5, 'details' => '', 'thumbnail' => ''];
        (new FastExcel(collect([$row, [...$row, 'name' => 'Imported two']])))->export($path);

        return new UploadedFile($path, 'products.xlsx', null, null, true);
    }

    /** Binds a request of the employee, who works with the given countries, the way the middleware of the product screens does. */
    private function boundTo(array $countries, int $adminId = 2): Request
    {
        $this->giveCountries(adminId: $adminId, countries: $countries);
        $request = Request::create('/admin/products/bulk-import', 'POST', files: ['products_file' => $this->sheet()]);
        $this->app->instance('request', $request);
        app(StaffCountryScope::class)->bind(request: $request, admin: Admin::query()->findOrFail($adminId));

        return $request;
    }

    private function importedBy(Request $request): array
    {
        return app(ProductService::class)->getImportBulkProductData(request: $request, addedBy: 'admin', shopId: 1);
    }

    // ---- the service --------------------------------------------------------------------------------------------------------------

    public function test_with_one_country_and_countries_tracked_every_row_takes_it(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['KW']));

        $this->assertTrue($result['status']);
        $this->assertSame(['KW', 'KW'], array_column($result['products'], 'storefront_country'));
        $this->assertCount(2, $result['productsTax']);
    }

    public function test_with_several_countries_and_countries_tracked_the_sheet_is_refused_whole(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['KW', 'SA']));

        $this->assertFalse($result['status']);
        $this->assertSame(translate(self::SEVERAL), $result['message']);
        $this->assertSame([], $result['products']);
    }

    public function test_with_no_usable_country_the_sheet_is_refused(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['zz']));

        $this->assertFalse($result['status']);
        $this->assertSame(translate(self::UNUSABLE), $result['message']);
    }

    public function test_while_countries_are_not_tracked_the_rows_take_the_default_of_the_table_when_it_is_one_of_theirs(): void
    {
        $result = $this->importedBy($this->boundTo(['KW', 'SA']));

        $this->assertTrue($result['status']);
        $this->assertSame(['SA', 'SA'], array_column($result['products'], 'storefront_country'));
    }

    public function test_while_countries_are_not_tracked_the_sheet_is_refused_when_the_default_is_not_one_of_theirs(): void
    {
        $result = $this->importedBy($this->boundTo(['KW', 'AE']));

        $this->assertFalse($result['status']);
        $this->assertSame(translate(self::UNUSABLE), $result['message'], 'the rows would be saved in SA, which is not theirs');
    }

    public function test_the_sheet_is_refused_before_it_is_read(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $request = $this->boundTo(['KW', 'SA']);
        $request->files->remove('products_file');

        $this->assertSame(translate(self::SEVERAL), $this->importedBy($request)['message'], 'not the message of a file that could not be read');
    }

    public function test_an_import_nobody_narrowed_is_what_it_always_was(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->giveCountries(adminId: 2, countries: ['KW', 'SA']);
        $request = Request::create('/admin/products/bulk-import', 'POST', files: ['products_file' => $this->sheet()]);
        $this->app->instance('request', $request);

        $result = $this->importedBy($request);

        $this->assertTrue($result['status']);
        $this->assertCount(2, $result['products']);
        $this->assertArrayNotHasKey('storefront_country', $result['products'][0], 'the row carries no country: the table gives it the default, as it always did');
    }

    public function test_an_import_nobody_narrowed_does_not_probe_the_schema_even_with_countries_tracked(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $request = Request::create('/vendor/products/bulk-import', 'POST', files: ['products_file' => $this->sheet()]);
        $this->app->instance('request', $request);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->importedBy($request);

        $probes = array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $query): bool => preg_match('/pragma|information_schema|sqlite_master|table_info|show columns|show tables/i', $query) === 1);
        $this->assertSame([], array_values($probes), 'whether countries are tracked is only asked once an employee is narrowed');
    }

    public function test_the_master_admin_and_an_employee_without_countries_import_as_before(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->giveCountries(adminId: 1, countries: ['KW']);

        foreach ([1, 2] as $adminId) {
            $request = Request::create('/admin/products/bulk-import', 'POST', files: ['products_file' => $this->sheet()]);
            $this->app->instance('request', $request);
            app(StaffCountryScope::class)->bind(request: $request, admin: Admin::query()->findOrFail($adminId));

            $result = $this->importedBy($request);

            $this->assertTrue($result['status'], 'admin ' . $adminId);
            $this->assertArrayNotHasKey('storefront_country', $result['products'][0], 'admin ' . $adminId);
        }
    }

    public function test_a_vendor_import_is_not_touched(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $request = $this->boundTo(['KW', 'SA']);
        $request = Request::create('/vendor/products/bulk-import', 'POST', files: ['products_file' => $this->sheet()]);
        $this->app->instance('request', $request);

        $result = app(ProductService::class)->getImportBulkProductData(request: $request, addedBy: 'seller', shopId: 1);

        $this->assertTrue($result['status']);
        $this->assertArrayNotHasKey('storefront_country', $result['products'][0]);
    }

    public function test_the_two_refusals_are_language_keys_every_language_file_holds(): void
    {
        foreach (['en', 'sa', 'ae'] as $locale) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");

            foreach ([self::SEVERAL, self::UNUSABLE] as $key) {
                $this->assertNotSame('', trim((string) ($messages[$key] ?? '')), $locale . ' lacks ' . $key);
            }
        }
    }

    public function test_the_default_of_the_table_is_what_a_row_without_a_country_gets(): void
    {
        $this->assertSame(StaffCountryScope::TABLE_DEFAULT_COUNTRY, 'SA');
        $this->assertSame('SA', DB::table('products')->where('id', DB::table('products')->insertGetId(['name' => 'No country given']))->value('storefront_country'));
    }

    // ---- the route -----------------------------------------------------------------------------------------------------------------

    public function test_the_route_saves_the_rows_of_an_employee_with_one_country_in_that_country_and_they_see_them(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->giveCountries(adminId: 5, countries: ['KW']);

        $this->signInAsStaff(adminId: 5)->post('/admin/products/bulk-import', ['products_file' => $this->sheet()])->assertRedirect();

        $this->assertSame(['KW', 'KW'], DB::table('products')->orderBy('id')->pluck('storefront_country')->all());
    }

    public function test_the_route_writes_nothing_for_an_employee_with_several_countries(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->giveCountries(adminId: 5, countries: ['KW', 'AE']);

        $response = $this->signInAsStaff(adminId: 5)->post('/admin/products/bulk-import', ['products_file' => $this->sheet()]);

        $response->assertRedirect();
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(['error'], array_column(session('laravel-toaster-magic::messages', []), 'type'));
    }

    public function test_the_route_imports_in_the_default_country_for_an_employee_who_is_not_restricted(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->signInAsStaff(adminId: 2)->post('/admin/products/bulk-import', ['products_file' => $this->sheet()])->assertRedirect();

        $this->assertSame(['SA', 'SA'], DB::table('products')->orderBy('id')->pluck('storefront_country')->all());
    }
}
