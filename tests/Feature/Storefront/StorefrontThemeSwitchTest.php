<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Services\Theme\StorefrontThemeResolver;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-01, the switch: with gcc.storefronts.themes.enabled off, or before the themes migration has run, the platform reads one active theme, with the very queries it
 * always ran, and nothing about the storefront is read. The check for the column is cheap: it is asked once.
 */
class StorefrontThemeSwitchTest extends TestCase
{
    use BuildsThemeWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->openEveryStorefront();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();

        parent::tearDown();
    }

    /** @return list<string> the text of every statement the callback ran, without the quotes each engine puts around a name */
    private function statementsOf(callable $callback): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $callback();
        $connection->disableQueryLog();

        return array_map(static fn (string $statement): string => str_replace(['"', '`'], '', $statement), array_column($connection->getQueryLog(), 'query'));
    }

    /** @param list<string> $statements */
    private function askingTheSchema(array $statements): array
    {
        return array_values(array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'pragma') || str_contains($statement, 'information_schema') || str_contains($statement, 'sqlite_master')));
    }

    public function test_switched_off_the_active_theme_is_asked_for_with_the_query_it_was_always_asked_for_with(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $resolver = app(StorefrontThemeResolver::class);

        $statements = $this->statementsOf(fn () => $resolver->activeTheme());

        $this->assertSame(['select * from themes where is_active = ? limit 1'], $statements);
    }

    public function test_switched_off_the_published_version_is_asked_for_with_the_queries_it_was_always_asked_for_with(): void
    {
        $this->givePublishedVersion(theme: $this->giveTheme(name: 'Saudi', active: true), revision: 3, spacerHeight: 41);
        $resolver = app(StorefrontThemeResolver::class);

        $statements = $this->statementsOf(fn () => $resolver->publishedVersion());

        $this->assertSame([
            'select * from themes where is_active = ? limit 1',
            'select * from theme_versions where theme_id = ? and status = ? limit 1',
        ], $statements);
    }

    public function test_switched_off_an_activation_deactivates_every_other_theme_with_the_statement_it_always_ran(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $manager = app(ThemeManager::class);
        $alwaysRan = $this->statementsOf(fn () => Theme::query()->where('id', '!=', $emirati->id)->where('is_active', true)->update(['is_active' => false]));
        Theme::query()->where('name', 'Saudi')->update(['is_active' => true]);

        $statements = $this->statementsOf(fn () => $manager->activate($emirati));

        $this->assertCount(1, $alwaysRan);
        $this->assertContains($alwaysRan[0], $statements);
    }

    public function test_switched_off_the_storefront_is_not_read_at_all(): void
    {
        // A storefront configuration that cannot be resolved: reading the storefront would throw.
        config(['gcc.storefronts.default_country' => 'XX']);
        $this->giveTheme(name: 'Saudi', active: true);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $resolver = app(StorefrontThemeResolver::class);

        $statements = $this->statementsOf(function () use ($resolver, $emirati): void {
            $this->assertSame('Saudi', $resolver->activeTheme()?->name);
            $this->assertFalse($resolver->keepsThemesByStorefront());
            $this->assertSame([], $resolver->servedByStorefront());
            $this->assertSame([], $resolver->newThemeRules());
            $this->assertSame([], $resolver->newThemeAttributes(requestedCountry: 'AE'));
            $this->assertSame(Theme::query()->count(), $resolver->themesSharingStorefrontWith(theme: $emirati)->count());
        });

        $this->assertSame([], array_filter($statements, static fn (string $statement): bool => str_contains($statement, 'storefront_country')), 'the column is not named');
        $this->assertSame([], $this->askingTheSchema($statements), 'the schema is not asked');

        $this->switchStorefrontThemesOn();
        $this->forgetWhatTheThemeResolverLearnt();
        $this->assertNotSame([], $this->askingTheSchema($this->statementsOf(fn () => app(StorefrontThemeResolver::class)->keepsThemesByStorefront())), 'the check above would see a schema question');
    }

    public function test_switched_on_before_the_migration_the_platform_still_reads_one_active_theme(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $this->switchStorefrontThemesOn();
        $this->serveRequestFromStorefront('AE');
        $first = $this->giveTheme(name: 'First', active: true);
        $this->givePublishedVersion(theme: $first, revision: 3, spacerHeight: 41);
        $resolver = app(StorefrontThemeResolver::class);

        $this->assertFalse($resolver->keepsThemesByStorefront());
        $this->assertSame('First', $resolver->activeTheme()?->name);
        $this->assertSame($first->id, $resolver->publishedVersion()?->theme_id);
        $this->assertSame([], $resolver->newThemeRules());
        $this->assertSame([], $resolver->servedByStorefront());
    }

    public function test_the_column_is_noticed_without_a_restart_once_the_migration_has_run(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $this->switchStorefrontThemesOn();
        $resolver = app(StorefrontThemeResolver::class);
        $this->assertFalse($resolver->keepsThemesByStorefront());

        $this->runThemeStorefrontMigration();
        $this->forgetWhatTheThemeResolverLearnt();

        $this->assertTrue(app(StorefrontThemeResolver::class)->keepsThemesByStorefront());
    }

    /** How many statements it takes to ask the resolver this many times, from a resolver that knows nothing yet. */
    private function statementsToAskTheColumn(int $times): int
    {
        $this->forgetWhatTheThemeResolverLearnt();
        $resolver = app(StorefrontThemeResolver::class);

        return count($this->statementsOf(function () use ($resolver, $times): void {
            foreach (range(1, $times) as $call) {
                $resolver->keepsThemesByStorefront();
            }
        }));
    }

    public function test_the_column_is_asked_for_once_not_on_every_call(): void
    {
        $this->switchStorefrontThemesOn();

        $this->assertGreaterThan(0, $this->statementsToAskTheColumn(times: 1));
        $this->assertSame($this->statementsToAskTheColumn(times: 1), $this->statementsToAskTheColumn(times: 5), 'a yes is remembered for the process');
    }

    public function test_a_missing_column_is_asked_for_once_not_on_every_call(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $this->switchStorefrontThemesOn();

        $this->assertGreaterThan(0, $this->statementsToAskTheColumn(times: 1));
        $this->assertSame($this->statementsToAskTheColumn(times: 1), $this->statementsToAskTheColumn(times: 5), 'a no is remembered for a while');
    }

    public function test_the_default_storefront_is_the_one_the_platform_is_configured_with(): void
    {
        config(['gcc.storefronts.default_country' => 'AE']);
        $this->switchStorefrontThemesOn();
        $this->giveStorefrontTheme(name: 'Named none', country: null, revision: 3, spacerHeight: 41, page: 'none-offers', active: true);
        $this->giveStorefrontTheme(name: 'Saudi', country: 'SA', revision: 7, spacerHeight: 42, page: 'saudi-offers', active: true);
        $resolver = app(StorefrontThemeResolver::class);

        $this->serveRequestFromStorefront('AE');
        $this->assertSame('Named none', $resolver->activeTheme()?->name, 'a theme that names no country is the default storefront\'s, whichever that is');
        $this->serveRequestFromStorefront('SA');
        $this->assertSame('Saudi', $resolver->activeTheme()?->name);
    }
}
