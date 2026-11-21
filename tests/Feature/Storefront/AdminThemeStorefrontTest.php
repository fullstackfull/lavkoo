<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Theme;
use App\Services\Theme\ThemeManager;
use DOMDocument;
use DOMXPath;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC F-01.3: the admin theme list shows which storefront each theme belongs to and which theme each storefront serves, and a new theme is made for the storefront the
 * admin chooses. While the themes are not kept by storefront, or before the migration has run, none of it appears and the page is what it was.
 */
class AdminThemeStorefrontTest extends TestCase
{
    use BuildsThemeWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        session(['local' => 'en']);
        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The Saudi theme (it names no country) and the Emirati one, both active, and a Kuwaiti theme that is not. */
    private function giveSaudiAndEmiratiThemes(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $this->giveTheme(name: 'Kuwaiti', country: 'KW');
    }

    private function themeList(): string
    {
        $themes = Theme::query()->with('versions')->orderByDesc('id')->get();

        return view('admin-views.theme.index', [
            'themes' => new LengthAwarePaginator($themes, $themes->count(), 20, 1, ['path' => '/admin/theme']),
            'compatibility' => [], 'readiness' => [], 'schedulerOk' => true, 'search' => null, 'presets' => [], 'assetsReady' => false, 'maxAssetSize' => 1024,
        ])->render();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** @return array<string, string> storefront => the text of its row in the table of what each storefront serves */
    private function servedRows(string $html): array
    {
        $rows = [];
        foreach ($this->xpath($html)->query('//tr[@data-served-storefront]') as $row) {
            $rows[$row->getAttribute('data-served-storefront')] = trim((string) preg_replace('/\s+/', ' ', $row->textContent));
        }

        return $rows;
    }

    /** @return array<string, string> theme slug => the storefront its badge names */
    private function badges(string $html): array
    {
        $xpath = $this->xpath($html);
        $badges = [];
        foreach ($xpath->query('//tr[.//code]') as $row) {
            $badge = $xpath->query('.//span[@data-theme-storefront]', $row)->item(0);
            $badges[trim($xpath->query('.//code', $row)->item(0)->textContent)] = $badge?->getAttribute('data-theme-storefront') ?? '';
        }

        return $badges;
    }

    // ---- which theme each storefront serves ---------------------------------------------------------------------------------------------

    public function test_the_list_shows_which_theme_each_storefront_serves(): void
    {
        $this->giveSaudiAndEmiratiThemes();
        $html = $this->themeList();

        $served = $this->servedRows($html);

        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_keys($served));
        $this->assertSame('SA · SAR Saudi', $served['SA']);
        $this->assertSame('AE · AED Emirati', $served['AE']);
        $this->assertSame(['Storefront', 'Theme'], array_map(
            static fn ($header): string => trim($header->textContent),
            iterator_to_array($this->xpath($html)->query('//div[@id="theme-storefronts"]//th')),
        ), 'the table says what its two columns are');
    }

    public function test_a_storefront_without_a_theme_of_its_own_is_shown_with_the_default_storefronts(): void
    {
        $this->giveSaudiAndEmiratiThemes();

        $served = $this->servedRows($this->themeList());

        $this->assertSame("KW · KWD Saudi The default storefront's theme", $served['KW'], 'the Kuwaiti theme is not active, so Kuwait is served the default storefront\'s');
        $this->assertSame("OM · OMR Saudi The default storefront's theme", $served['OM']);
    }

    public function test_the_list_follows_an_activation(): void
    {
        $this->giveSaudiAndEmiratiThemes();
        app(ThemeManager::class)->activate(Theme::query()->where('name', 'Kuwaiti')->firstOrFail());

        $served = $this->servedRows($this->themeList());

        $this->assertSame('KW · KWD Kuwaiti', $served['KW']);
        $this->assertSame('SA · SAR Saudi', $served['SA']);
        $this->assertSame('AE · AED Emirati', $served['AE']);
    }

    public function test_a_storefront_with_nothing_to_show_is_served_the_built_in_pages(): void
    {
        $this->giveTheme(name: 'Emirati', country: 'AE', active: true);

        $served = $this->servedRows($this->themeList());

        $this->assertSame('SA · SAR No theme: the built-in pages are shown', $served['SA']);
        $this->assertSame('KW · KWD No theme: the built-in pages are shown', $served['KW']);
        $this->assertSame('AE · AED Emirati', $served['AE']);
    }

    public function test_only_the_storefronts_a_request_can_reach_are_listed(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->giveSaudiAndEmiratiThemes();

        $this->assertSame(['SA'], array_keys($this->servedRows($this->themeList())));
    }

    // ---- which storefront each theme belongs to -----------------------------------------------------------------------------------------

