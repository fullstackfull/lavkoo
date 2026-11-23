<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-12.2 and F-12.3: one shared design identity, and a storefront override that changes one storefront only. The shared identity is the settings of the default storefront's published theme, which
 * every other storefront is served when it has no theme of its own and which it inherits, key by key, when it has one that does not set the key. A storefront's own theme overrides the keys it sets and no other.
 *
 * The shared identity changes in two ways, and both are read from every other storefront: the default storefront publishes an edit of the theme it has activated, and the default storefront activates another theme.
 * An edit that is only a draft is neither of them and reaches nobody.
 *
 * Everything here goes through the services a merchant's actions go through: a theme is made, published and activated by ThemeManager, the app is given its tokens by /api/v1/theme/home (and told by
 * /api/v1/theme/version whether to ask), and the page head reads them from StorefrontThemeRenderer. Each storefront reads its tokens before a change and after it, because what is cached under the key of a
 * storefront is what it is served until something forgets it: the new value has to be served after a change, not only stored. The shared identity here sets values the code defaults do not hold, or what a
 * storefront inherits could not be told from what it was never given.
 */
class StorefrontThemeSharedIdentityTest extends TestCase
{
    use BuildsThemeWorld;

    /** What the default storefront's theme sets: the shared identity, four values of three groups that the code defaults hold otherwise. */
    private const SHARED_IDENTITY = ['colors' => ['primary' => '#ae0000', 'text' => '#111111'], 'typography' => ['base_font_size' => 19], 'layout' => ['border_radius' => 12]];

    /** What the shared identity is changed to: every value of it, and one more. */
    private const CHANGED_IDENTITY = ['colors' => ['primary' => '#005a5a', 'text' => '#222222'], 'typography' => ['base_font_size' => 17], 'layout' => ['border_radius' => 4, 'container_width' => 1100]];

    /** What the Emirati storefront overrides: one colour. */
    private const EMIRATI_OVERRIDE = ['colors' => ['secondary' => '#00a651']];

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

    // ---- the services a merchant's actions go through ---------------------------------------------------------------------------------------

    /** A theme made, published with these settings and activated, as the theme screens do it. */
    private function publishThemeOf(?string $country, array $settings, string $name): Theme
    {
        $manager = app(ThemeManager::class);
        $theme = $manager->createTheme(attributes: ['name' => $name, 'slug' => str($name)->slug()->value(), 'is_active' => false, 'is_system' => false, 'storefront_country' => $country], settings: $settings);
        $manager->publish(version: $theme->versions()->firstOrFail());

        return $manager->activate(theme: $theme);
    }

    /** What a merchant does in the theme settings of a theme: a draft of its published version with these settings, published. */
    private function republish(Theme $theme, array $settings): ThemeVersion
    {
        $manager = app(ThemeManager::class);
        $draft = $manager->createDraftFrom(source: $theme->publishedVersion()->firstOrFail());
        $draft->update(['settings' => $settings === [] ? null : $settings]);

        return $manager->publish(version: $draft);
    }

    // ---- what each storefront is given ------------------------------------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> the design tokens the home payload gives an app that asks as a storefront: colours, typography, layout and branding */
    private function tokensTheAppIsGivenBy(string $country): array
    {
        return $this->getJson('/api/v1/theme/home', ['X-Storefront' => $country])->assertOk()->json('tokens');
    }

    /** @return array{0: int, 1: string} what the cheap question of the app is answered for a storefront: the revision and the checksum the app compares with what it holds */
    private function stampTheAppIsToldFor(string $country): array
    {
        $version = $this->getJson('/api/v1/theme/version', ['X-Storefront' => $country])->assertOk();

        return [$version->json('revision'), $version->json('checksum')];
    }

    /** @return array{0: ?string, 1: int|float|null} the logo and its width the home payload gives an app that asks as a storefront */
    private function logoTheAppIsGivenBy(string $country): array
    {
        $branding = $this->tokensTheAppIsGivenBy(country: $country)['branding'];

        return [$branding['logo'], $branding['logo_width']];
    }

    /** @return array<string, array<string, mixed>> the settings the page head of the website writes its variables from, as the storefront that asks */
    private function settingsOfThePageHeadOf(string $country): array
    {
        $this->serveRequestFromStorefront(country: $country);

        return app(StorefrontThemeRenderer::class)->publishedGlobalSettings(manager: app(ThemeManager::class)) ?? [];
    }

