<?php

namespace App\Services\Commerce;

use App\Models\ExperienceCampaign;
use App\Models\ExperienceExperiment;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductMetric;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\ContentSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Experience Health (Phase 3.7): every way the live experience can be quietly wrong, checked.
 *
 * Each check reads the same services and rows the serve path reads, so the panel cannot disagree
 * with what shoppers actually get. Severities follow §53: INFO is worth knowing, WARNING is worth
 * fixing, CRITICAL is being served wrong right now. Detection only — nothing here ever rewrites
 * content (§58); every row names what to open instead.
 */
class ExperienceHealth
{
    public const INFO     = 'info';
    public const WARNING  = 'warning';
    public const CRITICAL = 'critical';

    public function __construct(
        private readonly CollectionResolver $collections,
        private readonly MerchandisingRules $merchandising,
        private readonly CampaignRules $campaignRules,
    ) {
    }

    /**
     * @return array<int, array{key: string, severity: string, label: string, detail: ?string}>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ([
            fn () => $this->brokenCollectionReferences(),
            fn () => $this->unavailablePins(),
            fn () => $this->thinCollections(),
            fn () => $this->staleMetrics(),
            fn () => $this->campaignDrift(),
            fn () => $this->campaignContests(),
            fn () => $this->orphanedExperiments(),
        ] as $check) {
            try {
                $findings = [...$findings, ...$check()];
            } catch (\Throwable) {
                // A health check failing must never be the unhealthy thing on the page.
            }
        }

        usort($findings, fn (array $first, array $second) => $this->rank($first['severity']) <=> $this->rank($second['severity']));

        return $findings;
    }

    // ---------------------------------------------------------------------------------------

    /** Sections on the LIVE page sourced from a collection that is gone or off — served now. */
    private function brokenCollectionReferences(): array
    {
        $versionId = $this->publishedVersionId();
        if ($versionId === null || !$this->collections->ready()) {
            return [];
        }

        $findings = [];

        foreach (ThemeSection::query()->where('theme_version_id', $versionId)->get() as $section) {
            $source = ContentSource::fromSettings($section->settings ?? []);

            if ($source->kind === 'collection' && $source->id !== null
                && $this->collections->find($source->id) === null) {
                $findings[] = [
                    'key'      => 'broken_collection_ref_' . $section->id,
                    'severity' => self::CRITICAL,
                    'label'    => 'a_live_section_is_sourced_from_a_collection_that_was_deleted_or_disabled',
                    'detail'   => $section->page . ' · ' . $section->type . ' · #' . $section->sort_order,
                ];
            }
        }

        return $findings;
    }

    /** Pins naming products that can no longer be sold — the list quietly closes up around them. */
    private function unavailablePins(): array
    {
        if (!$this->collections->ready()) {
            return [];
        }

        $findings = [];

        foreach (ProductCollection::query()->live()->get() as $collection) {
            $pinIds = array_column($this->merchandising->configFor($collection)['pins'], 'id');
            if ($pinIds === []) {
                continue;
            }

            $available = Product::active()->whereIn('products.id', $pinIds)->pluck('products.id')->all();
            $missing = array_values(array_diff($pinIds, array_map('intval', $available)));

            if ($missing !== []) {
                $findings[] = [
                    'key'      => 'unavailable_pins_' . $collection->id,
                    'severity' => self::WARNING,
                    'label'    => 'pinned_products_are_no_longer_purchasable',
                    'detail'   => $collection->name . ' · #' . implode(', #', $missing),
                ];
            }
        }

        return $findings;
    }

    /** Collections currently resolving under their own minimum — the fallback is speaking. */
    private function thinCollections(): array
    {
        if (!$this->collections->ready()) {
            return [];
        }

        $findings = [];

        foreach (ProductCollection::query()->live()->get() as $collection) {
            $config = $this->merchandising->configFor($collection);
            if ($config['min_items'] < 1) {
                continue;
            }

            $resolved = $this->collections->resolve($collection, ContentSource::MAX_LIMIT, isFallback: true);

            if ($resolved->count() < $config['min_items']) {
                $findings[] = [
                    'key'      => 'thin_collection_' . $collection->id,
                    'severity' => $config['fallback']['kind'] === 'hide' ? self::WARNING : self::INFO,
                    'label'    => $config['fallback']['kind'] === 'hide'
                        ? 'a_collection_is_below_its_minimum_and_its_sections_are_hidden'
                        : 'a_collection_is_below_its_minimum_and_its_fallback_is_showing',
                    'detail'   => $collection->name . ' · ' . $resolved->count() . ' / ' . $config['min_items'],
                ];
            }
        }

        return $findings;
    }