    public function test_every_theme_in_the_list_names_its_storefront(): void
    {
        $this->giveSaudiAndEmiratiThemes();
        $this->giveTheme(name: 'Explicit', country: 'SA');

        $this->assertSame(['explicit' => 'SA', 'kuwaiti' => 'KW', 'emirati' => 'AE', 'saudi' => 'SA'], $this->badges($this->themeList()));
    }

    // ---- nothing of it while the switch is off ---------------------------------------------------------------------------------------------

    public function test_nothing_of_it_appears_while_the_switch_is_off_or_before_the_migration(): void
    {
        $this->giveSaudiAndEmiratiThemes();
        $markers = ['theme-storefronts', 'data-served-storefront', 'data-theme-storefront', 'name="storefront_country"', 'theme-storefront"'];

        config(['gcc.storefronts.themes.enabled' => false]);
        $switchedOff = $this->themeList();
        $this->buildThemeWorld(withCountryColumn: false);
        $this->giveTheme(name: 'Saudi', active: true);
        config(['gcc.storefronts.themes.enabled' => true]);
        $beforeTheMigration = $this->themeList();

        foreach ([$switchedOff, $beforeTheMigration] as $html) {
            foreach ($markers as $marker) {
                $this->assertStringNotContainsString($marker, $html, $marker);
            }
            $this->assertStringContainsString('name="description"', $html, 'the page itself is there');
        }
    }

    // ---- the storefront of a new theme ------------------------------------------------------------------------------------------------------

    public function test_the_create_form_offers_every_configured_storefront_with_the_default_one_chosen(): void
    {
        $html = $this->themeList();

        preg_match_all('/<option value="([A-Z]{2})"\s*(selected)?>\s*([^<]+?)\s*<\/option>/', $html, $options, PREG_SET_ORDER);

        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_column($options, 1));
        $this->assertSame(['SA · SAR', 'AE · AED', 'KW · KWD', 'QA · QAR', 'BH · BHD', 'OM · OMR'], array_column($options, 3));
        $this->assertSame(['SA'], array_column(array_filter($options, static fn (array $option): bool => ($option[2] ?? '') === 'selected'), 1));
        $this->assertSame(1, substr_count($html, 'name="storefront_country"'));
    }

    public function test_the_choice_made_before_a_failed_submit_is_kept(): void
    {
        session()->flashInput(['storefront_country' => 'KW']);
        $this->app['request']->setLaravelSession(session()->driver());

        $this->assertMatchesRegularExpression('/<option value="KW"\s*selected>/', $this->themeList());
    }

    private function throughTheRoute(): void
    {
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    /** @return array<string, ?string> the storefront of every theme that exists, by name */
    private function storefrontsOfThemes(): array
    {
        return Theme::query()->orderBy('id')->pluck('storefront_country', 'name')->all();
    }

    public function test_a_new_theme_belongs_to_the_storefront_the_admin_chose(): void
    {
        $this->throughTheRoute();

        $this->post(route('admin.theme.store'), ['name' => 'Emirati look', 'storefront_country' => 'AE'])->assertRedirect(route('admin.theme.index'));

        $this->assertSame(['Emirati look' => 'AE'], $this->storefrontsOfThemes());
        $this->assertFalse(Theme::query()->firstOrFail()->is_active, 'a theme is created inactive');
    }

    public function test_a_new_theme_of_the_default_storefront_names_no_country(): void
    {
        $this->throughTheRoute();

        $this->post(route('admin.theme.store'), ['name' => 'Saudi look', 'storefront_country' => 'SA']);
        $this->post(route('admin.theme.store'), ['name' => 'Unchosen look']);

        $this->assertSame(['Saudi look' => null, 'Unchosen look' => null], $this->storefrontsOfThemes());
    }

    public function test_a_country_that_is_not_a_storefront_is_refused_and_no_theme_is_made(): void
    {
        $this->throughTheRoute();

        $this->post(route('admin.theme.store'), ['name' => 'Moon look', 'storefront_country' => 'ZZ'])->assertSessionHasErrors('storefront_country');

        $this->assertSame([], $this->storefrontsOfThemes());
    }

    public function test_switched_off_the_choice_is_ignored_and_the_theme_is_what_it_always_was(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $this->throughTheRoute();

        $this->post(route('admin.theme.store'), ['name' => 'Emirati look', 'storefront_country' => 'AE'])->assertSessionHasNoErrors();
        $this->post(route('admin.theme.store'), ['name' => 'Moon look', 'storefront_country' => 'ZZ'])->assertSessionHasNoErrors();

        $this->assertSame(['Emirati look' => null, 'Moon look' => null], $this->storefrontsOfThemes());
    }

    public function test_before_the_migration_the_choice_is_ignored_and_a_theme_is_still_made(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $this->throughTheRoute();

        $this->post(route('admin.theme.store'), ['name' => 'Emirati look', 'storefront_country' => 'AE'])->assertSessionHasNoErrors();

        $this->assertSame(['Emirati look'], Theme::query()->pluck('name')->all());
    }
}
