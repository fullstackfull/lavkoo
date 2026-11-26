<?php

namespace Tests\Feature\Catalog;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Rules\NewProductCountryInStaffScope;
use App\Services\Catalog\BulkImportCountry;
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
 * A bulk import can be told which country to import into, and an employee who works with several may import (B-08).
 *
 * The import sheet has no country column, so every row took ONE country that nobody chose. Two things followed, and both are fixed here:
 *
 * - an employee who works with several countries **could not import at all** -- the sheet was refused whole, because there was no way to
 *   say which of their countries the rows belonged to (`StaffBulkImportTest` pins that refusal, and it still holds for a sheet that names
 *   no country, which is what keeps this additive);
 * - an unrestricted employee **could not target a country either**: every import landed in the default storefront's catalogue, so another
 *   market's catalogue could not be prepared from this screen.
 *
 * The choice is validated, not corrected. `ProductCountry::newProductAttributes()` falls back to the default storefront for an
 * unrecognised country, which is right for the add form -- one row, in front of the person who typed it -- and wrong for hundreds of rows,
 * so an import that names a country nobody sells in is refused and says which problem it is: not a market, or not one of yours.
 *
 * **Nothing changes when nothing is chosen**, and the first test is that: every reading of `StaffBulkImportTest` still holds.
 */
