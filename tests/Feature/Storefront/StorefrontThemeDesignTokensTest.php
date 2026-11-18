<?php

namespace Tests\Feature\Storefront;

use App\Models\ThemeVersion;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-12: the design tokens of a storefront are the settings of the published version of its theme laid over the code defaults, key by key, and the website and the app are given the same ones. A
 * version that sets a key changes that key only (a key the defaults do not know comes through as well), a version that sets nothing is the defaults, and the colours, the type size and the corners the
 * partial theme-global-tokens writes as CSS variables are the values the app payload carries as `tokens`, for a token the version overrides and for one it leaves at the code default.
 *
 * The web side is that partial rendered with the settings StorefrontThemeRenderer::publishedGlobalSettings resolves for the published theme: four tokens, written as nine variables and two RGB values. That
 * the layout renders the partial with those settings is pinned structurally only (a string match on the layout's source), and the draft-preview branch of publishedGlobalSettings is not covered. The
 * branding group is compared on its own: the logo sizes a merchant sets are numbers and reach the app as numbers.
 */
class StorefrontThemeDesignTokensTest extends TestCase
{
    use BuildsThemeWorld;

    /** What the version of each storefront's theme sets, and nothing else: each leaves some of the four tokens the page head writes at the code default. */
    private const OVERRIDES = [
        'SA' => ['colors' => ['primary' => '#ae0000'], 'typography' => ['base_font_size' => 19]],
        'AE' => ['colors' => ['secondary' => '#00a651'], 'layout' => ['border_radius' => 20]],
    ];

    /** The groups of tokens the app payload carries as the settings resolve them and that the page head can be compared with: the page head writes no variable from the branding group (see the test of the logo sizes). */
    private const TOKENS_OF_THE_APP = ['colors', 'typography', 'layout'];

    /** The CSS variables the page head writes from the settings: the token each comes from, and what is written after its value. */
    private const WEB_VARIABLES = [
        '--web-primary' => ['colors', 'primary', ''],
        '--base' => ['colors', 'primary', ''],
        '--web-primary-10' => ['colors', 'primary', '10'],
        '--web-primary-20' => ['colors', 'primary', '20'],
        '--web-primary-40' => ['colors', 'primary', '40'],
        '--web-secondary' => ['colors', 'secondary', ''],
        '--base-2' => ['colors', 'secondary', ''],
        '--theme-base-font-size' => ['typography', 'base_font_size', 'px'],
        '--theme-border-radius' => ['layout', 'border_radius', 'px'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, mixed>}> the keys a version sets, by their path: keys the code defaults hold with another value, and keys they do not hold at all */
    public static function whatAVersionSets(): array
    {
        return [
            'one colour' => [['colors.primary' => '#112233']],
            'one key of every group' => [['branding.logo_width' => 200, 'colors.text' => '#000000', 'typography.line_height' => 1.4, 'layout.container_width' => 1400]],
            'keys whose default is nothing' => [['branding.logo' => '/storage/logo.png', 'branding.favicon' => '/storage/favicon.ico']],
            'a number and a word' => [['typography.base_font_size' => 18, 'layout.button_style' => 'square']],
            'a key the defaults do not know, in a group they do' => [['colors.brand_gradient' => '#123456']],
            'a group the defaults do not know' => [['promotion.badge_color' => '#fa5252', 'promotion.badge_shape' => 'pill']],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>|null|false}> what a version that sets no token holds: no version, no settings, empty settings, and groups with nothing in them */
    public static function versionsThatSetNothing(): array
    {
        return [
            'no version at all' => [false],
            'a version that has no settings' => [null],
            'a version with empty settings' => [[]],
            'a version that names groups and sets nothing in them' => [['branding' => [], 'colors' => [], 'typography' => [], 'layout' => []]],
        ];
    }

    private function givePublishedThemeTo(string $country): void
    {
        $theme = $this->giveStorefrontTheme(name: "Theme of {$country}", country: $country === 'SA' ? null : $country, revision: 1, spacerHeight: 10, page: "offers-of-{$country}", active: true);
        $theme->publishedVersion->update(['settings' => self::OVERRIDES[$country]]);
    }

    /** @return array<string, string> the CSS variables the page head of a storefront writes from the settings of its published theme, by name */
    private function cssVariablesOfThePageHeadOf(string $country): array
    {
        $this->serveRequestFromStorefront(country: $country);
        $settings = app(StorefrontThemeRenderer::class)->publishedGlobalSettings(manager: app(ThemeManager::class));
        $this->assertNotNull($settings, "{$country} has a published theme");

        preg_match_all('/(--[\w-]+):\s*([^;]+);/', view('partials.theme-global-tokens', ['themeSettings' => $settings])->render(), $found);

        return array_combine($found[1], array_map('trim', $found[2]));
    }

    /** @return array<string, array<string, mixed>> the design tokens the home payload gives an app that asks as a storefront */
    private function tokensTheAppIsGivenBy(string $country): array
    {
        return $this->getJson('/api/v1/theme/home', ['X-Storefront' => $country])->assertOk()->json('tokens');
    }

    // ---- the settings of a version laid over the code defaults (F-12.0) -----------------------------------------------------------------------

    #[DataProvider('whatAVersionSets')]
    public function test_the_keys_a_version_sets_replace_the_code_defaults_one_by_one_and_every_other_key_keeps_its_default(array $set): void
    {
        $manager = app(ThemeManager::class);
        $defaults = $manager->defaultSettings();
        $expected = $defaults;
        foreach ($set as $path => $value) {
            Arr::set(array: $expected, key: $path, value: $value);
        }

        $resolved = $manager->resolveSettings(version: new ThemeVersion(['settings' => Arr::undot(array: $set)]));

        $this->assertSame($expected, $resolved);
        $this->assertEqualsCanonicalizing(array_keys($set), array_keys(array_diff_assoc(Arr::dot(array: $resolved), Arr::dot(array: $defaults))), 'a key the version did not set differs from its default, or one it set does not');
    }

    #[DataProvider('versionsThatSetNothing')]
    public function test_a_version_that_sets_nothing_resolves_to_the_code_defaults(array|false|null $settings): void
    {
        $manager = app(ThemeManager::class);

        $resolved = $manager->resolveSettings(version: $settings === false ? null : new ThemeVersion(['settings' => $settings]));

        $this->assertSame($manager->defaultSettings(), $resolved);
        $this->assertNotSame([], $resolved['colors'], 'the defaults hold something to resolve to');
    }

    public function test_a_version_read_back_from_the_database_resolves_the_same_way(): void
    {
        $manager = app(ThemeManager::class);
        $version = $this->giveStorefrontTheme(name: 'Saudi', country: null, revision: 1, spacerHeight: 10, page: 'offers', active: true)->publishedVersion;
        $version->update(['settings' => ['colors' => ['primary' => '#112233'], 'layout' => ['border_radius' => 14]]]);

        $resolved = $manager->resolveSettings(version: $version->fresh());

        $expected = $manager->defaultSettings();
        $expected['colors']['primary'] = '#112233';
        $expected['layout']['border_radius'] = 14;
        $this->assertEquals($expected, $resolved);
    }

    // ---- the same tokens on the web and in the app (F-12.1) -------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function storefrontsWithATheme(): array
    {
        return ['the default storefront' => ['SA'], 'the Emirati storefront' => ['AE']];
    }

    #[DataProvider('storefrontsWithATheme')]
    public function test_the_page_head_and_the_app_are_given_the_same_tokens_for_a_token_the_version_overrides_and_for_one_it_does_not(string $country): void
    {
        $this->givePublishedThemeTo(country: 'SA');
        $this->givePublishedThemeTo(country: 'AE');
        $defaults = app(ThemeManager::class)->defaultSettings();
        $expected = array_replace_recursive($defaults, self::OVERRIDES[$country]);

        $web = $this->cssVariablesOfThePageHeadOf(country: $country);
        $app = $this->tokensTheAppIsGivenBy(country: $country);

        foreach (self::WEB_VARIABLES as $variable => [$group, $token, $suffix]) {
            $this->assertSame($expected[$group][$token] . $suffix, $web[$variable], "{$variable} on the web");
            $this->assertSame($expected[$group][$token], $app[$group][$token], "{$group}.{$token} in the app");
        }
        foreach (['--web-primary-rgb', '--bs-base-rgb'] as $variable) {
            $this->assertSame(getHexToRGBColorCode(hex: $app['colors']['primary']), $web[$variable], "{$variable} follows the primary colour of the app");
        }
        foreach (Arr::dot(array: self::OVERRIDES[$country]) as $path => $value) {
            $this->assertNotSame(Arr::get(array: $defaults, key: $path), $value, "{$path} is overridden with the value of the default, so the check below proves nothing");
        }
        foreach (Arr::dot(array: Arr::only(array: $expected, keys: self::TOKENS_OF_THE_APP)) as $path => $value) {
            $this->assertSame($value, Arr::get(array: $app, key: $path), "{$path} in the app");
        }
    }

    public function test_the_logo_sizes_a_merchant_sets_reach_the_app_as_numbers_and_the_code_defaults_arrive_when_none_is_set(): void
    {
        $defaults = app(ThemeManager::class)->defaultSettings()['branding'];
        $this->givePublishedThemeTo(country: 'SA');
        $this->givePublishedThemeTo(country: 'AE');
        $aeVersion = ThemeVersion::query()->whereHas('theme', fn ($themes) => $themes->where('storefront_country', 'AE'))->firstOrFail();
        $aeVersion->update(['settings' => array_replace_recursive(self::OVERRIDES['AE'], ['branding' => ['logo_width' => 200, 'logo_height' => 60]])]);

        $this->assertSame([200, 60], [$this->tokensTheAppIsGivenBy(country: 'AE')['branding']['logo_width'], $this->tokensTheAppIsGivenBy(country: 'AE')['branding']['logo_height']]);
        $this->assertSame([$defaults['logo_width'], $defaults['logo_height']], [$this->tokensTheAppIsGivenBy(country: 'SA')['branding']['logo_width'], $this->tokensTheAppIsGivenBy(country: 'SA')['branding']['logo_height']], 'a theme that sets no size gives the code default one');
        $this->assertIsInt($defaults['logo_width']);
    }

    public function test_the_two_storefronts_themes_give_two_sets_of_tokens_to_both_the_page_head_and_the_app(): void
    {
        $this->givePublishedThemeTo(country: 'SA');
        $this->givePublishedThemeTo(country: 'AE');

        $this->assertNotSame($this->cssVariablesOfThePageHeadOf(country: 'SA'), $this->cssVariablesOfThePageHeadOf(country: 'AE'));
        $this->assertNotSame($this->tokensTheAppIsGivenBy(country: 'SA'), $this->tokensTheAppIsGivenBy(country: 'AE'));
    }

    /** A structural pin, not a rendering: the layout's source holds the call that gives the partial the published settings of the storefront and the include of the partial. A refactor that keeps the behaviour (a view composer, a split call) moves the pin. */
    public function test_the_page_head_hands_the_partial_the_published_settings_of_the_storefront(): void
    {
        $layout = (string) file_get_contents(resource_path('themes/default/layouts/front-end/app.blade.php'));

        $this->assertStringContainsString('app(\App\Services\Theme\StorefrontThemeRenderer::class)->publishedGlobalSettings(app(\App\Services\Theme\ThemeManager::class))', $layout);
        $this->assertStringContainsString("@include('partials.theme-global-tokens', ['themeSettings' => \$__themeSettings])", $layout);
    }
}
