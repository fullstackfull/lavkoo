<?php

namespace Tests\Feature\Storefront;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\StorefrontThemeResolver;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemeManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsThemeWorld;
use Tests\Support\ReadsThemeWorld;
use Tests\TestCase;

/**
 * GCC F-08: restoring a version of a theme copies it into a NEW draft and publishes nothing. The live version, the archived ones, the revision counter and the theme that is active stay
 * as they were, and the restored draft goes live only when the merchant publishes it. A publish that fails midway leaves every version as it was (it is one transaction), so the
 * storefront keeps serving the version that was live, nothing is recorded as published, and the same draft publishes normally once the fault is gone.
 */
class StorefrontThemeVersionLifecycleTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsThemeWorld;
    use ReadsThemeWorld;

    private const DATABASE_ERROR = 'the database refused the publish';

    /** What a copy of a section keeps. The uuid is kept on purpose: it is the identity of the section in every version, which clients key their per-section state by. */
    private const COPIED_SECTION_COLUMNS = ['page', 'type', 'sort_order', 'is_visible', 'settings', 'uuid', 'starts_at', 'ends_at', 'platforms', 'audience', 'channels'];

    private const COPIED_BLOCK_COLUMNS = ['type', 'sort_order', 'is_visible', 'settings'];

    private bool $failing = false;

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

    /**
     * A theme with an archived version, the live one and a draft that waits for a moment, each with a hero of two slides and a product rail of its own, the rail hidden and
     * carrying delivery rules, so that a copy that forgot any of it would show.
     *
     * @return array{theme: Theme, archived: ThemeVersion, live: ThemeVersion, draft: ThemeVersion}
     */
    private function giveHistory(string $name, ?string $country = null, bool $active = true): array
    {
        $theme = $this->giveTheme(name: $name, country: $country, active: $active);

        return [
            'theme' => $theme,
            'archived' => $this->giveVersion(theme: $theme, status: ThemeVersion::STATUS_ARCHIVED, revision: 1, marker: 11, settings: ['colors' => ['primary' => '#112233'], 'layout' => ['border_radius' => 14]]),
            'live' => $this->giveVersion(theme: $theme, status: ThemeVersion::STATUS_PUBLISHED, revision: 2, marker: 22, settings: ['colors' => ['primary' => '#445566']]),
            'draft' => $this->giveVersion(theme: $theme, status: ThemeVersion::STATUS_DRAFT, revision: null, marker: 33, settings: ['typography' => ['base_font_size' => 18]], publishAt: now()->addHours(3)),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function giveVersion(Theme $theme, string $status, ?int $revision, int $marker, array $settings, ?Carbon $publishAt = null): ThemeVersion
    {
        $version = ThemeVersion::query()->create([
            'theme_id' => $theme->id,
            'label' => "{$theme->name} {$status}",
            'status' => $status,
            'settings' => $settings,
            'published_at' => match ($status) {
                ThemeVersion::STATUS_ARCHIVED => now()->subDays(10),
                ThemeVersion::STATUS_PUBLISHED => now()->subDay(),
                default => null,
            },
            'publish_at' => $publishAt,
            'revision' => $revision,
            'checksum' => $revision === null ? null : "checksum-of-{$theme->slug}-{$revision}",
        ]);

        $hero = ThemeSection::query()->create(['theme_version_id' => $version->id, 'page' => 'home', 'type' => 'hero_banner', 'sort_order' => 1, 'settings' => ['height' => $marker]]);
        foreach (['first', 'second'] as $position => $title) {
            ThemeBlock::query()->create(['theme_section_id' => $hero->id, 'type' => 'slide', 'sort_order' => $position + 1, 'is_visible' => $title === 'first', 'settings' => ['title' => "{$marker} {$title}"]]);
        }
        ThemeSection::query()->create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'product_slider', 'sort_order' => 2, 'is_visible' => false, 'settings' => ['source' => 'new_arrival', 'limit' => $marker],
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(9), 'platforms' => ['app'], 'channels' => ['customer_app'],
        ]);

        return $version;
    }

    /** @return list<array{section: array<string, mixed>, blocks: list<array<string, mixed>>}> what a version holds, without the ids and the timestamps of its rows */
    private function contentOf(ThemeVersion $version): array
    {
        return $version->sections()->with('blocks')->get()->map(fn (ThemeSection $section): array => [
            'section' => $section->only(self::COPIED_SECTION_COLUMNS),
            'blocks' => $section->blocks->map(fn (ThemeBlock $block): array => $block->only(self::COPIED_BLOCK_COLUMNS))->all(),
        ])->all();
    }

    /** @return list<int> */
    private function sectionIdsOf(ThemeVersion $version): array
    {
        return $version->sections()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function blockIdsOf(ThemeVersion $version): array
    {
        return ThemeBlock::query()->whereIn('theme_section_id', $this->sectionIdsOf($version))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<string> every action the audit trail holds, oldest first */
    private function recordedActions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }

    /** @return array<string, array{0: string}> */
    public static function versionsThatCanBeRestored(): array
    {
        return ['an archived version' => ['archived'], 'the live version' => ['live']];
    }

    // ---- restoring a version (F-08.2) --------------------------------------------------------------------------------------------------------

    #[DataProvider('versionsThatCanBeRestored')]
    public function test_restoring_a_version_creates_a_new_draft_holding_a_copy_of_its_sections_blocks_and_settings(string $which): void
    {
        $source = $this->giveHistory(name: 'Saudi')[$which];
        $versionsBefore = ThemeVersion::query()->pluck('id')->all();

        $restored = app(ThemeManager::class)->restoreVersion(source: $source);

        $draft = $restored->fresh();
        $this->assertNotContains($draft->id, $versionsBefore);
        $this->assertSame([ThemeVersion::STATUS_DRAFT, $source->theme_id, $source->id, "Restored from #{$source->id}"], [$draft->status, $draft->theme_id, $draft->based_on_version_id, $draft->label]);
        $this->assertSame([null, null, null, null], [$draft->published_at, $draft->publish_at, $draft->revision, $draft->checksum]);
        $this->assertEquals($source->settings, $draft->settings);
        $this->assertCount(2, $this->contentOf(version: $source), 'the source has something to copy');
        $this->assertNotNull($source->sections()->firstOrFail()->uuid, 'its sections have an identity to keep');
        $this->assertEquals($this->contentOf(version: $source), $this->contentOf(version: $draft));
        $this->assertSame([], array_intersect($this->sectionIdsOf(version: $source), $this->sectionIdsOf(version: $draft)), 'the sections are new rows, not the same ones');
        $this->assertSame([], array_intersect($this->blockIdsOf(version: $source), $this->blockIdsOf(version: $draft)), 'the blocks are new rows, not the same ones');
        $this->assertCount(2, $this->blockIdsOf(version: $draft));
    }

    #[DataProvider('versionsThatCanBeRestored')]
    public function test_restoring_publishes_nothing_and_leaves_every_version_and_the_revision_as_they_were(string $which): void
    {
        $history = $this->giveHistory(name: 'Saudi');
        $before = $this->rowsOfTheThemeTables();

        $restored = app(ThemeManager::class)->restoreVersion(source: $history[$which]);

        $after = $this->rowsOfTheThemeTables();
        foreach (['theme_versions', 'theme_sections', 'theme_blocks'] as $table) {
            $this->assertEquals($before[$table], array_slice($after[$table], 0, count($before[$table])), "A row of {$table} that existed before the restore was changed.");
            $this->assertGreaterThan(count($before[$table]), count($after[$table]), "The restore added nothing to {$table}.");
        }
        $this->assertSame([$history['live']->id], ThemeVersion::query()->where('theme_id', $history['theme']->id)->published()->pluck('id')->all());
        $this->assertSame(2, (int) ThemeVersion::query()->where('theme_id', $history['theme']->id)->max('revision'));
        $this->assertNull($restored->fresh()->published_at);
        $this->assertSame(['theme.restored'], $this->recordedActions());
    }

    public function test_the_restored_draft_goes_live_only_when_it_is_published_and_then_takes_the_next_revision(): void
    {
        $history = $this->giveHistory(name: 'Saudi');
        $themes = app(ThemeManager::class);
        $restored = $themes->restoreVersion(source: $history['archived']);

        $this->assertSame($history['live']->id, app(StorefrontThemeResolver::class)->publishedVersion()?->id, 'the restore left the live version live');

        $published = $themes->publish(version: $restored);

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, 3], [$published->status, $published->revision], 'the restore did not use up a revision');
        $this->assertSame(ThemeVersion::STATUS_ARCHIVED, $history['live']->fresh()->status);
        $this->assertSame($restored->id, app(StorefrontThemeResolver::class)->publishedVersion()?->id);
        $this->assertSame(['theme.restored', 'theme.published'], $this->recordedActions());
    }

    public function test_restoring_never_changes_which_theme_is_active(): void
    {
        $histories = [
            $this->giveHistory(name: 'Saudi'),
            $this->giveHistory(name: 'Emirati', country: 'AE'),
            $this->giveHistory(name: 'Kuwaiti', country: 'KW', active: false),
        ];
        $themesBefore = $this->rowsOfTheThemeTables()['themes'];
        $servedBefore = $this->themeServedToEachStorefront();

        foreach ($histories as $history) {
            app(ThemeManager::class)->restoreVersion(source: $history['archived']);
        }

        $this->assertSame(['SA' => 'Saudi', 'AE' => 'Emirati', 'KW' => 'Saudi'], $servedBefore, 'Kuwait is served the default storefront\'s theme as its own is not active');
        $this->assertSame($servedBefore, $this->themeServedToEachStorefront());
        $this->assertEquals($themesBefore, $this->rowsOfTheThemeTables()['themes']);
        $this->assertSame([true, true, false], Theme::query()->orderBy('id')->pluck('is_active')->map(static fn ($active): bool => (bool) $active)->all());
    }

    // ---- a publish that fails midway (F-08.3) -----------------------------------------------------------------------------------------------

    /**
     * Makes the publish of one draft throw inside its transaction, while $this->failing is on. Before the draft is stamped, the failure comes when the draft is saved as published,
     * after the live version has been archived; after it is stamped, it comes when the stamp has been written, after the draft was saved as published too.
     */
    private function failThePublishOf(ThemeVersion $draft, bool $afterTheDraftWasStamped): void
    {
        $this->failing = true;
        $fails = function (ThemeVersion $saved) use ($draft, $afterTheDraftWasStamped): void {
            $isTheDraftGoingLive = (int) $saved->getKey() === (int) $draft->getKey() && $saved->status === ThemeVersion::STATUS_PUBLISHED;

            if ($this->failing && $isTheDraftGoingLive && ($saved->revision !== null) === $afterTheDraftWasStamped) {
                throw new RuntimeException(self::DATABASE_ERROR);
            }
        };

        $afterTheDraftWasStamped ? ThemeVersion::saved($fails) : ThemeVersion::saving($fails);
    }

    private function publishExpectingItToFail(ThemeVersion $draft): RuntimeException
    {
        try {
            app(ThemeManager::class)->publish(version: $draft);
        } catch (RuntimeException $failure) {
            return $failure;
        }

        $this->fail('The publish was meant to fail.');
    }

    /** @return array<string, array{0: bool}> */
    public static function momentsOfAFailure(): array
    {
        return [
            'when the draft is saved as published, after the live version was archived' => [false],
            'when the draft is stamped with its revision, after it was saved as published' => [true],
        ];
    }

    #[DataProvider('momentsOfAFailure')]
    public function test_a_publish_that_fails_midway_leaves_the_live_and_the_archived_versions_as_they_were(bool $afterTheDraftWasStamped): void
    {
        $history = $this->giveHistory(name: 'Saudi');
        $before = $this->rowsOfTheThemeTables();
        $this->failThePublishOf(draft: $history['draft'], afterTheDraftWasStamped: $afterTheDraftWasStamped);

        $failure = $this->publishExpectingItToFail(draft: $history['draft']);

        $this->assertSame(self::DATABASE_ERROR, $failure->getMessage());
        $this->assertEquals($before, $this->rowsOfTheThemeTables());
        $this->assertSame([ThemeVersion::STATUS_ARCHIVED, 1], [$history['archived']->fresh()->status, $history['archived']->fresh()->revision]);
        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, 2], [$history['live']->fresh()->status, $history['live']->fresh()->revision]);
        $draft = $history['draft']->fresh();
        $this->assertSame([ThemeVersion::STATUS_DRAFT, null, null, null], [$draft->status, $draft->published_at, $draft->revision, $draft->checksum]);
        $this->assertNotNull($draft->publish_at, 'the draft keeps the moment it was scheduled for');
        $this->assertSame([], $this->recordedActions());
    }

    #[DataProvider('momentsOfAFailure')]
    public function test_the_storefront_keeps_serving_the_live_version_after_a_publish_failed_and_the_draft_publishes_once_the_fault_is_gone(bool $afterTheDraftWasStamped): void
    {
        $history = $this->giveHistory(name: 'Saudi');
        $this->failThePublishOf(draft: $history['draft'], afterTheDraftWasStamped: $afterTheDraftWasStamped);
        $this->publishExpectingItToFail(draft: $history['draft']);

        $this->assertSame($history['live']->id, app(StorefrontThemeResolver::class)->publishedVersion()?->id);
        $this->assertSame(2, app(ThemeDelivery::class)->revision()['revision']);

        $this->failing = false;
        $published = app(ThemeManager::class)->publish(version: $history['draft']->fresh());

        $this->assertSame([ThemeVersion::STATUS_PUBLISHED, 3], [$published->status, $published->revision]);
        $this->assertSame([ThemeVersion::STATUS_ARCHIVED, ThemeVersion::STATUS_ARCHIVED], [$history['archived']->fresh()->status, $history['live']->fresh()->status]);
        $this->assertSame($history['draft']->id, app(StorefrontThemeResolver::class)->publishedVersion()?->id);
        $this->assertSame(['theme.published'], $this->recordedActions());
    }
}
