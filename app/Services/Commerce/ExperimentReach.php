<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually saw each variant.
 *
 * Assignment being stable is half of an experiment; the other half is the measurement, and it had
 * no reader: the web beacon and the app both stamp an impression with `experiment: key:variant`,
 * the recorder stores it, and no screen ever asked. A merchant "running" an experiment was
 * running it blind — they could stop it, but never learn anything from it.
 *
 * This is the reader. Distinct visitors, not events: the same shopper scrolling past the hero on
 * five visits is one person who saw variant B. Thirty days, matching the section-reach window, so
 * the two numbers beside each other on the admin's screens mean the same kind of thing.
 */
class ExperimentReach
{
    private const CACHE_TTL = 300;
    private const RANGE_DAYS = 30;

    /**
     * Distinct visitors per exposure tag: ['hero-copy:b' => 41, ...].
     *
     * @return array<string, int>
     */
    public function visitors(): array
    {
        try {
            if (!Schema::hasTable('analytics_events')) {
                return [];
            }

            return Cache::remember('commerce_experiment_reach', self::CACHE_TTL, function () {
                $rows = DB::table('analytics_events')
                    ->select('properties->experiment as tag')
                    ->selectRaw('count(distinct visitor_id) as visitors')
                    ->where('name', 'section_viewed')
                    // The same noise gate every analytics screen applies: a crawler or a staff
                    // member is not an exposed shopper, and counting them here would make this
                    // the one number in the panel that disagrees with all the others.
                    ->where('is_bot', false)
                    ->where('is_internal', false)
                    ->where('occurred_at', '>=', now()->subDays(self::RANGE_DAYS))
                    ->whereNotNull('properties->experiment')
                    ->groupBy('tag')
                    ->limit(200)
                    ->get();

                $reach = [];
                foreach ($rows as $row) {
                    // The JSON accessor quotes on some drivers; a tag is a slug pair either way.
                    $tag = trim((string) $row->tag, '"');
                    if ($tag !== '') {
                        $reach[$tag] = (int) $row->visitors;
                    }
                }

                return $reach;
            });
        } catch (\Throwable) {
            // Unmeasured, never broken: the experiments screen simply shows no numbers.
            return [];
        }
    }

    /**
     * One experiment's exposures by variant key, or [] while nothing is measured.
     *
     * @return array<string, int>
     */
    public function variantVisitors(string $experimentKey): array
    {
        $prefix = $experimentKey . ':';
        $variants = [];

        foreach ($this->visitors() as $tag => $visitors) {
            if (str_starts_with($tag, $prefix)) {
                $variants[substr($tag, strlen($prefix))] = $visitors;
            }
        }

        return $variants;
    }
}
