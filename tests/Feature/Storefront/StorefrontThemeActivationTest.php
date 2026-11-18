<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Services\Theme\ThemeManager;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-01.0: activating one storefront's theme leaves the other storefronts' themes active. Switched off, or before the themes migration has run, one theme is
 * active for the whole platform and activating a theme deactivates every other, as it always did.
 */
class StorefrontThemeActivationTest extends TestCase
{
    use BuildsThemeWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->switchStorefrontThemesOn();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** @return list<string> */
    private function activeThemeNames(): array
    {
        return Theme::query()->where('is_active', true)->orderBy('name')->pluck('name')->all();
    }

    public function test_activating_the_theme_of_another_storefront_leaves_the_default_storefronts_theme_active(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');

        $activated = app(ThemeManager::class)->activate($emirati);

        $this->assertTrue($activated->is_active);
        $this->assertSame(['Emirati', 'Saudi'], $this->activeThemeNames());
    }

    public function test_activating_the_default_storefronts_theme_leaves_the_theme_of_another_storefront_active(): void
    {
        $saudi = $this->giveTheme(name: 'Saudi');
        $this->giveTheme(name: 'Emirati', country: 'AE', active: true);

        app(ThemeManager::class)->activate($saudi);

        $this->assertSame(['Emirati', 'Saudi'], $this->activeThemeNames());
    }

    public function test_activating_a_theme_deactivates_the_other_themes_of_its_own_storefront_only(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $this->giveTheme(name: 'Emirati old', country: 'AE', active: true);
        $this->giveTheme(name: 'Kuwaiti', country: 'KW', active: true);
        $emiratiNew = $this->giveTheme(name: 'Emirati new', country: 'AE');

        app(ThemeManager::class)->activate($emiratiNew);

        $this->assertSame(['Emirati new', 'Kuwaiti', 'Saudi'], $this->activeThemeNames());
    }

    public function test_a_theme_that_names_no_country_and_a_theme_of_the_default_country_are_one_storefront(): void
    {
        $this->giveTheme(name: 'Legacy', active: true);
        $explicit = $this->giveTheme(name: 'Explicit', country: 'SA');

        app(ThemeManager::class)->activate($explicit);

        $this->assertSame(['Explicit'], $this->activeThemeNames());
    }

    public function test_switched_off_activating_a_theme_deactivates_every_other_whatever_its_storefront(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $this->giveTheme(name: 'Saudi', active: true);
        $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $kuwaiti = $this->giveTheme(name: 'Kuwaiti', country: 'KW');

        app(ThemeManager::class)->activate($kuwaiti);

        $this->assertSame(['Kuwaiti'], $this->activeThemeNames());
    }

    public function test_before_the_migration_activating_a_theme_deactivates_every_other(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $this->giveTheme(name: 'First', active: true);
        $second = $this->giveTheme(name: 'Second');

        app(ThemeManager::class)->activate($second);

        $this->assertSame(['Second'], $this->activeThemeNames());
    }
}
