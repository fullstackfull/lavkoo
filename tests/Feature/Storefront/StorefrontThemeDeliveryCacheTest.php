<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\LocalisedSettings;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-04.2 to F-04.4 and F-09.1, through the app routes: what the theme caches are keyed by.
 *
 * The world has a Saudi theme (it names no country, so it is the default storefront's, and the one Kuwait falls back to) and an Emirati one. Each home page is a spacer, whose height tells the
 * theme apart, and a greeting with an Arabic text beside its base one, which tells the language apart. The keys the tests expect are written out from the documented scheme
 * (docs/gcc/F-01-STOREFRONT-THEME.md and StorefrontCacheKeysTest) and not computed by the code under test: the default storefront keeps the key it always had, every other storefront writes it under
 * `storefront:{country}:`, and a delivered page is `theme_delivery_{page}_{fingerprint}`, the fingerprint being the first 24 hex digits of the SHA-256 of the stamp of the published version
 * (its checksum, `#`, its revision), the platform, the device, the audience, the segments, the language, the engine, the schema and the sorted components of the client.
 */
class StorefrontThemeDeliveryCacheTest extends TestCase
{
    use BuildsThemeWorld;

    /** What a customer app sends: the section types it can draw, the engine and the schema it runs, and its language. */
    private const APP = ['X-UI-Components' => 'spacer,custom_html', 'X-UI-Engine' => '1', 'X-UI-Schema' => '1', 'lang' => 'en'];

    /** The checksum of the published version of a theme, `#`, and its revision: the first part of every fingerprint. */
    private const SAUDI_STAMP = 'checksum-of-saudi#3';
    private const EMIRATI_STAMP = 'checksum-of-emirati#7';

    /** The stamp of the theme each storefront is served: Kuwait has none of its own. */
    private const STAMP_SERVED = ['SA' => self::SAUDI_STAMP, 'AE' => self::EMIRATI_STAMP, 'KW' => self::SAUDI_STAMP];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->offerEnglishAndArabic();
    }

    protected function tearDown(): void
    {
        LocalisedSettings::forget();
        $this->dropThemeWorld();

        parent::tearDown();
    }

    private function offerEnglishAndArabic(): void
    {
        Cache::put('language', [
            ['id' => 1, 'name' => 'English', 'code' => 'en', 'status' => 1, 'default' => true],
            ['id' => 2, 'name' => 'Arabic', 'code' => 'ar', 'status' => 1, 'default' => false],
        ]);
        LocalisedSettings::forget();
    }

    /** A published theme whose home page is a spacer of the given height and a greeting that has an Arabic text beside its base one. */
    private function givePublishedGreeting(string $name, ?string $country, int $revision, int $spacerHeight, string $city): void
    {
        $theme = $this->giveTheme(name: $name, country: $country, active: true);
        $version = $this->givePublishedVersion(theme: $theme, revision: $revision, spacerHeight: $spacerHeight);
        ThemeSection::query()->create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'custom_html', 'sort_order' => 2,
            'settings' => ['content' => "Hello from {$city}", 'content_ar' => "Marhaba from {$city}"],
        ]);
    }

    private function giveStorefrontsTheirThemes(): void
    {
        $this->givePublishedGreeting(name: 'Saudi', country: null, revision: 3, spacerHeight: 41, city: 'Riyadh');
        $this->givePublishedGreeting(name: 'Emirati', country: 'AE', revision: 7, spacerHeight: 42, city: 'Dubai');
    }

    private function sectionOf(string $theme, string $type): ThemeSection
    {
        return Theme::query()->where('name', $theme)->firstOrFail()->publishedVersion->sections()->where('type', $type)->firstOrFail();
    }

    /** @param array<string, string> $headers what this client sends instead of what the app sends */
    private function homeFor(string $storefront, array $headers = []): TestResponse
    {
        return $this->getJson('/api/v1/theme/home', ['X-Storefront' => $storefront] + $headers + self::APP)->assertOk();
    }

    /** @return array{0: int, 1: string} the revision and the checksum the app is told for a storefront */
    private function stampAnswered(string $storefront): array
    {
        $version = $this->getJson('/api/v1/theme/version', ['X-Storefront' => $storefront])->assertOk();

        return [$version->json('revision'), $version->json('checksum')];
    }

    private function spacerHeight(TestResponse $home): int
    {
        return $home->json('sections.0.settings.height');
    }

    private function greeting(TestResponse $home): string
    {
        return $home->json('sections.1.settings.content');
    }

    /** What a storefront writes its keys under: nothing for the default storefront, which keeps the keys it always had. */
    private function scopeOf(string $storefront): string
    {
        return $storefront === 'SA' ? '' : "storefront:{$storefront}:";
    }

    /** The scope a storefront must not write its keys under: that of the other kind of storefront. */
    private function otherScopeThan(string $storefront): string
    {
        return $storefront === 'SA' ? 'storefront:SA:' : '';
    }

    /**
     * The key of the page the delivery caches for the client that sent these headers, without the scope of its storefront.
     *
     * @param array<string, string> $client
     */
    private function deliveredPageKey(string $stamp, array $client = self::APP): string
    {
        $components = array_map('trim', explode(',', $client['X-UI-Components']));
        sort($components);
        $fingerprint = substr(hash('sha256', implode('|', [
            $stamp, 'app', 'mobile', 'guest', '', $client['lang'], $client['X-UI-Engine'], $client['X-UI-Schema'], implode(',', $components),
        ])), 0, 24);

        return "theme_delivery_home_{$fingerprint}";
    }

    // ---- F-04.2: the entries are written under the key of the storefront that asked for them ---------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function storefronts(): array
    {
        return ['the default storefront' => ['SA'], 'a storefront with a theme of its own' => ['AE'], 'a storefront served the default storefront\'s theme' => ['KW']];
    }

    #[DataProvider('storefronts')]
    public function test_the_app_delivery_is_cached_under_the_key_of_the_storefront_that_asked_for_it(string $storefront): void
    {
        $this->giveStorefrontsTheirThemes();

        $this->homeFor(storefront: $storefront);

        foreach (['theme_delivery_revision', $this->deliveredPageKey(stamp: self::STAMP_SERVED[$storefront])] as $key) {
            $this->assertTrue(Cache::has($this->scopeOf(storefront: $storefront) . $key), "[{$this->scopeOf(storefront: $storefront)}{$key}] was not written");
            $this->assertFalse(Cache::has($this->otherScopeThan(storefront: $storefront) . $key), "[{$this->otherScopeThan(storefront: $storefront)}{$key}] was written as well");
        }
    }

    #[DataProvider('storefronts')]
    public function test_the_page_renderer_writes_its_pages_and_its_colours_under_the_key_of_the_storefront_that_asked_for_them(string $storefront): void
    {
        $this->giveStorefrontsTheirThemes();

        $this->getJson('/api/v1/theme/sections?page=home', ['X-Storefront' => $storefront])->assertOk();
        $this->serveRequestFromStorefront($storefront);
        app(StorefrontThemeRenderer::class)->publishedGlobalSettings(app(ThemeManager::class));

        foreach (['storefront_theme_page_home', 'storefront_theme_global_settings'] as $key) {
            $this->assertTrue(Cache::has($this->scopeOf(storefront: $storefront) . $key), "[{$this->scopeOf(storefront: $storefront)}{$key}] was not written");
            $this->assertFalse(Cache::has($this->otherScopeThan(storefront: $storefront) . $key), "[{$this->otherScopeThan(storefront: $storefront)}{$key}] was written as well");
        }
    }

    public function test_while_storefronts_are_off_every_entry_is_written_under_the_key_it_always_had(): void
    {
        $this->giveStorefrontsTheirThemes();
        config(['gcc.storefronts.enabled' => false]);

        $this->homeFor(storefront: 'AE');
        $this->getJson('/api/v1/theme/sections?page=home', ['X-Storefront' => 'AE'])->assertOk();

        foreach (['theme_delivery_revision', $this->deliveredPageKey(stamp: self::SAUDI_STAMP), 'storefront_theme_page_home'] as $key) {
            $this->assertTrue(Cache::has($key), "[{$key}] was not written");
            $this->assertFalse(Cache::has("storefront:AE:{$key}"), "[storefront:AE:{$key}] was written");
        }
    }

    // ---- F-04.3: a page cached for one language is never served for another ---------------------------------------------------------------

    public function test_a_page_cached_for_one_language_is_never_served_for_another_and_each_is_served_from_its_own_entry(): void
    {
        $this->giveStorefrontsTheirThemes();
        $arabic = ['lang' => 'ar'] + self::APP;

        $this->assertSame('Hello from Riyadh', $this->greeting($this->homeFor(storefront: 'SA')));
        $this->assertSame('Marhaba from Riyadh', $this->greeting($this->homeFor(storefront: 'SA', headers: $arabic)));
        $this->assertTrue(Cache::has($this->deliveredPageKey(stamp: self::SAUDI_STAMP)), 'the English entry');
        $this->assertTrue(Cache::has($this->deliveredPageKey(stamp: self::SAUDI_STAMP, client: $arabic)), 'the Arabic entry');
        $this->assertNotSame($this->deliveredPageKey(stamp: self::SAUDI_STAMP), $this->deliveredPageKey(stamp: self::SAUDI_STAMP, client: $arabic));

        $this->sectionOf(theme: 'Saudi', type: 'custom_html')->update(['settings' => ['content' => 'Rewritten', 'content_ar' => 'Rewritten in Arabic']]);

        $this->assertSame('Hello from Riyadh', $this->greeting($this->homeFor(storefront: 'SA')));
        $this->assertSame('Marhaba from Riyadh', $this->greeting($this->homeFor(storefront: 'SA', headers: $arabic)));
    }

    // ---- F-04.4: a page cached for one storefront is never served to another -------------------------------------------------------------

    /**
     * Each order of two storefronts, with the two themes as they are (two stamps, so two fingerprints) and with the same stamp on both, which leaves the storefront as the one thing that
     * tells the two entries of the same page and the same client apart.
     *
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    public static function ordersOfStorefrontsAndStamps(): array
    {
        return [
            'Emirati then Saudi, each with its own stamp' => [['AE', 'SA'], false],
            'Saudi then Emirati, each with its own stamp' => [['SA', 'AE'], false],
            'Emirati then Saudi, the same stamp' => [['AE', 'SA'], true],
            'Saudi then Emirati, the same stamp' => [['SA', 'AE'], true],
        ];
    }

    /** @param list<string> $order */
    #[DataProvider('ordersOfStorefrontsAndStamps')]
    public function test_a_page_cached_for_one_storefront_is_never_served_to_another_for_the_same_page_and_the_same_client(array $order, bool $sameStamp): void
    {
        $this->giveStorefrontsTheirThemes();
        if ($sameStamp) {
            ThemeVersion::query()->update(['revision' => 5, 'checksum' => 'one-checksum-for-both']);
            $this->assertSame([5, 'one-checksum-for-both'], $this->stampAnswered(storefront: 'AE'), 'the premise: nothing but the storefront tells the two themes apart');
            $this->assertSame([5, 'one-checksum-for-both'], $this->stampAnswered(storefront: 'SA'), 'the premise: nothing but the storefront tells the two themes apart');
        }

        foreach ([...$order, ...$order] as $storefront) {
            $home = $this->homeFor(storefront: $storefront);

            $this->assertSame($storefront === 'AE' ? 42 : 41, $this->spacerHeight($home), $storefront);
            $this->assertSame($storefront === 'AE' ? 'Hello from Dubai' : 'Hello from Riyadh', $this->greeting($home), $storefront);
        }
    }

    // ---- F-09.1: what makes the pages of two clients two entries --------------------------------------------------------------------------

    /**
     * What a client may send differently from the app that was served before it, and whether that makes its page another entry of the cache. The same components in another order, with spaces,
     * are the same capabilities, and a repeated request is the same client.
     *
     * @return array<string, array{0: array<string, string>, 1: bool}>
     */
    public static function whatAClientMaySendDifferently(): array
    {
        return [
            'the schema it parses' => [['X-UI-Schema' => '2'], true],
            'the engine it runs' => [['X-UI-Engine' => '2'], true],
            'the components it can draw' => [['X-UI-Components' => 'spacer'], true],
            'its language' => [['lang' => 'ar'], true],
            'nothing' => [[], false],
            'the same components in another order, with spaces' => [['X-UI-Components' => ' custom_html , spacer'], false],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('whatAClientMaySendDifferently')]
    public function test_the_page_of_a_client_is_another_entry_of_the_cache_when_one_thing_it_sends_changes_and_the_same_entry_when_nothing_does(array $headers, bool $isAnotherEntry): void
    {
        $this->giveStorefrontsTheirThemes();
        $this->assertSame(41, $this->spacerHeight($this->homeFor(storefront: 'SA')));
        $this->sectionOf(theme: 'Saudi', type: 'spacer')->update(['settings' => ['height' => 99]]);

        $served = $this->spacerHeight($this->homeFor(storefront: 'SA', headers: $headers));

        $this->assertSame($isAnotherEntry ? 99 : 41, $served, $isAnotherEntry ? 'served the page cached for the client before it' : 'built the page again');
    }
}