    /** Rankings decaying: the metrics table has not been rebuilt in over a day. */
    private function staleMetrics(): array
    {
        if (!Schema::hasTable('product_metrics') || !Schema::hasTable('product_collections')
            || !ProductCollection::query()->live()->exists()) {
            return [];
        }

        $computedAt = ProductMetric::query()->max('computed_at');

        if ($computedAt === null) {
            return [[
                'key'      => 'metrics_never_computed',
                'severity' => self::WARNING,
                'label'    => 'collection_metrics_have_never_been_computed',
                'detail'   => 'php artisan commerce:metrics-refresh',
            ]];
        }

        if (Carbon::parse($computedAt)->lessThan(now()->subHours(26))) {
            return [[
                'key'      => 'metrics_stale',
                'severity' => self::WARNING,
                'label'    => 'collection_metrics_are_stale_check_the_scheduler',
                'detail'   => (string) $computedAt,
            ]];
        }

        return [];
    }

    /** Status rows contradicting their own windows — the tick is not running. */
    private function campaignDrift(): array
    {
        if (!Schema::hasTable('experience_campaigns')) {
            return [];
        }

        $drifted = ExperienceCampaign::query()
            ->whereIn('status', ExperienceCampaign::SERVABLE_STATUSES)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->subMinutes(15))
            ->count();

        if ($drifted === 0) {
            return [];
        }

        return [[
            'key'      => 'campaign_status_drift',
            'severity' => self::INFO,
            'label'    => 'ended_campaigns_still_marked_servable_the_window_protects_shoppers_but_the_tick_is_not_running',
            'detail'   => (string) $drifted,
        ]];
    }

    /** Live campaigns contesting a slot at equal priority — the tie an activation should have refused. */
    private function campaignContests(): array
    {
        if (!Schema::hasTable('experience_campaigns')) {
            return [];
        }

        $findings = [];

        foreach (ExperienceCampaign::query()
            ->whereIn('status', ExperienceCampaign::SERVABLE_STATUSES)
            ->get() as $campaign) {
            $conflicts = $this->campaignRules->conflictsFor($campaign);

            if ($conflicts !== []) {
                $findings[] = [
                    'key'      => 'campaign_contest_' . $campaign->id,
                    'severity' => self::WARNING,
                    'label'    => 'campaigns_contest_a_slot_at_equal_priority_the_older_one_is_winning',
                    'detail'   => $campaign->name . ' ↔ ' . implode(' · ', $conflicts),
                ];
                break; // one row describes the whole contest; a row per rival would double it
            }
        }

        return $findings;
    }

    /** Running experiments whose section is no longer on the published page — measuring nothing. */
    private function orphanedExperiments(): array
    {
        if (!Schema::hasTable('experience_experiments')) {
            return [];
        }

        $versionId = $this->publishedVersionId();
        $findings = [];

        foreach (ExperienceExperiment::query()
            ->where('status', ExperienceExperiment::STATUS_RUNNING)
            ->get() as $experiment) {
            $exists = $versionId !== null && ThemeSection::query()
                ->where('theme_version_id', $versionId)
                ->where('uuid', $experiment->section_uuid)
                ->exists();

            if (!$exists) {
                $findings[] = [
                    'key'      => 'orphaned_experiment_' . $experiment->id,
                    'severity' => self::WARNING,
                    'label'    => 'a_running_experiment_targets_a_section_that_is_no_longer_published',
                    'detail'   => $experiment->name,
                ];
            }
        }

        return $findings;
    }

    private function publishedVersionId(): ?int
    {
        try {
            $theme = Theme::query()->where('is_active', true)->first();

            return $theme === null ? null : ThemeVersion::query()
                ->where('theme_id', $theme->id)
                ->where('status', ThemeVersion::STATUS_PUBLISHED)
                ->value('id');
        } catch (\Throwable) {
            return null;
        }
    }

    private function rank(string $severity): int
    {
        return match ($severity) {
            self::CRITICAL => 0,
            self::WARNING  => 1,
            default        => 2,
        };
    }
}
