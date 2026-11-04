<?php

namespace App\Services\Commerce;

use App\Models\ExperienceCampaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Which overrides dress a page right now, and where they land (Phase 3.3).
 *
 * The resolver answers with raw winning overrides; each render path adapts them to its own
 * section shape and splices with {@see splice()}, so the ordering rules live once. Everything
 * here is a read of small indexed rows; everything here failing means "no campaign", never a
 * broken page — the callers wrap the whole overlay in try/catch and keep the base (§37).
 */
class CampaignResolver
{
    /**
     * How long a page's winning overrides may be served from cache. Short, because the overlay
     * exists to open and close on time; campaign writes and the lifecycle tick call
     * {@see forget()} so transitions land immediately, and this only bounds the un-flushed case.
     */
    private const OVERRIDES_TTL = 30;

    /**
     * The winning override per slot for one page: live campaigns only, highest priority first,
     * older campaign breaking a tie the conflict check failed to prevent — deterministic, §36.
     *
     * @return array<int, array{slot: string, section: array{type: string, settings: array<string, mixed>}, campaign_id: int}>
     */
    public function overridesFor(string $page, ?\Illuminate\Support\Carbon $at = null): array
    {
        if (!$this->serving()) {
            return [];
        }

        // Several render paths ask per request (the cache key, the build, the banner hand-off);
        // one short-lived entry answers them all. A time-travel evaluation is never cached — it
        // is a hypothetical, and the next real request must not inherit it.
        if ($at === null) {
            try {
                return Cache::remember(
                    'commerce_campaign_overrides_' . $page,
                    self::OVERRIDES_TTL,
                    fn () => $this->resolveOverrides($page, null),
                );
            } catch (\Throwable) {
                return [];
            }
        }

        return $this->resolveOverrides($page, $at);
    }

    /** Drop the cached overrides so a lifecycle transition reaches shoppers now. */
    public static function forget(): void
    {
        try {
            foreach (ExperienceCampaign::query()->distinct()->pluck('page') as $page) {
                Cache::forget('commerce_campaign_overrides_' . $page);
            }
        } catch (\Throwable) {
            // Nothing to forget is nothing to fail over.
        }
    }

    /** @return array<int, array{slot: string, section: array<string, mixed>, campaign_id: int}> */
    private function resolveOverrides(string $page, ?\Illuminate\Support\Carbon $at): array
    {
        try {
            $campaigns = ExperienceCampaign::query()
                ->where('page', $page)
                ->whereIn('status', ExperienceCampaign::SERVABLE_STATUSES)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->filter(fn (ExperienceCampaign $campaign) => $campaign->isLive($at));
        } catch (\Throwable) {
            return [];
        }

        $winners = [];

        foreach ($campaigns as $campaign) {
            foreach ($campaign->overrideRows() as $override) {
                $slot = $override['slot'] ?? null;
                $section = $override['section'] ?? null;

                if (!is_string($slot) || !is_array($section) || isset($winners[$slot])) {
                    continue;
                }

                $winners[$slot] = [
                    'slot'        => $slot,
                    'section'     => $section,
                    'campaign_id' => (int) $campaign->id,
                ];
            }
        }

        return array_values($winners);
    }

    /**
     * A short stamp of the live campaign set, folded into the version endpoint's checksum so
     * every installed app notices a campaign starting or ending on its next resume — the
     * revision number knows nothing about overlays, and must not start lying about them.
     */
    public function stamp(?string $page = 'home'): ?string
    {
        if (!$this->serving()) {
            return null;
        }

        try {
            // The stamp reads the LIVE campaign rows directly — id, page and updated_at — so it
            // moves when a campaign opens, closes, or has its content edited while live, on any
            // page. Hashing winners alone missed the edit case: same campaign, same slot, new
            // text, stale caches everywhere.
            $live = ExperienceCampaign::query()
                ->when($page !== null, fn ($query) => $query->where('page', $page))
                ->whereIn('status', ExperienceCampaign::SERVABLE_STATUSES)
                ->get(['id', 'page', 'priority', 'starts_at', 'ends_at', 'status', 'updated_at', 'overrides'])
                ->filter(fn (ExperienceCampaign $campaign) => $campaign->isLive())
                ->map(fn (ExperienceCampaign $campaign) => [
                    $campaign->id, $campaign->page, (string) $campaign->updated_at,
                ])
                ->values()
                ->all();

            return $live === [] ? null : substr(hash('crc32b', json_encode($live)), 0, 8);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Splice overrides into an ordered section list — the one place slot positions mean things.
     *
     * hero    replaces the first hero_banner, or leads the page when there is none
     * top     before everything that is not the hero
     * middle  the visual midpoint
     * bottom  after everything
     *
     * @template T
     * @param  array<int, T>  $sections  the base page, in order
     * @param  callable(array{type: string, settings: array<string, mixed>}, int): (T|null)  $make
     *         adapts one override section to the caller's shape; null skips it (§37: one broken
     *         override never costs the others, let alone the page)
     * @return array<int, T>
     */
    public function splice(array $sections, array $overrides, callable $make, callable $typeOf): array
    {
        foreach ($overrides as $override) {
            $made = null;

            try {
                $made = $make($override['section'], $override['campaign_id']);
            } catch (\Throwable) {
                $made = null;
            }

            if ($made === null) {
                continue;
            }

            $sections = match ($override['slot']) {
                'hero'   => $this->placeHero($sections, $made, $typeOf),
                // Top of the page, but never above the hero: the hero is the page's face, and a
                // "top" promo belongs directly under it — or first, when there is no hero.
                'top'    => $sections !== [] && $typeOf($sections[0]) === 'hero_banner'
                    ? [$sections[0], $made, ...array_slice($sections, 1)]
                    : [$made, ...$sections],
                'middle' => [...array_slice($sections, 0, (int) ceil(count($sections) / 2)),
                             $made,
                             ...array_slice($sections, (int) ceil(count($sections) / 2))],
                default  => [...$sections, $made],
            };
        }

        return array_values($sections);
    }

    /**
     * @template T
     * @param  array<int, T>  $sections
     * @param  T  $made
     * @return array<int, T>
     */
    private function placeHero(array $sections, mixed $made, callable $typeOf): array
    {
        foreach ($sections as $index => $section) {
            if ($typeOf($section) === 'hero_banner') {
                $sections[$index] = $made;

                return $sections;
            }
        }

        return [$made, ...$sections];
    }

    private function serving(): bool
    {
        if (!config('commerce.enabled', true)) {
            return false;
        }

        try {
            return Schema::hasTable('experience_campaigns');
        } catch (\Throwable) {
            return false;
        }
    }
}