    /** @return array<string, mixed> every token of the groups the page head and the app share, as one list of paths and values */
    private function sharedTokens(array $tokens): array
    {
        return Arr::dot(array: Arr::only(array: $tokens, keys: ['colors', 'typography', 'layout']));
    }

    /** What the tokens of a storefront must be: the code defaults, laid under every layer given, the last one winning key by key. */
    private function expectedTokens(array ...$layers): array
    {
        return $this->sharedTokens(array_replace_recursive(app(ThemeManager::class)->defaultSettings(), ...$layers));
    }

    private function assertGiven(string $country, array $expected, string $when): void
    {
        $this->assertSame($expected, $this->sharedTokens($this->tokensTheAppIsGivenBy(country: $country)), "{$when}, the app of {$country}");
        $this->assertSame($expected, $this->sharedTokens($this->settingsOfThePageHeadOf(country: $country)), "{$when}, the page head of {$country}");
    }

    // ---- F-12.2: a storefront without overrides inherits the shared identity, and a change to it reaches that storefront ---------------------

    /** @return array<string, array{0: bool}> whether the Emirati storefront has a theme of its own that sets no token, or none at all */
    public static function emiratiStorefrontsThatOverrideNothing(): array
    {
        return ['a storefront with no theme of its own' => [false], 'a storefront with a theme of its own that sets nothing' => [true]];
    }

    #[DataProvider('emiratiStorefrontsThatOverrideNothing')]
    public function test_a_storefront_that_overrides_nothing_is_given_the_shared_identity_and_a_change_of_it_reaches_the_storefront_whatever_it_had_cached(bool $hasATheme): void
    {
        $saudi = $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        if ($hasATheme) {
            $this->publishThemeOf(country: 'AE', settings: [], name: 'Emirati');
        }
        $shared = $this->expectedTokens(self::SHARED_IDENTITY);
        $this->assertGiven(country: 'AE', expected: $shared, when: 'before the change');
        $this->assertGiven(country: 'SA', expected: $shared, when: 'before the change');
        $stampBefore = $this->stampTheAppIsToldFor(country: 'AE');

        $this->republish(theme: $saudi, settings: self::CHANGED_IDENTITY);

        $changed = $this->expectedTokens(self::CHANGED_IDENTITY);
        $this->assertNotSame($shared, $changed, 'the premise: the change is a change');
        $this->assertGiven(country: 'AE', expected: $changed, when: 'after the change');
        $this->assertGiven(country: 'SA', expected: $changed, when: 'after the change');
        $this->assertNotSame($stampBefore, $this->stampTheAppIsToldFor(country: 'AE'), 'the app is told there is something new');
    }

    // ---- F-12.3: a storefront override changes only that storefront --------------------------------------------------------------------------

