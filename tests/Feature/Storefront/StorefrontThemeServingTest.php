<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemeManager;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-01.1: a request bound to a storefront is served that storefront's published theme, and a storefront without a theme of its own is served the default
 * storefront's. What is cached for one storefront is never served to another.
 */
class StorefrontThemeServingTest extends TestCase
{
    use BuildsThemeWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
        $this->giveStorefrontTheme(name: 'Saudi', country: null, revision: 3, spacerHeight: 41, page: 'saudi-offers', active: true);
        $this->giveStorefrontTheme(name: 'Emirati', country: 'AE', revision: 7, spacerHeight: 42, page: 'emirati-offers', active: true);
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    public function test_the_app_is_told_the_revision_of_the_theme_of_the_storefront_it_asks_for(): void
    {
        $this->getJson('/api/v1/theme/version', ['X-Storefront' => 'AE'])->assertOk()->assertJsonPath('revision', 7)->assertJsonPath('checksum', 'checksum-of-emirati');
        $this->getJson('/api/v1/theme/version', ['X-Storefront' => 'SA'])->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('checksum', 'checksum-of-saudi');
    }

    public function test_the_app_is_given_the_home_page_of_the_theme_of_the_storefront_it_asks_for(): void
    {
        $emirati = $this->getJson('/api/v1/theme/home', ['X-Storefront' => 'AE'])->assertOk();
        $saudi = $this->getJson('/api/v1/theme/home', ['X-Storefront' => 'SA'])->assertOk();

        $this->assertSame([7, 42], [$emirati->json('revision'), $emirati->json('sections.0.settings.height')]);
        $this->assertSame([3, 41], [$saudi->json('revision'), $saudi->json('sections.0.settings.height')]);
    }

    public function test_a_page_only_the_emirati_theme_has_is_served_to_the_emirati_storefront_alone(): void
    {
        $this->getJson('/api/v1/theme/sections?page=emirati-offers', ['X-Storefront' => 'AE'])->assertOk();
        $this->getJson('/api/v1/theme/sections?page=emirati-offers', ['X-Storefront' => 'SA'])->assertNotFound();
        $this->getJson('/api/v1/theme/sections?page=emirati-offers')->assertNotFound();
    }

    public function test_a_request_that_names_no_storefront_is_served_the_default_storefronts_theme(): void
    {
        $this->getJson('/api/v1/theme/version')->assertOk()->assertJsonPath('revision', 3);
    }

    public function test_a_storefront_without_a_theme_of_its_own_is_served_the_default_storefronts(): void
    {
        $this->getJson('/api/v1/theme/version', ['X-Storefront' => 'KW'])->assertOk()->assertJsonPath('revision', 3);
        $this->getJson('/api/v1/theme/home', ['X-Storefront' => 'KW'])->assertOk()->assertJsonPath('sections.0.settings.height', 41);

        $this->serveRequestFromStorefront('KW');
        $this->assertSame(41, $this->drawnSpacerHeight());
    }

    public function test_a_storefront_is_served_its_own_theme_at_once_when_it_is_given_one(): void
    {
        $this->serveRequestFromStorefront('KW');
        $this->assertSame(41, $this->drawnSpacerHeight(), 'the default storefront\'s theme, and it is cached for Kuwait now');
        $this->assertSame(3, app(ThemeDelivery::class)->revision()['revision']);

        $kuwaiti = $this->giveStorefrontTheme(name: 'Kuwaiti', country: 'KW', revision: 11, spacerHeight: 43, page: 'kuwaiti-offers');
        app(ThemeManager::class)->activate($kuwaiti);

        $this->assertSame(43, $this->drawnSpacerHeight());
        $this->assertSame(11, app(ThemeDelivery::class)->revision()['revision']);
    }

    public function test_what_is_cached_for_one_storefront_is_not_served_to_another(): void
    {
        foreach (['AE', 'SA', 'AE', 'SA'] as $storefront) {
            $this->serveRequestFromStorefront($storefront);

            $this->assertSame($storefront === 'AE' ? 42 : 41, $this->drawnSpacerHeight(), $storefront);
            $this->assertSame($storefront === 'AE' ? 7 : 3, app(ThemeDelivery::class)->revision()['revision'], $storefront);
        }
    }

    public function test_the_colours_of_the_page_head_are_those_of_the_theme_of_the_storefront(): void
    {
        Theme::query()->where('name', 'Emirati')->firstOrFail()->publishedVersion->update(['settings' => ['colors' => ['primary' => '#ae0000']]]);
        $renderer = app(StorefrontThemeRenderer::class);
        $manager = app(ThemeManager::class);

        $this->serveRequestFromStorefront('AE');
        $emirati = $renderer->publishedGlobalSettings($manager);
        $this->serveRequestFromStorefront('SA');
        $saudi = $renderer->publishedGlobalSettings($manager);

        $this->assertSame('#ae0000', $emirati['colors']['primary']);
        $this->assertSame($manager->defaultSettings()['colors']['primary'], $saudi['colors']['primary']);
    }

    public function test_a_storefront_whose_own_theme_has_nothing_published_is_served_the_built_in_pages_not_the_default_storefronts_theme(): void
    {
        ThemeVersion::query()->whereHas('theme', fn ($themes) => $themes->where('name', 'Emirati'))->update(['status' => ThemeVersion::STATUS_ARCHIVED]);

        $this->serveRequestFromStorefront('AE');

        $this->assertNull($this->drawnSpacerHeight());
        $this->assertSame(0, app(ThemeDelivery::class)->revision()['revision']);
    }

    public function test_while_storefronts_are_off_every_request_is_served_the_default_storefronts_theme_even_one_a_payment_callback_bound_to_another(): void
    {
        config(['gcc.storefronts.enabled' => false]);
        $this->app->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->contextOfRecordedCountry('AE'));

        $this->assertSame('AE', StorefrontContext::current()->country, 'the callback of an Emirati order is bound to the Emirati storefront');
        $this->assertSame(41, $this->drawnSpacerHeight());
        $this->assertSame(3, app(ThemeDelivery::class)->revision()['revision']);

        $this->app->forgetInstance(StorefrontContext::class);
        $this->assertSame(41, $this->drawnSpacerHeight(), 'the copy the Saudi storefront reads is the Saudi theme, as the keys of the caches are not per storefront while they are off');
    }

    public function test_the_admin_panel_works_on_the_default_storefronts_theme_whatever_other_theme_was_activated_last(): void
    {
        $this->app->forgetInstance(StorefrontContext::class);

        $published = app(ThemeManager::class)->activeThemePublishedVersion();

        $this->assertSame('Saudi', $published->theme->name);
    }
}