class BulkImportTargetsAChosenCountryTest extends TestCase
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

    // ---- nothing chosen is the import of today ---------------------------------------------------------------------------

    public function test_a_sheet_that_names_no_country_behaves_exactly_as_it_did(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame(['KW', 'KW'], array_column($this->importedBy($this->boundTo(['KW']))['products'], 'storefront_country'), 'one country: every row takes it');

        $severalCountries = $this->importedBy($this->boundTo(['KW', 'SA'], adminId: 3));
        $this->assertFalse($severalCountries['status'], 'several countries and no choice: refused whole, as before');
        $this->assertSame(translate(self::SEVERAL), $severalCountries['message']);

        $unusable = $this->importedBy($this->boundTo(['zz'], adminId: 4));
        $this->assertSame(translate(self::UNUSABLE), $unusable['message']);
    }

    public function test_a_sheet_that_names_a_country_while_countries_are_not_tracked_is_the_import_of_today(): void
    {
        $result = $this->importedBy($this->boundTo(['KW', 'SA']), chosen: 'KW');

        $this->assertTrue($result['status'], 'nothing stores a country, so the choice is ignored rather than refused');
        $this->assertSame(['SA', 'SA'], array_column($result['products'], 'storefront_country'), 'the default of the table, as before');
    }

    // ---- an employee of several countries may import by naming one of theirs ---------------------------------------------

    public function test_an_employee_of_several_countries_imports_into_the_one_they_name(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['KW', 'SA']), chosen: 'KW');

        $this->assertTrue($result['status'], 'the sheet is no longer refused whole');
        $this->assertSame(['KW', 'KW'], array_column($result['products'], 'storefront_country'));
    }

    public function test_the_same_employee_imports_into_their_other_country_when_they_name_that_one(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['KW', 'SA']), chosen: 'SA');

        $this->assertSame(['SA', 'SA'], array_column($result['products'], 'storefront_country'), 'the choice decides, not the order of the grant');
    }

    /** The unrestricted twin: without it a refusal below could as easily be a field nothing reads. */
    public function test_an_unrestricted_employee_imports_into_any_country_the_shop_sells_in(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->anUnboundImport(), chosen: 'KW');

        $this->assertSame(['KW', 'KW'], array_column($result['products'], 'storefront_country'), 'a market nobody narrowed them to, and not the default storefront');
    }

    public function test_an_unrestricted_employee_who_names_nothing_still_imports_into_the_default(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->anUnboundImport());

        $this->assertTrue($result['status']);
        $this->assertCount(2, $result['products']);
        $this->assertSame([], array_column($result['products'], 'storefront_country'), 'no row even carries the key: the column is left alone, exactly as before');
    }

    // ---- a choice that cannot be honoured is refused, and says which problem it is ----------------------------------------

    public function test_a_country_outside_the_employees_own_is_refused_and_nothing_is_imported(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['KW']), chosen: 'SA');

        $this->assertFalse($result['status']);
        $this->assertSame(translate(NewProductCountryInStaffScope::MESSAGE), $result['message']);
        $this->assertSame([], $result['products']);
    }

    public function test_a_country_the_shop_does_not_sell_in_is_refused_rather_than_corrected_to_the_default(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->anUnboundImport(), chosen: 'ZZ');

        $this->assertFalse($result['status'], 'hundreds of rows must not land in a market nobody chose');
        $this->assertSame(translate(BulkImportCountry::NOT_A_MARKET), $result['message']);
    }

    public function test_the_choice_is_read_whatever_case_and_spacing_it_is_written_in(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame(['KW', 'KW'], array_column($this->importedBy($this->boundTo(['KW', 'SA']), chosen: ' kw ')['products'], 'storefront_country'));
    }

    public function test_a_choice_that_is_not_a_country_at_all_is_refused_rather_than_read_as_no_choice(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $result = $this->importedBy($this->boundTo(['KW', 'SA']), chosen: 'Kuwait');

        $this->assertFalse($result['status'], 'a wrong choice is a choice, and must not fall back to "nothing was chosen"');
    }

    public function test_the_sheet_is_refused_before_it_is_read(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $request = $this->boundTo(['KW'], chosen: 'SA');
        $request->files->remove('products_file');

        $this->assertSame(translate(NewProductCountryInStaffScope::MESSAGE), $this->importedBy($request)['message'], 'not the message of a file that could not be read');
    }

    // ---- the screen that sends the choice --------------------------------------------------------------------------------

    /**
     * **The include is asserted on the FILE, and that is a weaker test than it looks -- said here rather than left to be discovered.**
     * The import page extends the admin layout, which this world does not build, so it cannot be rendered; removing the `@include` from
     * it therefore fails no RENDER. Reading the file is what gives the two tests below their teeth: without this one, taking the field
     * off the screen leaves the whole file green while the service answers a question nobody asks.
     */
    public function test_the_import_screen_includes_the_country_field(): void
    {
        $screen = (string) file_get_contents(resource_path('views/admin-views/product/bulk-import.blade.php'));

        $this->assertStringContainsString("@include('admin-views.product.partials._bulk-import-country')", $screen);
    }

    /**
     * The partial itself is RENDERED, because a Blade file with an unimported class or a mistyped directive is a fatal error at render
     * and no grep catches it.
     */
    public function test_the_import_screen_offers_the_employees_own_countries_and_nothing_else(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->boundTo(['KW']);
        View::getFinder()->flush();

        $drawn = (string) view('admin-views.product.partials._bulk-import-country')->render();

        $this->assertStringContainsString('name="' . BulkImportCountry::FIELD . '"', $drawn);
        $this->assertStringContainsString('value="KW"', $drawn);
        $this->assertStringNotContainsString('value="AE"', $drawn, 'a country the employee does not work with is not offered');
    }

    /** Nothing is drawn while a product's country is not tracked, so the import screen of today is byte-identical. */
    public function test_the_import_screen_draws_nothing_while_a_products_country_is_not_tracked(): void
    {
        $this->boundTo(['KW']);
        View::getFinder()->flush();

        $this->assertSame('', trim((string) view('admin-views.product.partials._bulk-import-country')->render()));
    }

    // ---- what the tests build and read ----------------------------------------------------------------------------------

    private function buildTaxTables(): void
    {
        foreach (['taxables', 'taxes', 'system_tax_setups'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('system_tax_setups', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('taxables', function (Blueprint $table): void {
            $table->id();
            $table->string('taxable_type');
            $table->unsignedBigInteger('taxable_id');
            $table->string('tax_for')->nullable();
        });
    }

    /** A sheet of two rows in the format of the import, which carries no country column of its own. */
    private function sheet(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-country') . '.xlsx';
        $this->sheets[] = $path;
        $row = ['name' => 'Imported', 'category_id' => 1, 'sub_category_id' => '', 'sub_sub_category_id' => '', 'brand_id' => '', 'unit' => 'pc', 'minimum_order_qty' => 1, 'status' => 1,
            'refundable' => 0, 'youtube_video_url' => '', 'unit_price' => 10, 'tax_ids' => '999', 'discount' => 0, 'discount_type' => 'flat', 'current_stock' => 5, 'details' => '', 'thumbnail' => ''];
        (new FastExcel(collect([$row, [...$row, 'name' => 'Imported two']])))->export($path);

        return new UploadedFile($path, 'products.xlsx', null, null, true);
    }

    /** Binds a request of the employee, who works with the given countries, the way the middleware of the product screens does. */
    private function boundTo(array $countries, int $adminId = 2, ?string $chosen = null): Request
    {
        $this->giveCountries(adminId: $adminId, countries: $countries);
        $request = $this->anImport(chosen: $chosen);
        app(StaffCountryScope::class)->bind(request: $request, admin: Admin::query()->findOrFail($adminId));

        return $request;
    }

    /** An import nobody narrowed: the master admin, whom the scope never restricts. */
    private function anUnboundImport(?string $chosen = null): Request
    {
        return $this->anImport(chosen: $chosen);
    }

    private function anImport(?string $chosen): Request
    {
        $request = Request::create(
            '/admin/products/bulk-import',
            'POST',
            $chosen === null ? [] : [BulkImportCountry::FIELD => $chosen],
            files: ['products_file' => $this->sheet()],
        );
        $this->app->instance('request', $request);

        return $request;
    }

    /** @return array<string, mixed> */
    private function importedBy(Request $request, ?string $chosen = null): array
    {
        if ($chosen !== null) {
            $request->merge([BulkImportCountry::FIELD => $chosen]);
        }

        return app(ProductService::class)->getImportBulkProductData(request: $request, addedBy: 'admin', shopId: 1);
    }
}