    public function test_an_override_of_one_key_changes_that_key_of_that_storefront_and_nothing_of_any_other_and_the_keys_it_does_not_set_are_inherited(): void
    {
        $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $shared = $this->expectedTokens(self::SHARED_IDENTITY);
        foreach (['AE', 'SA', 'KW'] as $country) {
            $this->assertGiven(country: $country, expected: $shared, when: 'before the override');
        }

        $this->publishThemeOf(country: 'AE', settings: self::EMIRATI_OVERRIDE, name: 'Emirati');

        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::SHARED_IDENTITY, self::EMIRATI_OVERRIDE), when: 'after the override');
        foreach (['from what they cached before', 'from an empty cache'] as $source) {
            $this->assertGiven(country: 'SA', expected: $shared, when: "after the override, {$source}");
            $this->assertGiven(country: 'KW', expected: $shared, when: "after the override, {$source}");
            Cache::flush();
        }
    }

    public function test_a_change_of_the_shared_identity_reaches_the_keys_a_storefront_inherits_and_leaves_the_key_it_overrides_alone(): void
    {
        $saudi = $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $overridden = ['colors' => ['primary' => '#00a651']];
        $this->publishThemeOf(country: 'AE', settings: $overridden, name: 'Emirati');
        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::SHARED_IDENTITY, $overridden), when: 'before the change');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::SHARED_IDENTITY), when: 'before the change');

        $this->republish(theme: $saudi, settings: self::CHANGED_IDENTITY);

        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::CHANGED_IDENTITY, $overridden), when: 'after the change');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::CHANGED_IDENTITY), when: 'after the change');
    }

    public function test_removing_the_override_returns_the_storefront_to_the_shared_identity_whatever_it_had_cached(): void
    {
        $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $emirati = $this->publishThemeOf(country: 'AE', settings: self::EMIRATI_OVERRIDE, name: 'Emirati');
        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::SHARED_IDENTITY, self::EMIRATI_OVERRIDE), when: 'with the override');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::SHARED_IDENTITY), when: 'with the override');

        $this->republish(theme: $emirati, settings: []);

        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::SHARED_IDENTITY), when: 'without the override');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::SHARED_IDENTITY), when: 'without the override');
    }

    public function test_the_logo_of_the_shared_identity_is_the_logo_of_a_storefront_until_it_sets_its_own_and_the_size_it_does_not_set_stays_the_shared_one(): void
    {
        $this->publishThemeOf(country: null, settings: ['branding' => ['logo' => '/storage/shared-logo.png', 'logo_width' => 180]], name: 'Saudi');
        $emirati = $this->publishThemeOf(country: 'AE', settings: [], name: 'Emirati');
        $this->assertSame([url('/storage/shared-logo.png'), 180], $this->logoTheAppIsGivenBy(country: 'AE'), 'before the override');

        $this->republish(theme: $emirati, settings: ['branding' => ['logo' => '/storage/emirati-logo.png']]);

        $this->assertSame([url('/storage/emirati-logo.png'), 180], $this->logoTheAppIsGivenBy(country: 'AE'), 'after the override');
        $this->assertSame([url('/storage/shared-logo.png'), 180], $this->logoTheAppIsGivenBy(country: 'SA'), 'the default storefront');
    }

    // ---- the two ways the shared identity changes: the default storefront publishes, or it activates another theme -------------------------

    /** @return array<string, array{0: array<string, mixed>|null}> what the Emirati storefront's own theme sets, or null where it has no theme of its own and is served the default storefront's */
    public static function emiratiStorefrontsBeneathTheIdentity(): array
    {
        return [
            'a storefront with no theme of its own' => [null],
            'a storefront whose own theme sets nothing' => [[]],
            'a storefront whose own theme overrides one colour' => [self::EMIRATI_OVERRIDE],
        ];
    }

    /** @param array<string, mixed>|null $emiratiSettings */
    #[DataProvider('emiratiStorefrontsBeneathTheIdentity')]
    public function test_an_edit_of_the_default_storefronts_theme_that_is_not_published_reaches_no_other_storefront(?array $emiratiSettings): void
    {
        $saudi = $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        if ($emiratiSettings !== null) {
            $this->publishThemeOf(country: 'AE', settings: $emiratiSettings, name: 'Emirati');
        }
        $inherited = $this->expectedTokens(self::SHARED_IDENTITY, $emiratiSettings ?? []);
        $this->assertGiven(country: 'AE', expected: $inherited, when: 'before the edit');
        $told = $this->stampTheAppIsToldFor(country: 'AE');

        $draft = app(ThemeManager::class)->createDraftFrom(source: $saudi->publishedVersion()->firstOrFail());
        $draft->update(['settings' => self::CHANGED_IDENTITY]);
        Cache::flush();

        $this->assertNotSame($inherited, $this->expectedTokens(self::CHANGED_IDENTITY, $emiratiSettings ?? []), 'the premise: the edit is a change');
        $this->assertGiven(country: 'AE', expected: $inherited, when: 'while the edit is only a draft');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::SHARED_IDENTITY), when: 'while the edit is only a draft');
        $this->assertSame($told, $this->stampTheAppIsToldFor(country: 'AE'), 'an edit that is not live is nothing for the app to refetch for');

        app(ThemeManager::class)->publish(version: $draft->fresh());

        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::CHANGED_IDENTITY, $emiratiSettings ?? []), when: 'once the edit is published');
        $this->assertNotSame($told, $this->stampTheAppIsToldFor(country: 'AE'), 'the app is told there is something new once the edit is live');
    }

    /** @param array<string, mixed>|null $emiratiSettings */
    #[DataProvider('emiratiStorefrontsBeneathTheIdentity')]
    public function test_the_identity_another_storefront_inherits_is_that_of_whichever_theme_the_default_storefront_has_activated(?array $emiratiSettings): void
    {
        $firstSaudiTheme = $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        if ($emiratiSettings !== null) {
            $this->publishThemeOf(country: 'AE', settings: $emiratiSettings, name: 'Emirati');
        }
        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::SHARED_IDENTITY, $emiratiSettings ?? []), when: 'before the other theme is activated');
        $told = $this->stampTheAppIsToldFor(country: 'AE');

        $this->publishThemeOf(country: null, settings: self::CHANGED_IDENTITY, name: 'Saudi festive');

        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::CHANGED_IDENTITY, $emiratiSettings ?? []), when: 'once the other theme is activated');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::CHANGED_IDENTITY), when: 'once the other theme is activated');
        $this->assertNotSame($told, $this->stampTheAppIsToldFor(country: 'AE'), 'the app is told there is something new');

        app(ThemeManager::class)->activate(theme: $firstSaudiTheme);

        $this->assertGiven(country: 'AE', expected: $this->expectedTokens(self::SHARED_IDENTITY, $emiratiSettings ?? []), when: 'once the first theme is activated again');
        $this->assertGiven(country: 'SA', expected: $this->expectedTokens(self::SHARED_IDENTITY), when: 'once the first theme is activated again');
    }

    // ---- what the app is told, and what is left as it was --------------------------------------------------------------------------------------

    public function test_the_app_is_told_there_is_something_new_when_the_identity_a_storefront_inherits_changes_and_not_when_it_is_published_again_unchanged(): void
    {
        $saudi = $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $this->publishThemeOf(country: 'AE', settings: self::EMIRATI_OVERRIDE, name: 'Emirati');
        $told = $this->stampTheAppIsToldFor(country: 'AE');
        $this->assertSame($told, $this->stampTheAppIsToldFor(country: 'AE'), 'asking twice is the same answer');

        $this->republish(theme: $saudi, settings: self::SHARED_IDENTITY);
        $this->assertSame($told, $this->stampTheAppIsToldFor(country: 'AE'), 'the identity published again, unchanged');

        $this->republish(theme: $saudi, settings: self::CHANGED_IDENTITY);
        $this->assertNotSame($told, $this->stampTheAppIsToldFor(country: 'AE'), 'the identity changed');
    }

    public function test_the_default_storefront_and_a_storefront_served_its_theme_are_told_the_stamp_of_that_theme_as_it_is_stored(): void
    {
        $saudi = $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $this->publishThemeOf(country: 'AE', settings: self::EMIRATI_OVERRIDE, name: 'Emirati');
        $stored = $saudi->publishedVersion()->firstOrFail();

        $this->assertSame([$stored->revision, $stored->checksum], $this->stampTheAppIsToldFor(country: 'SA'));
        $this->assertSame([$stored->revision, $stored->checksum], $this->stampTheAppIsToldFor(country: 'KW'));
    }

    public function test_a_draft_of_the_override_is_resolved_as_the_storefront_will_be_given_it(): void
    {
        $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $emirati = $this->publishThemeOf(country: 'AE', settings: [], name: 'Emirati');
        $draft = app(ThemeManager::class)->createDraftFrom(source: $emirati->publishedVersion()->firstOrFail());
        $draft->update(['settings' => self::EMIRATI_OVERRIDE]);

        $this->assertSame($this->expectedTokens(self::SHARED_IDENTITY, self::EMIRATI_OVERRIDE), $this->sharedTokens(app(ThemeManager::class)->resolveSettings(version: $draft->fresh())));
    }

    public function test_a_storefront_has_no_identity_to_inherit_while_the_default_storefront_has_nothing_published(): void
    {
        $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $emirati = $this->publishThemeOf(country: 'AE', settings: self::EMIRATI_OVERRIDE, name: 'Emirati');
        ThemeVersion::query()->whereHas('theme', fn ($themes) => $themes->whereNull('storefront_country'))->update(['status' => ThemeVersion::STATUS_ARCHIVED]);
        Cache::flush();

        $this->assertSame($this->expectedTokens(self::EMIRATI_OVERRIDE), $this->sharedTokens(app(ThemeManager::class)->resolveSettings(version: $emirati->publishedVersion()->firstOrFail())));
    }

    public function test_while_themes_are_not_kept_by_storefront_a_version_is_its_own_settings_over_the_code_defaults_and_nothing_is_inherited(): void
    {
        $this->publishThemeOf(country: null, settings: self::SHARED_IDENTITY, name: 'Saudi');
        $emirati = $this->publishThemeOf(country: 'AE', settings: self::EMIRATI_OVERRIDE, name: 'Emirati');
        config(['gcc.storefronts.themes.enabled' => false]);

        $this->assertSame($this->expectedTokens(self::EMIRATI_OVERRIDE), $this->sharedTokens(app(ThemeManager::class)->resolveSettings(version: $emirati->publishedVersion()->firstOrFail())));
    }
}
