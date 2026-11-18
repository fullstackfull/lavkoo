<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-02: a publish, an activation or a restore of one storefront's theme touches that storefront only. Publishing in Kuwait archives Kuwait's previous version and leaves the
 * published version, the revision and the checksum of the Saudi theme as they are; it forgets the cached pages of Kuwait and no other storefront's; and its audit line says which
 * storefront it was for. The theme of the default storefront is the one every other storefront without a theme of its own falls back to, so a change of it forgets every storefront's
 * pages, as it always did, and while themes are not kept by storefront nothing changes at all.
 */
class StorefrontThemePublishTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsThemeWorld;

    /** The two caches a publish forgets, under the key each storefront writes them: the pages the website draws and the revision the apps ask for. */
    private const CACHED = [StorefrontThemeRenderer::CACHE_KEY_PREFIX . 'home', StorefrontThemeRenderer::GLOBAL_SETTINGS_CACHE_KEY, 'theme_delivery_revision', 'theme_delivery_home_index'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildThemeWorld();
        $this->buildAuditLogTable();
        $this->switchStorefrontThemesOn();
        $this->openEveryStorefront();
    }

    protected function tearDown(): void
    {
        $this->dropThemeWorld();
        Schema::dropIfExists('audit_logs');

        parent::tearDown();
    }

    private function draftOf(Theme $theme): ThemeVersion
    {
        return ThemeVersion::query()->create(['theme_id' => $theme->id, 'label' => 'A draft', 'status' => ThemeVersion::STATUS_DRAFT]);
    }

    /** @return array<string, list<string>> the cache keys each storefront writes the cached pages under, by country */
    private function cachedKeysByStorefront(): array
    {
        $keysOf = static fn (string $country): array => array_map(static fn (string $key): string => $country === 'SA' ? $key : "storefront:{$country}:{$key}", self::CACHED);

        return ['SA' => $keysOf('SA'), 'AE' => $keysOf('AE'), 'KW' => $keysOf('KW')];
    }

    private function cacheEveryStorefrontsPages(): void
    {
        foreach ($this->cachedKeysByStorefront() as $keys) {
            foreach ($keys as $key) {
                Cache::put($key, 'a page that was cached', 600);
            }
        }
    }

    /** @return list<string> the storefronts whose cached pages are still there, every one of them or none */
    private function storefrontsStillCached(): array
    {
        $still = [];
        foreach ($this->cachedKeysByStorefront() as $country => $keys) {
            $present = array_map(static fn (string $key): bool => Cache::has($key), $keys);

            $this->assertTrue(count(array_unique($present)) === 1, "Some of the cached pages of {$country} were forgotten and some were not.");

            if ($present[0]) {
                $still[] = $country;
            }
        }

        return $still;
    }

    private function auditContext(string $action): array
    {
        $line = DB::table('audit_logs')->where('action', $action)->orderByDesc('id')->first();
        $this->assertNotNull($line, "No audit line [{$action}].");

        return (array) json_decode((string) $line->context, true);
    }

    // ---- what a publish does to the other storefronts' themes ------------------------------------------------------------------------------

    public function test_publishing_in_one_storefront_archives_its_previous_version_and_leaves_the_published_version_of_another_alone(): void
    {
        $saudi = $this->giveTheme(name: 'Saudi', active: true);
        $saudiVersion = $this->givePublishedVersion(theme: $saudi, revision: 7, spacerHeight: 100);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $previous = $this->givePublishedVersion(theme: $emirati, revision: 3, spacerHeight: 250);
        $draft = $this->draftOf($emirati);

        $published = app(ThemeManager::class)->publish($draft);

        $this->assertSame(ThemeVersion::STATUS_PUBLISHED, $published->status);
        $this->assertSame(ThemeVersion::STATUS_ARCHIVED, $previous->fresh()->status);
        $untouched = $saudiVersion->fresh();
        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, 7, 'checksum-of-saudi'], [$untouched->status, $untouched->revision, $untouched->checksum]);
        $this->assertTrue($untouched->published_at->equalTo($saudiVersion->published_at));
    }

    public function test_the_revisions_of_two_storefronts_are_counted_apart(): void
    {
        $saudi = $this->giveTheme(name: 'Saudi', active: true);
        $this->givePublishedVersion(theme: $saudi, revision: 7, spacerHeight: 100);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $this->givePublishedVersion(theme: $emirati, revision: 3, spacerHeight: 250);

        $published = app(ThemeManager::class)->publish($this->draftOf($emirati));

        $this->assertSame(4, (int) $published->revision);
        $this->assertSame(7, (int) ThemeVersion::query()->where('theme_id', $saudi->id)->value('revision'));
    }

    // ---- what a publish forgets -------------------------------------------------------------------------------------------------------------

    public function test_a_publish_in_one_storefront_forgets_its_cached_pages_and_those_of_no_other(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE', active: true);
        $this->cacheEveryStorefrontsPages();

        app(ThemeManager::class)->publish($this->draftOf($emirati));

        $this->assertSame(['SA', 'KW'], $this->storefrontsStillCached());
    }

    public function test_an_activation_in_one_storefront_forgets_its_cached_pages_and_those_of_no_other(): void
    {
        $this->giveTheme(name: 'Saudi', active: true);
        $kuwaiti = $this->giveTheme(name: 'Kuwaiti', country: 'KW');
        $this->cacheEveryStorefrontsPages();

        app(ThemeManager::class)->activate($kuwaiti);

        $this->assertSame(['SA', 'AE'], $this->storefrontsStillCached());
    }

    public function test_a_publish_of_the_default_storefronts_theme_forgets_the_cached_pages_of_every_storefront(): void
    {
        $saudi = $this->giveTheme(name: 'Saudi', active: true);
        $this->cacheEveryStorefrontsPages();

        app(ThemeManager::class)->publish($this->draftOf($saudi));

        $this->assertSame([], $this->storefrontsStillCached());
    }

    public function test_a_theme_that_names_the_default_country_is_the_default_storefronts(): void
    {
        $saudi = $this->giveTheme(name: 'Saudi', country: 'SA', active: true);
        $this->cacheEveryStorefrontsPages();

        app(ThemeManager::class)->publish($this->draftOf($saudi));

        $this->assertSame([], $this->storefrontsStillCached());
    }

    public function test_while_themes_are_not_kept_by_storefront_a_publish_and_an_activation_forget_every_storefronts_pages(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');

        $this->cacheEveryStorefrontsPages();
        app(ThemeManager::class)->publish($this->draftOf($emirati));
        $this->assertSame([], $this->storefrontsStillCached());

        $this->cacheEveryStorefrontsPages();
        app(ThemeManager::class)->activate($emirati);
        $this->assertSame([], $this->storefrontsStillCached());
    }

    public function test_before_the_themes_migration_has_run_a_publish_forgets_every_storefronts_pages(): void
    {
        $this->buildThemeWorld(withCountryColumn: false);
        $theme = $this->giveTheme(name: 'Only');
        $this->cacheEveryStorefrontsPages();

        app(ThemeManager::class)->publish($this->draftOf($theme));

        $this->assertSame([], $this->storefrontsStillCached());
    }

    // ---- what the audit lines say ----------------------------------------------------------------------------------------------------------

    public function test_the_audit_lines_of_a_publish_an_activation_and_a_restore_name_the_storefront(): void
    {
        $emirati = $this->giveTheme(name: 'Emirati', country: 'AE');
        $source = $this->givePublishedVersion(theme: $emirati, revision: 1, spacerHeight: 250);
        $saudi = $this->giveTheme(name: 'Saudi');

        $themes = app(ThemeManager::class);
        $themes->activate($emirati);
        $themes->publish($this->draftOf($emirati));
        $themes->restoreVersion($source);
        $themes->activate($saudi);

        $this->assertSame('AE', $this->auditContext('theme.published')['storefront']);
        $this->assertSame('AE', $this->auditContext('theme.restored')['storefront']);
        $this->assertSame(['storefront' => 'SA'], $this->auditContext('theme.activated'));
        $this->assertSame($emirati->id, $this->auditContext('theme.published')['theme_id']);
    }

    public function test_the_audit_lines_are_what_they_were_while_themes_are_not_kept_by_storefront(): void
    {
        config(['gcc.storefronts.themes.enabled' => false]);
        $theme = $this->giveTheme(name: 'Only');
        $source = $this->givePublishedVersion(theme: $theme, revision: 1, spacerHeight: 100);

        $themes = app(ThemeManager::class);
        $themes->activate($theme);
        $themes->publish($this->draftOf($theme));
        $themes->restoreVersion($source);

        $this->assertEquals(['theme_id' => $theme->id], $this->auditContext('theme.published'));
        $this->assertEquals(['restored_from_version_id' => $source->id, 'theme_id' => $theme->id], $this->auditContext('theme.restored'), 'a JSON column does not keep the order of the keys on every engine');
        $this->assertNull(DB::table('audit_logs')->where('action', 'theme.activated')->value('context'));
    }
}
