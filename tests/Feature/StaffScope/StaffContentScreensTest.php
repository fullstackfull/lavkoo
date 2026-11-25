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
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The storefront-content screens of slice 4: the theme builder, the two SEO screens and the site map (GCC-14c).
 *
 * This is the half of slice 4 where something OTHER than a product carries the market. A theme belongs to a storefront
 * (`themes.storefront_country`, F-01), so the builder is judged by the market of the theme the version, section, block or asset in hand
 * belongs to -- which is why the refusals here are the point and the product pickers are the lesser half.
 *
 * The world: theme 1 is the default storefront's (it names no market, which is every theme that exists today), theme 2 is Kuwait's and
 * theme 3 is the United Arab Emirates'. Employee 90 holds the themes module and is restricted to Kuwait.
 */
class StaffContentScreensTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use BuildsThemeWorld;
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
        $this->buildThemeWorld();
        $this->buildThemeAssetAndSeoTables();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');

        DB::table('themes')->insert([
            ['id' => 1, 'name' => 'Platform', 'slug' => 'platform', 'is_active' => true, 'storefront_country' => null],
            ['id' => 2, 'name' => 'Kuwait', 'slug' => 'kuwait', 'is_active' => false, 'storefront_country' => 'KW'],
            ['id' => 3, 'name' => 'Emirates', 'slug' => 'emirates', 'is_active' => false, 'storefront_country' => 'AE'],
        ]);
        DB::table('theme_versions')->insert([
            ['id' => 11, 'theme_id' => 1, 'status' => 'draft', 'settings' => '{}'],
            ['id' => 22, 'theme_id' => 2, 'status' => 'draft', 'settings' => '{}'],
            ['id' => 33, 'theme_id' => 3, 'status' => 'draft', 'settings' => '{}'],
        ]);
        DB::table('theme_sections')->insert([
            ['id' => 111, 'theme_version_id' => 11, 'page' => 'home', 'type' => 'product_slider', 'settings' => '{}'],
            ['id' => 222, 'theme_version_id' => 22, 'page' => 'home', 'type' => 'product_slider', 'settings' => '{}'],
            ['id' => 333, 'theme_version_id' => 33, 'page' => 'home', 'type' => 'product_slider', 'settings' => '{}'],
        ]);
        DB::table('theme_blocks')->insert([
            ['id' => 1111, 'theme_section_id' => 111, 'type' => 'text', 'settings' => '{}'],
            ['id' => 2222, 'theme_section_id' => 222, 'type' => 'text', 'settings' => '{}'],
            ['id' => 3333, 'theme_section_id' => 333, 'type' => 'text', 'settings' => '{}'],
        ]);
        DB::table('theme_assets')->insert([
            ['id' => 7, 'theme_id' => 2, 'label' => 'Kuwaiti logo', 'path' => 'a.png', 'disk' => 'public'],
            ['id' => 8, 'theme_id' => 3, 'label' => 'Emirati logo', 'path' => 'b.png', 'disk' => 'public'],
        ]);
        DB::table('seo_meta_translations')->insert([
            ['id' => 1, 'seoable_type' => Product::class, 'seoable_id' => 1, 'language_code' => 'en', 'title' => 'Saudi'],
            ['id' => 2, 'seoable_type' => Product::class, 'seoable_id' => 2, 'language_code' => 'en', 'title' => 'Kuwaiti'],
            ['id' => 3, 'seoable_type' => 'App\Models\Category', 'seoable_id' => 5, 'language_code' => 'en', 'title' => 'A category'],
        ]);

        DB::table('admin_roles')->insert(['id' => 61, 'name' => 'Themes and settings', 'module_access' => json_encode(['themes_and_addons', 'business_settings']), 'status' => 1]);
        DB::table('admins')->insert(['id' => 90, 'name' => 'Theme hand', 'email' => 'ninety@example.test', 'admin_role_id' => 61]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** The two tables these screens read that no builder in the repository creates. */
    private function buildThemeAssetAndSeoTables(): void
    {
        foreach (['theme_assets', 'seo_meta_translations'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('theme_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('label')->nullable();
            $table->string('path');
            $table->string('disk')->default('public');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();
        });
        Schema::create('seo_meta_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->string('language_code', 10);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('keywords')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->boolean('is_followable')->default(true);
            $table->timestamps();
        });
    }

    /** Employee 90 holds the themes module and the business settings, and works with Kuwait only. */
    private function asKuwaitThemeHand(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 90, countries: ['KW']);

        return $this->signInAsStaff(adminId: 90);
    }

    // ---- the theme builder: a theme belongs to a storefront -------------------------------------------------------------------------

    public function test_a_theme_version_of_another_market_cannot_be_opened_or_changed(): void
    {
        $this->asKuwaitThemeHand();

        foreach (['/admin/theme/builder?version=33', '/admin/theme/builder/media/library?version_id=33'] as $url) {
            $this->get($url)->assertNotFound();
        }
        foreach ([
            '/admin/theme/builder/section/add' => ['version_id' => 33, 'page' => 'home', 'type' => 'product_slider'],
            '/admin/theme/builder/section/reorder' => ['version_id' => 33, 'page' => 'home', 'order' => []],
            '/admin/theme/builder/preview/start' => ['version_id' => 33],
            '/admin/theme/builder/preview/link' => ['version_id' => 33],
            '/admin/theme/builder/media/upload' => ['version_id' => 33],
        ] as $url => $payload) {
            $this->post($url, $payload)->assertNotFound();
        }

        $this->assertSame(1, DB::table('theme_sections')->where('theme_version_id', 33)->count(), 'nothing was added to the other market\'s version');
    }

    public function test_a_section_a_block_and_an_asset_are_followed_to_their_theme(): void
    {
        $this->asKuwaitThemeHand();

        foreach ([
            '/admin/theme/builder/section/update' => ['section_id' => 333, 'settings' => []],
            '/admin/theme/builder/section/toggle' => ['section_id' => 333, 'visible' => false],
            '/admin/theme/builder/section/duplicate' => ['section_id' => 333],
            '/admin/theme/builder/section/delete' => ['section_id' => 333],
            '/admin/theme/builder/section/delivery-rules' => ['section_id' => 333],
            '/admin/theme/builder/block/add' => ['section_id' => 333, 'type' => 'text'],
            '/admin/theme/builder/block/reorder' => ['section_id' => 333, 'order' => []],
            '/admin/theme/builder/block/update' => ['block_id' => 3333, 'settings' => []],
            '/admin/theme/builder/block/toggle' => ['block_id' => 3333, 'visible' => false],
            '/admin/theme/builder/block/duplicate' => ['block_id' => 3333],
            '/admin/theme/builder/block/delete' => ['block_id' => 3333],
            '/admin/theme/builder/media/delete' => ['id' => 8],
        ] as $url => $payload) {
            $this->post($url, $payload)->assertNotFound($url);
        }

        $this->assertSame(1, DB::table('theme_sections')->where('id', 333)->count());
        $this->assertSame(1, DB::table('theme_blocks')->where('id', 3333)->count());
        $this->assertSame(1, DB::table('theme_assets')->where('id', 8)->count());
    }

    public function test_the_employees_own_market_and_the_default_storefronts_theme_are_not_refused(): void
    {
        $this->asKuwaitThemeHand();

        // Kuwait's own version, section, block and asset: the middleware lets them through. What the controller then answers is the theme
        // builder's business, not the scope's, so this asserts only that it is not the 404 of something out of scope.
        $this->assertNotSame(404, $this->get('/admin/theme/builder?version=22')->getStatusCode());
        $this->assertNotSame(404, $this->post('/admin/theme/builder/section/update', ['section_id' => 222, 'settings' => []])->getStatusCode());
        $this->assertNotSame(404, $this->post('/admin/theme/builder/block/toggle', ['block_id' => 2222, 'visible' => false])->getStatusCode());
        $this->assertNotSame(404, $this->post('/admin/theme/builder/media/delete', ['id' => 7])->getStatusCode());
    }

    public function test_an_employee_of_the_default_market_reaches_the_theme_that_names_no_market(): void
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 90, countries: ['SA']);
        $this->signInAsStaff(adminId: 90);

        $this->assertNotSame(404, $this->get('/admin/theme/builder?version=11')->getStatusCode(), 'a theme with no market is the default storefront\'s');
        $this->get('/admin/theme/builder?version=22')->assertNotFound();
    }

    public function test_a_theme_row_that_is_gone_or_hangs_off_one_that_is_belongs_to_no_market(): void
    {
        $this->asKuwaitThemeHand();

        $this->get('/admin/theme/builder?version=999')->assertNotFound();
        $this->post('/admin/theme/builder/section/update', ['section_id' => 999, 'settings' => []])->assertNotFound();
        $this->post('/admin/theme/builder/block/toggle', ['block_id' => 'abc', 'visible' => false])->assertNotFound();

        DB::table('theme_sections')->where('id', 222)->update(['theme_version_id' => 909]);
        $this->post('/admin/theme/builder/section/update', ['section_id' => 222, 'settings' => []])->assertNotFound('a section whose version is gone');
    }

    public function test_the_builders_own_screen_without_a_version_is_let_through(): void
    {
        $this->asKuwaitThemeHand();

        $this->assertNotSame(404, $this->get('/admin/theme/builder')->getStatusCode(), 'no version named is not a version out of scope');
    }

    public function test_the_master_admin_reaches_every_market_s_theme(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 1);

        foreach ([11, 22, 33] as $versionId) {
            $this->assertNotSame(404, $this->get('/admin/theme/builder?version=' . $versionId)->getStatusCode(), (string) $versionId);
        }
    }

    /**
     * The storefronts switch does NOT gate this. `StorefrontMarketRows` asks whether a table carries a market without asking the switch, on
     * purpose -- "who may CHANGE a row is not a question about which shop is open" -- so a theme that names a market is that market's
     * whether or not its storefront is serving yet. Pinned here because the obvious reading is the other one.
     */
    public function test_the_theme_market_decides_whether_or_not_the_storefronts_are_switched_on(): void
    {
        $this->asKuwaitThemeHand();

        foreach ([false, true] as $storefrontsOn) {
            config(['gcc.storefronts.enabled' => $storefrontsOn]);

            $this->get('/admin/theme/builder?version=33')->assertNotFound('storefronts ' . var_export($storefrontsOn, true));
            $this->assertNotSame(404, $this->get('/admin/theme/builder?version=22')->getStatusCode());
        }
    }

    // ---- the SEO screens ------------------------------------------------------------------------------------------------------------

    public function test_the_seo_translations_screen_is_judged_by_the_product_only_when_it_is_about_a_product(): void
    {
        $this->asKuwaitThemeHand();

        $this->get('/admin/seo-settings/translations?entity_type=product&entity_id=1')->assertNotFound();
        $this->post('/admin/seo-settings/translations/save', ['entity_type' => 'product', 'entity_id' => 1, 'language_code' => 'en', 'title' => 'x'])->assertNotFound();
        $this->get('/admin/seo-settings/translations/delete?entity_type=product&entity_id=1&language_code=en')->assertNotFound();

        $this->assertSame('Saudi', DB::table('seo_meta_translations')->where('id', 1)->value('title'), 'the Saudi product\'s override is untouched');

        // The same id as a CATEGORY is not a product id at all, so it is not refused for a product it does not name.
        $this->assertNotSame(404, $this->get('/admin/seo-settings/translations?entity_type=category&entity_id=1')->getStatusCode());
    }

    public function test_the_recent_overrides_list_names_no_product_of_another_market_and_keeps_the_other_kinds(): void
    {
        $page = $this->asKuwaitThemeHand()->get('/admin/seo-settings/translations');

        $page->assertOk();
        $this->assertStringContainsString('[seo:2 Product:2]', (string) $page->getContent());
        $this->assertStringContainsString('[seo:3 Category:5]', (string) $page->getContent(), 'an override of another kind has no market and stays');
        $this->assertStringNotContainsString('[seo:1 ', (string) $page->getContent());
    }

    public function test_the_master_admin_sees_every_recent_override(): void
    {
        $this->switchStaffScopesOn();

        $page = $this->signInAsStaff(adminId: 1)->get('/admin/seo-settings/translations');

        $page->assertOk();
        foreach (['[seo:1 Product:1]', '[seo:2 Product:2]', '[seo:3 Category:5]'] as $row) {
            $this->assertStringContainsString($row, (string) $page->getContent());
        }
    }

    public function test_the_seo_health_audit_reads_only_the_employees_products(): void
    {
        $page = $this->asKuwaitThemeHand()->get('/admin/seo-settings/health');

        $page->assertOk();
        preg_match_all('/\[product:(\d+)\]/', (string) $page->getContent(), $matches);
        $this->assertSame([3, 2], array_map('intval', $matches[1]));
    }

    // ---- the site map ---------------------------------------------------------------------------------------------------------------

    public function test_every_action_of_the_site_map_is_unavailable_to_a_restricted_employee(): void
    {
        $this->asKuwaitThemeHand();

        $this->get('/admin/seo-settings/sitemap')->assertNotFound();
        $this->get('/admin/seo-settings/sitemap-generate-download')->assertNotFound();
        $this->get('/admin/seo-settings/sitemap-generate-upload')->assertNotFound();
        $this->get('/admin/seo-settings/sitemap-download?file=sitemap.xml')->assertNotFound();
        $this->get('/admin/seo-settings/sitemap-delete?file=sitemap.xml')->assertNotFound();
        $this->post('/admin/seo-settings/sitemap-manual-upload')->assertNotFound();
    }

    public function test_the_site_map_screen_is_not_refused_to_an_unrestricted_employee(): void
    {
        $this->switchStaffScopesOn();

        $this->assertNotSame(404, $this->signInAsStaff(adminId: 90)->get('/admin/seo-settings/sitemap')->getStatusCode(), 'no scope row means unrestricted');
    }

    // ---- nothing changes while the switch is off -------------------------------------------------------------------------------------

    public function test_with_the_switch_off_nothing_on_these_screens_is_refused(): void
    {
        $this->giveCountries(adminId: 90, countries: ['KW']);
        $this->signInAsStaff(adminId: 90);

        $this->assertNotSame(404, $this->get('/admin/theme/builder?version=33')->getStatusCode());
        $this->assertNotSame(404, $this->get('/admin/seo-settings/sitemap')->getStatusCode());
        $this->assertNotSame(404, $this->get('/admin/seo-settings/translations?entity_type=product&entity_id=1')->getStatusCode());

        $page = $this->get('/admin/seo-settings/translations');
        $this->assertStringContainsString('[seo:1 Product:1]', (string) $page->getContent());
    }
}
