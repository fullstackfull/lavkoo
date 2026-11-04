<?php

namespace App\Services\Theme;

use App\Models\BusinessSetting;
use App\Services\Analytics\Reporting\AnalyticsReporting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Is this server actually able to run everything the App Builder promises?
 *
 * The builder's features lean on infrastructure the code cannot install for itself: migrations a
 * deployment forgot, a cron nobody added, an analytics rollup that never fired. Each of those used
 * to fail invisibly — a scheduled publish that quietly never happened, a reach column that stayed
 * empty forever — and the only person who could notice was whoever reads server logs.
 *
 * This turns "verify it on the server" into something the merchant can see: one list, each row a
 * thing the builder needs, each failure carrying the exact command that fixes it. It composes the
 * checks the features already run for themselves — the same heartbeat the scheduled-publish
 * control reads, the same health the analytics pages show — so the panel can never disagree with
 * the feature it describes.
 */
class BuilderReadiness
{
    /** The heartbeat the scheduler writes on every run; older than this and it is dead. */
    private const HEARTBEAT_FRESH_MINUTES = 10;

    public function __construct(
        private readonly ExperiencePageService $pages,
        private readonly AnalyticsReporting $analytics,
    ) {
    }

    /**
     * Every check the panel shows, in the order a merchant should fix them.
     *
     * Each row: a stable key, whether it passes, a translation key describing what is being
     * checked, an optional translation key explaining the failure, and the literal fix — a command
     * or crontab line, shown as-is because translating a shell command helps nobody.
     *
     * @return array<int, array{key: string, ok: bool, label: string, why: ?string, fix: ?string}>
     */
    public function checks(): array
    {
        $migrated = $this->storeIsMigrated();
        $scheduler = $this->schedulerIsRunning();
        $analytics = $this->analytics->collectionHealth();

        return [
            [
                'key'   => 'store',
                'ok'    => $migrated,
                'label' => 'the_page_and_version_tables_are_migrated',
                'why'   => $migrated ? null : 'a_migration_this_builder_needs_has_not_been_applied',
                'fix'   => $migrated ? null : 'php artisan migrate',
            ],
            [
                'key'   => 'scheduler',
                'ok'    => $scheduler,
                'label' => 'the_scheduler_is_running',
                'why'   => $scheduler ? null : 'scheduled_publishes_and_nightly_rollups_will_not_fire_until_the_cron_is_installed',
                'fix'   => $scheduler ? null : '* * * * * cd ' . base_path() . ' && php artisan schedule:run',
            ],
            [
                'key'   => 'analytics',
                'ok'    => ($analytics['state'] ?? null) === 'healthy',
                'label' => 'analytics_is_collecting_and_rolling_up',
                // The analytics health already explains itself with a translation key; reusing it
                // keeps this panel incapable of contradicting the analytics pages.
                'why'   => ($analytics['state'] ?? null) === 'healthy' ? null : ($analytics['message_key'] ?? null),
                'fix'   => ($analytics['state'] ?? null) === 'healthy' ? null : ($analytics['detail'] ?? null),
            ],
        ];
    }


    /**
     * Everything the builder stores, present. One answer rather than a row per table: a partially
     * migrated deployment has exactly one fix, and three red rows saying "migrate" would dress a
     * single problem up as three.
     */
    public function storeIsMigrated(): bool
    {
        try {
            return $this->pages->isReady()
                && Schema::hasColumn('theme_versions', 'change_note')
                && Schema::hasColumn('theme_versions', 'publish_at');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The same heartbeat the dashboard reads: the scheduler stamps every run, and a stamp this
     * stale means scheduled publishes are promises nothing will keep.
     */
    public function schedulerIsRunning(): bool
    {
        try {
            if (!Schema::hasTable('business_settings')) {
                return false;
            }

            $heartbeat = BusinessSetting::where('type', 'scheduler_last_run_at')->value('value');

            return $heartbeat
                && Carbon::parse($heartbeat)->greaterThan(now()->subMinutes(self::HEARTBEAT_FRESH_MINUTES));
        } catch (\Throwable) {
            return false;
        }
    }
}
