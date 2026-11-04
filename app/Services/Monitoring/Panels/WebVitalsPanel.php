<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Ingest\WebVitalsRecorder;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\ReadsFoldedSeries;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Web performance: what real shoppers experienced, measured in their own browsers.
 *
 * Every other speed figure in this console is measured on the server. This one is not, and that is
 * the whole reason the section exists: PHP can answer in 40ms while the page is unusable, because
 * the font blocked the first paint, the banner reflowed the layout after two seconds, or the first
 * three taps were ignored. Only the browser sees those, so only the browser can report them.
 *
 * WHICH FIGURE THIS PAGE SHOWS, AND WHICH IT DOES NOT. The published way to read a Core Web Vital
 * is the 75th percentile of visits. This store keeps per-minute aggregates, not a sample per visit,
 * so a p75 cannot be computed from it — and a p75 derived from a mean is not a p75, it is a mean
 * with a more authoritative name. What the recorder CAN compute exactly is how many visits fell in
 * each of the three published bands, so the band split is the headline figure here and the mean is
 * drawn beside it with its sample count, labelled as a mean. Both are true; neither is called a
 * p75. WebVitalsRecorder's own docblock is the source of that decision.
 *
 * AN EMPTY PAGE IS A STATEMENT ABOUT VISITS, NOT ABOUT SPEED. Nothing here is sampled by a
 * collector on a timer: a reading exists only because a real browser loaded a real page and posted
 * it back as the page went away. So "no data" means nobody visited (or the beacon is switched off),
 * never "the shop was fast", and the collection block above the numbers says which of the two it
 * is before a single figure is drawn.
 *
 * CLS is stored multiplied by a thousand so it can share one integer-friendly pipeline with the
 * timings. It is divided back here and labelled a score rather than a duration.
 */
class WebVitalsPanel implements Panel
{
    use ReadsFoldedSeries;

    /** Every series this section reads starts with this. */
    private const SERIES = 'web.vitals.';

    /** The three published bands, in the order they are drawn. Also the translate() allowlist. */
    private const BANDS = ['good', 'needs_improvement', 'poor'];

    /**
     * How each metric is named and what it means, in this section's own vocabulary.
     *
     * The keys and thresholds come from WebVitalsRecorder::METRICS, which is what the browser is
     * actually measured against; only the wording is here. A metric the recorder grows and this map
     * does not know is still drawn — under its stored key, untranslated — rather than dropped.
     *
     * @var array<string, array{abbreviation: string, label: string, headline: string|null, description: string}>
     */
    private const NAMES = [
        'lcp' => [
            'abbreviation' => 'LCP',
            'label' => 'largest_contentful_paint',
            'headline' => 'largest_contentful_paint_good_share',
            'description' => 'How long the largest thing on the page — usually the main image or heading — took to appear. This is the closest single number to "when did the page look loaded".',
        ],
        'inp' => [
            'abbreviation' => 'INP',
            'label' => 'interaction_to_next_paint',
            'headline' => 'interaction_to_next_paint_good_share',
            'description' => 'The worst delay between a shopper tapping something and the page visibly responding. A slow one is felt as a page that ignores taps.',
        ],
        'cls' => [
            'abbreviation' => 'CLS',
            'label' => 'cumulative_layout_shift',
            'headline' => 'cumulative_layout_shift_good_share',
            'description' => 'How much the layout moved under the shopper after it had already been drawn. A score, not a duration: it is what makes somebody tap the wrong button.',
        ],
        'ttfb' => [
            'abbreviation' => 'TTFB',
            'label' => 'time_to_first_byte',
            'headline' => null,
            'description' => 'How long the browser waited for the first byte of the page — DNS, TLS, the network, the web server queue and PHP, all of it, as the shopper experienced it.',
        ],
        'fcp' => [
            'abbreviation' => 'FCP',
            'label' => 'first_contentful_paint',
            'headline' => null,
            'description' => 'When the browser first painted anything at all from the page.',
        ],
    ];

    /** Distinct series names folded into the totals. Twenty exist today; the cap is the guard. */
    private const MAX_METRIC_GROUPS = 60;

    /**
     * Distinct (series, page) groups read for the per-page tables.
     *
     * A page contributes up to four series per metric, so this is roughly sixty pages. Read busiest
     * first and reported as truncated, rather than read whole: the label is a normalised path
     * pattern, which is bounded in practice but is not bounded by anything this panel controls.
     */
    private const MAX_PAGE_GROUPS = 1200;

    /** Pages listed under each metric. */
    private const MAX_PAGES_PER_METRIC = 10;

    /** Buckets the chart reads, whatever the window asks for. */
    private const MAX_TIMELINE_BUCKETS = 400;

    private const SOURCE = 'monitoring_series (web.vitals.*)';

    private const RECORDER = 'app/Services/Monitoring/Ingest/WebVitalsRecorder.php';

    private const BEACON = 'public/assets/front-end/js/analytics-beacon.js';

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $totals = $this->totals($range, $window);
        $pages = $this->pages($range, $window);
        $collection = $this->collection($totals);
        $metrics = $this->metrics($totals, $pages, $collection);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'collection' => $collection,
            'figure' => $this->figure(),
            'headline' => $this->headline($metrics, $collection),
            'metrics' => $metrics,
            'timeline' => $this->timeline($range, $window),
            'caveats' => $this->caveats(),
            'unrendered' => $this->unrendered($totals),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Can a reading arrive at all, and when did the last one

    /**
     * Whether the browser can report anything, and when it last did.
     *
     * Assembled before every number on the page and drawn above them, because an empty window has
     * four different meanings — collection off, beacon off, nothing stored yet, nobody visited —
     * and only one of them is "the shop was quiet". Three of the four are fixed by an operator.
     *
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function collection(array $totals): array
    {
        $base = [
            'source' => self::SOURCE,
            'recorder' => self::RECORDER,
            'beacon' => self::BEACON,
            'endpoint' => (string) config('analytics.beacon.path', 'analytics/collect'),
            'monitoring_enabled' => (bool) config('monitoring.enabled', true),
            'analytics_enabled' => (bool) app(AnalyticsPolicy::class)->enabled(),
            'beacon_enabled' => (bool) config('analytics.beacon.enabled', true),
            'last_reading_at' => null,
            'last_reading_age_minutes' => null,
            'readings_in_window' => null,
        ];

        // Read before the switches are checked, not after. A shop that turned the beacon off last
        // week still has last week's readings on the page below, and a banner saying "never" over
        // a table full of measurements is the contradiction this block exists to prevent.
        $last = $this->lastReading();
        $readings = $totals['state'] === 'ok' ? $this->sumReadings($totals) : null;

        if ($last['state'] === 'ok') {
            $base = array_merge($base, [
                'last_reading_at' => $last['display'],
                'last_reading_age_minutes' => $last['age_minutes'],
                'readings_in_window' => $readings,
            ]);
        }

        if (!$base['monitoring_enabled']) {
            return array_merge($base, [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so AnalyticsCollectController drops every vital the browser posts before it reaches the recorder. Nothing below can change until it is switched back on.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        if (!$base['analytics_enabled'] || !$base['beacon_enabled']) {
            return array_merge($base, [
                'state' => 'not_configured',
                'note' => 'The browser beacon is switched off, so the endpoint the vitals ride on answers 204 without reading them. No shopper measurement can reach this page while that is true.',
                'remedy' => 'Set ANALYTICS_ENABLED=true and ANALYTICS_BEACON=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        if ($last['state'] === 'failed') {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $last['note'],
                'remedy' => 'The series table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
            ]);
        }

        if ($last['at'] === null) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'No browser has reported a Core Web Vital yet. These are measured in the shopper\'s own browser and posted back as the page is closed, so they appear only after real visits to the storefront — there is nothing here for a collector or a cron to run.',
                'remedy' => 'Open a storefront page and leave it (or switch tabs): ' . self::BEACON . ' reports LCP, INP, CLS, TTFB and FCP once per page load to /' . ltrim((string) config('analytics.beacon.path', 'analytics/collect'), '/') . ', and ' . self::RECORDER . ' stores them. If nothing arrives, check that ANALYTICS_BEACON and MONITORING_ENABLED are both true and that the storefront layout still includes the beacon.',
            ]);
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
        ]);
    }

    /**
     * When a vital was last stored, at any resolution.
     *
     * Minute rows first because they are the newest, then the folded ones — a deployment quiet for
     * longer than the minute retention still gets a truthful answer instead of "never".
     *
     * Each read is `metric IN (5 names) AND resolution = ?` with MAX(bucket_at), which rides
     * monitoring_series_lookup (metric, resolution, bucket_at): the maximum is the end of an index
     * range rather than a scan.
     *
     * @return array{state: string, at: string|null, display: string|null, age_minutes: int|null, note: string|null}
     */
    private function lastReading(): array
    {
        try {
            $connection = $this->reader->connection();
            $newest = null;

            foreach (['minute', 'hour', 'day'] as $resolution) {
                $newest = $connection->table('monitoring_series')
                    ->whereIn('metric', $this->timingMetrics())
                    ->where('resolution', $resolution)
                    ->max('bucket_at');

                if ($newest !== null) {
                    break;
                }
            }
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'at' => null, 'display' => null, 'age_minutes' => null, 'note' => $this->failureNote($exception)];
        }

        return [
            'state' => 'ok',
            'at' => $newest === null ? null : (string) $newest,
            'display' => $this->displayStamp($newest),
            'age_minutes' => $this->minutesSince($newest),
            'note' => null,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The stored aggregates

    /**
     * Every web.vitals series in the window, folded to one row per series name.
     *
     * Read with a name prefix rather than an explicit list so a metric the recorder grows cannot
     * vanish silently — anything unknown lands in unrendered() instead of being dropped.
     *
     * Bounded by the window on one side and by MAX_METRIC_GROUPS on the other. It rides
     * monitoring_series_unique (resolution, bucket_at, metric, label) for the window and
     * monitoring_series_lookup (metric, resolution, bucket_at) for the name prefix; both are ranges
     * the optimiser can seek into, and neither reads outside the window.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function totals(string $range, array $window): array
    {
        try {
            $connection = $this->reader->connection();

            $rows = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $connection
                ->table('monitoring_series')
                ->selectRaw('metric, SUM(samples) AS readings, SUM(value_sum) AS total')
                ->where('metric', 'like', self::SERIES . '%')
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('metric')
                ->limit(self::MAX_METRIC_GROUPS + 1), self::MAX_METRIC_GROUPS);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read costs the numbers,
            // while letting it escape would blank the collection banner that explains them.
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => 'The series table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.',
                'source' => self::SOURCE,
                'by_metric' => [],
                'truncated' => false,
                'limit' => self::MAX_METRIC_GROUPS,
            ];
        }

        $byMetric = [];
        foreach ($rows['rows'] as $row) {
            $name = (string) $row->metric;
            $byMetric[$name] ??= ['readings' => 0, 'total' => 0.0];
            $byMetric[$name]['readings'] += (int) $row->readings;
            $byMetric[$name]['total'] += (float) $row->total;
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => self::SOURCE,
            'by_metric' => $byMetric,
            'truncated' => $rows['truncated'],
            'limit' => self::MAX_METRIC_GROUPS,
        ];
    }

    /**
     * The same series again, split by the page they were measured on.
     *
     * Ordered by volume so the cut, when there is one, falls on the pages nobody visits rather than
     * on the busiest ones. The list is exact — the series names are named rather than prefixed —
     * because this is the heavier of the two reads.
     *
     * Rides the same two indexes as totals(): the window is the range, the names are the seek.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function pages(string $range, array $window): array
    {
        $names = array_merge($this->timingMetrics(), $this->counterMetrics());

        try {
            $connection = $this->reader->connection();

            $rows = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $connection
                ->table('monitoring_series')
                ->selectRaw('metric, label, SUM(samples) AS readings, SUM(value_sum) AS total')
                ->whereIn('metric', $names)
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('metric', 'label')
                ->orderByRaw('SUM(samples) DESC')
                ->limit(self::MAX_PAGE_GROUPS + 1), self::MAX_PAGE_GROUPS);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::SOURCE,
                'by_metric' => [],
                'distinct' => null,
                'truncated' => false,
                'limit' => self::MAX_PAGE_GROUPS,
            ];
        }

        $byMetric = [];
        foreach ($rows['rows'] as $row) {
            $name = (string) $row->metric;
            $page = $this->pageLabel($row->label);

            $byMetric[$name][$page] ??= ['readings' => 0, 'total' => 0.0];
            $byMetric[$name][$page]['readings'] += (int) $row->readings;
            $byMetric[$name][$page]['total'] += (float) $row->total;
        }

        $paths = [];
        foreach ($byMetric as $series) {
            foreach (array_keys($series) as $path) {
                $paths[$path] = true;
            }
        }

        return [
            'state' => 'ok',
            'note' => null,
            'source' => self::SOURCE,
            'by_metric' => $byMetric,
            // Counted across every series before the per-metric lists are cut to ten, so the card
            // above the tables is not a count of the tables.
            'distinct' => count($paths),
            'truncated' => $rows['truncated'],
            'limit' => self::MAX_PAGE_GROUPS,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The five metrics

    /**
     * One row per metric: the band split, the mean, and the pages that fared worst.
     *
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $pages
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    private function metrics(array $totals, array $pages, array $collection): array
    {
        if ($totals['state'] !== 'ok') {
            return [
                'state' => $totals['state'],
                'note' => $totals['note'],
                'remedy' => $totals['remedy'] ?? null,
                'source' => self::SOURCE,
                'rows' => [],
                'truncated' => false,
                'pages_measured' => null,
                'pages_truncated' => false,
                'pages_limit' => self::MAX_PAGES_PER_METRIC,
            ];
        }

        $rows = [];
        foreach (WebVitalsRecorder::METRICS as $key => $rules) {
            $rows[] = $this->metricRow((string) $key, $rules, $totals, $pages);
        }

        $measured = array_filter($rows, static fn (array $row) => $row['state'] === 'ok');

        return [
            'state' => $measured === [] ? 'no_data' : 'ok',
            'note' => $measured === [] ? $this->nothingInWindow($collection) : null,
            'remedy' => $measured === [] ? ($collection['remedy'] ?? null) : null,
            'source' => self::SOURCE,
            'rows' => $rows,
            'truncated' => $totals['truncated'],
            'pages_measured' => $pages['distinct'] ?? null,
            'pages_truncated' => (bool) ($pages['truncated'] ?? false),
            'pages_limit' => self::MAX_PAGES_PER_METRIC,
        ];
    }

    /**
     * One metric.
     *
     * The band counters and the timing series are written together, one of each per accepted
     * reading, so when any band of a metric appears in the window a band that does not appear is a
     * measured zero rather than a missing read — that is the one inference this row makes, and it is
     * why "0 poor" is printed as a number. When no band appears at all, nothing is inferred: the
     * counts stay null and the row states no_data.
     *
     * @param  array{good: int, poor: int, unit: string, max: int}  $rules
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    private function metricRow(string $key, array $rules, array $totals, array $pages): array
    {
        $names = self::NAMES[$key] ?? null;
        $scale = ($rules['unit'] ?? 'ms') === 'score_x1000' ? 1000 : 1;
        $decimals = $scale === 1000 ? 3 : 0;

        $timing = $totals['by_metric'][self::SERIES . $key] ?? null;
        $readings = $timing === null ? null : (int) $timing['readings'];
        // Zero readings with a row present would be a bucket written with no samples, which the
        // writer cannot produce — but dividing by it would be an invented average either way.
        $average = $readings !== null && $readings > 0
            ? round(((float) $timing['total'] / $readings) / $scale, $decimals + 1)
            : null;

        $counts = $this->bandCounts($key, $totals['by_metric']);
        $rated = $counts === null ? null : array_sum($counts);

        return [
            'key' => $key,
            'abbreviation' => $names['abbreviation'] ?? strtoupper($key),
            // Null for a metric this build has no wording for: the view prints the stored key
            // instead of minting a language entry from a column.
            'label_key' => $names['label'] ?? null,
            'description' => $names['description'] ?? null,
            'unit' => $scale === 1000 ? 'score' : 'ms',
            'decimals' => $decimals,
            'stored_multiplier' => $scale,
            // Said on the card rather than left to a reader who knows the metric: 0.04 with no
            // unit beside four figures in milliseconds is the one number here that can be
            // misread as a duration.
            'unit_note' => $scale === 1000
                ? 'A unitless score rather than a duration. It is stored multiplied by a thousand so it can share one pipeline with the timings, and divided back for display here.'
                : null,
            'thresholds' => [
                'good' => round((float) ($rules['good'] ?? 0) / $scale, $decimals),
                'poor' => round((float) ($rules['poor'] ?? 0) / $scale, $decimals),
                'ceiling' => round((float) ($rules['max'] ?? 0) / $scale, $decimals),
            ],
            'state' => $rated === null && $readings === null ? 'no_data' : 'ok',
            'note' => $rated === null && $readings === null
                ? 'No browser reported this metric inside the selected window.'
                : null,
            'readings' => $readings,
            'rated' => $rated,
            'average' => $average,
            // Both are written per reading, so a gap between them means one of the two families was
            // cut by retention or a partial rollup. Published rather than reconciled.
            'counts_agree' => $readings === null || $rated === null ? null : $readings === $rated,
            'bands' => $this->bands($counts, $rated),
            'pages' => $this->metricPages($key, $scale, $decimals, $pages),
        ];
    }

    /**
     * The three band counters for one metric, or null when the window holds none of them.
     *
     * @param  array<string, array{readings: int, total: float}>  $byMetric
     * @return array<string, int>|null
     */
    private function bandCounts(string $key, array $byMetric): ?array
    {
        $counts = [];
        $seen = false;

        foreach (self::BANDS as $band) {
            $stored = $byMetric[self::SERIES . $key . '.' . $band] ?? null;
            $seen = $seen || $stored !== null;
            $counts[$band] = $stored === null ? 0 : (int) $stored['readings'];
        }

        return $seen ? $counts : null;
    }

    /**
     * The band split as shares of the readings that were rated.
     *
     * @param  array<string, int>|null  $counts
     * @return array<int, array<string, mixed>>
     */
    private function bands(?array $counts, ?int $rated): array
    {
        $bands = [];

        foreach (self::BANDS as $band) {
            $bands[] = [
                'band' => $band,
                'count' => $counts === null ? null : $counts[$band],
                // No rated reading means no share. A zero here would read as "none of the visits
                // were good", which is a measurement nobody took.
                'share_pct' => $counts === null || $rated === null || $rated <= 0
                    ? null
                    : round(100 * $counts[$band] / $rated, 1),
            ];
        }

        return $bands;
    }

    /**
     * The pages that fared worst for one metric.
     *
     * Ranked by the share of readings in the poor band, with the count beside it: a single visit at
     * 100% poor and four hundred at 12% are both real, and only the count tells them apart.
     *
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    private function metricPages(string $key, int $scale, int $decimals, array $pages): array
    {
        if ($pages['state'] !== 'ok') {
            return [
                'state' => $pages['state'],
                'note' => $pages['note'],
                'rows' => [],
                'truncated' => false,
                'limit' => self::MAX_PAGES_PER_METRIC,
                'any_poor' => false,
            ];
        }

        $paths = [];
        foreach (array_keys($pages['by_metric'][self::SERIES . $key] ?? []) as $path) {
            $paths[(string) $path] = true;
        }
        foreach (self::BANDS as $band) {
            foreach (array_keys($pages['by_metric'][self::SERIES . $key . '.' . $band] ?? []) as $path) {
                $paths[(string) $path] = true;
            }
        }

        $rows = [];
        foreach (array_keys($paths) as $path) {
            $timing = $pages['by_metric'][self::SERIES . $key][$path] ?? null;
            $readings = $timing === null ? null : (int) $timing['readings'];

            $counts = [];
            $seen = false;
            foreach (self::BANDS as $band) {
                $stored = $pages['by_metric'][self::SERIES . $key . '.' . $band][$path] ?? null;
                $seen = $seen || $stored !== null;
                $counts[$band] = $stored === null ? 0 : (int) $stored['readings'];
            }

            $rated = $seen ? array_sum($counts) : null;

            $rows[] = [
                'path' => $path,
                'readings' => $readings,
                'rated' => $rated,
                'good' => $seen ? $counts['good'] : null,
                'needs_improvement' => $seen ? $counts['needs_improvement'] : null,
                'poor' => $seen ? $counts['poor'] : null,
                'poor_share_pct' => $rated !== null && $rated > 0 ? round(100 * $counts['poor'] / $rated, 1) : null,
                'average' => $readings !== null && $readings > 0
                    ? round(((float) $timing['total'] / $readings) / $scale, $decimals + 1)
                    : null,
            ];
        }

        usort($rows, static function (array $first, array $second) {
            return [$second['poor_share_pct'] ?? -1, $second['rated'] ?? -1, $first['path']]
                <=> [$first['poor_share_pct'] ?? -1, $first['rated'] ?? -1, $second['path']];
        });

        $anyPoor = false;
        foreach ($rows as $row) {
            $anyPoor = $anyPoor || ($row['poor'] ?? 0) > 0;
        }

        return [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === [] ? 'No page reported this metric inside the selected window.' : null,
            'rows' => array_slice($rows, 0, self::MAX_PAGES_PER_METRIC),
            'truncated' => count($rows) > self::MAX_PAGES_PER_METRIC,
            'limit' => self::MAX_PAGES_PER_METRIC,
            'any_poor' => $anyPoor,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The cards above the tables

    /**
     * The readings drawn as single values.
     *
     * The three Core Web Vitals are given as the share of visits in the GOOD band, which is the one
     * figure this store can compute exactly. TTFB and FCP are diagnostics for those three rather
     * than verdicts of their own, so they are drawn in full below and not summarised here.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $collection
     * @return array<string, Metric>
     */
    private function headline(array $metrics, array $collection): array
    {
        if ($metrics['state'] === 'failed') {
            return [];
        }

        $headline = [];

        $readings = $collection['readings_in_window'];
        $headline['readings_received_in_this_window'] = $readings === null
            ? Metric::noData(self::SOURCE, 'The stored readings could not be counted for this window.')
            : Metric::of(
                value: $readings,
                source: self::SOURCE,
                unit: null,
                // A measured zero, and said as one: no browser reported anything in this window,
                // which is a statement about visits and never about how fast the shop was.
                note: $readings === 0
                    ? 'No browser reported a vital inside this window. That is a count of visits that reported, not a verdict on speed.'
                    : 'One reading per metric per page load, reported by the browser as the page is closed.',
            );

        $pages = $metrics['pages_measured'];
        $headline['pages_measured'] = $pages === null || $pages === 0
            ? Metric::noData(
                self::SOURCE,
                $pages === null
                    ? 'The pages could not be read for this window.'
                    : 'No page reported a vital inside this window.',
            )
            : Metric::of(
                value: $pages,
                source: self::SOURCE,
                unit: null,
                note: 'Distinct normalised page patterns, not distinct URLs: /product/{slug} is one page here.',
            );

        foreach ($metrics['rows'] as $row) {
            $key = self::NAMES[$row['key']]['headline'] ?? null;
            if ($key === null) {
                continue;
            }

            $good = $row['bands'][0]['share_pct'] ?? null;

            $headline[$key] = $good === null
                ? Metric::noData(
                    self::SOURCE,
                    $row['state'] === 'ok'
                        ? 'Readings arrived but none of them was rated, so no share can be given.'
                        : 'No browser reported this metric inside the selected window.',
                )
                : Metric::of(
                    value: $good,
                    source: self::SOURCE,
                    unit: '%',
                    note: 'Share of ' . $this->readingCount((int) $row['rated']) . ' rated in this window. A band share, not a p75.',
                );
        }

        return $headline;
    }

    // -------------------------------------------------------------------------------------------
    // The chart

    /**
     * Readings received over the window, and how many of them were poor.
     *
     * Counted across all five metrics on purpose: this line answers "did shoppers keep arriving,
     * and did their experience change during the window", which is a question about the shape of
     * the two counts rather than about any one metric. No average is published on it — a mean over
     * five metrics measured in two different units would be a number with no meaning.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window): array
    {
        $counters = $this->counterMetrics();
        $poor = $this->counterMetrics('poor');
        $limit = min(self::MAX_TIMELINE_BUCKETS, $this->bucketsInWindow($window) + 1);

        try {
            $connection = $this->reader->connection();
            $placeholders = implode(',', array_fill(0, count($poor), '?'));

            // Both counts are folded inside one grouped row per bucket. Grouping by bucket and
            // metric would return fifteen rows per bucket against a limit counted in buckets, and
            // because the read is ordered oldest first it is the newest points that fall off.
            $read = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $connection
                ->table('monitoring_series')
                ->selectRaw(
                    'bucket_at, SUM(samples) AS rated, SUM(CASE WHEN metric IN (' . $placeholders . ') THEN samples ELSE 0 END) AS poor',
                    $poor,
                )
                ->whereIn('metric', $counters)
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->limit($limit));
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'source' => self::SOURCE,
                'points' => [],
                'truncated' => false,
            ];
        }

        // The minute tail is folded into this window's own buckets, so the bar width of the line
        // does not change halfway along it.
        $folded = [];
        foreach ($read['rows'] as $row) {
            $bucket = $this->truncateToWindow((string) $row->bucket_at, $window['resolution']);
            $folded[$bucket] ??= ['rated' => 0, 'poor' => 0];
            $folded[$bucket]['rated'] += (int) $row->rated;
            $folded[$bucket]['poor'] += (int) $row->poor;
        }

        ksort($folded);

        $points = [];
        foreach ($folded as $bucket => $counts) {
            $points[] = [
                't' => Clock::parse($bucket)->toIso8601String(),
                'hits' => $counts['rated'],
                'errors' => $counts['poor'],
            ];
        }

        return [
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => count($points) >= 2
                ? null
                : (count($points) === 1
                    ? 'Only one bucket in this window holds a reading, and one point is not a line.'
                    : 'No reading was recorded in this window.'),
            'source' => self::SOURCE,
            'points' => $points,
            'truncated' => count($points) >= $limit,
        ];
    }

    /**
     * How many buckets the chosen window can hold — the figure the chart read is bounded by.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     */
    private function bucketsInWindow(array $window): int
    {
        return match ($window['resolution']) {
            'day' => (int) ceil($window['minutes'] / 1440),
            'hour' => (int) ceil($window['minutes'] / 60),
            default => $window['minutes'],
        };
    }

    /** A minute bucket's timestamp, rounded down to the bucket this window draws. */
    private function truncateToWindow(string $bucketAt, string $resolution): string
    {
        $moment = Clock::parse($bucketAt);

        return match ($resolution) {
            'day' => $moment->startOfDay()->toDateTimeString(),
            'hour' => $moment->startOfHour()->toDateTimeString(),
            default => $moment->startOfMinute()->toDateTimeString(),
        };
    }

    // -------------------------------------------------------------------------------------------
    // What this page is not

    /**
     * Which figure is on the screen, said in the payload rather than left to the view.
     *
     * @return array<string, mixed>
     */
    private function figure(): array
    {
        return [
            'shown' => 'band_shares_and_mean',
            'standard' => 'p75',
            'source' => self::RECORDER,
            'why' => 'The published way to read a Core Web Vital is the 75th percentile of visits. This store keeps per-minute aggregates rather than one sample per visit, so a p75 cannot be computed from it — and a p75 taken from a mean is not a p75. The band shares below are exact counts of how many readings fell either side of the published thresholds; the figure beside them is the arithmetic mean of the readings, labelled as a mean.',
        ];
    }

    /**
     * The three things somebody could otherwise read into this page that are not true.
     *
     * @return array<int, array<string, string>>
     */
    private function caveats(): array
    {
        return [
            [
                'key' => 'not_a_p75',
                'text' => 'Nothing on this page is a p75. Google\'s thresholds are applied per reading to produce the band shares, which is exact; the average is an average and is labelled as one.',
            ],
            [
                'key' => 'server_time_is_not_ttfb',
                'text' => 'The response times in the Requests section are not TTFB. They measure PHP only, from the first line of the framework to the last, and exclude DNS, TLS, the network and any queueing in the web server — which is most of what a shopper waits through. TTFB here is the browser\'s own navigation timing.',
            ],
            [
                'key' => 'storefront_only',
                'text' => 'Only storefront pages report. The beacon is included by resources/themes/default/layouts/front-end/app.blade.php and by nothing else, so the admin panel, the vendor panel and the API contribute no readings at all — and a page with no readings here had no measured visit, which is not the same as a fast page.',
            ],
            [
                'key' => 'one_reading_per_load',
                'text' => 'A page load contributes at most one reading per metric, sent once as the page is hidden or closed. A visitor who never leaves the first page still reports, but a browser too old for PerformanceObserver reports nothing at all and is invisible here rather than counted as good.',
            ],
            [
                'key' => 'clamped',
                'text' => 'Readings from the browser are trusted only as numbers: a negative value or one past the metric ceiling is refused by the recorder rather than averaged in, so a tab left in the background for an hour cannot poison the window it lands in.',
            ],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Everything the store holds that this page does not draw

    /**
     * web.vitals series this page draws nowhere.
     *
     * Normally empty. It exists so a metric the recorder grows cannot silently disappear: an undrawn
     * measurement is indistinguishable from an unmeasured one.
     *
     * @param  array<string, mixed>  $totals
     * @return array<int, array{metric: string, state: string}>
     */
    private function unrendered(array $totals): array
    {
        if ($totals['state'] !== 'ok') {
            return [];
        }

        $drawn = array_merge($this->timingMetrics(), $this->counterMetrics());
        $unrendered = [];

        foreach (array_keys($totals['by_metric']) as $name) {
            if (in_array($name, $drawn, true)) {
                continue;
            }

            $unrendered[] = ['metric' => (string) $name, 'state' => 'ok'];
        }

        return $unrendered;
    }

    // -------------------------------------------------------------------------------------------

    /** @return array<int, string> */
    private function timingMetrics(): array
    {
        return array_map(
            static fn (string $key) => self::SERIES . $key,
            array_keys(WebVitalsRecorder::METRICS),
        );
    }

    /**
     * The band counters, all of them or one band of each.
     *
     * @return array<int, string>
     */
    private function counterMetrics(?string $band = null): array
    {
        $names = [];

        foreach (array_keys(WebVitalsRecorder::METRICS) as $key) {
            foreach ($band === null ? self::BANDS : [$band] as $wanted) {
                $names[] = self::SERIES . $key . '.' . $wanted;
            }
        }

        return $names;
    }

    /**
     * Why the window is empty, in the words the collection block already worked out.
     *
     * @param  array<string, mixed>  $collection
     */
    private function nothingInWindow(array $collection): string
    {
        if ($collection['state'] !== 'ok') {
            return (string) $collection['note'];
        }

        return 'No browser reported a vital inside this window. The last reading arrived at '
            . ($collection['last_reading_at'] ?? 'an unreadable time')
            . '; readings appear only when somebody visits the storefront, so an empty window is a quiet shop rather than a fast one.';
    }

    /** "1 reading" rather than "1 readings": these sentences are read on their own in a card. */
    private function readingCount(int $count): string
    {
        return number_format($count) . ($count === 1 ? ' reading' : ' readings');
    }

    /** A stored page pattern, bounded and redacted before it reaches a page an operator can screenshot. */
    private function pageLabel(mixed $stored): string
    {
        if (!is_string($stored) || trim($stored) === '') {
            return '/';
        }

        return $this->redactor->text(mb_substr(trim($stored), 0, 96));
    }

    /**
     * A failed read, said in one line that is safe to print.
     *
     * A QueryException carries the statement and its bindings, and an exception message is one of
     * the most reliable places in an application to find a token or a customer's address.
     */
    private function failureNote(\Throwable $exception): string
    {
        return class_basename($exception) . ': '
            . $this->redactor->text(mb_substr($exception->getMessage(), 0, 400));
    }

    /** A stored UTC stamp, in the timezone the dashboard renders in. */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the reading really
            // arrived, and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    private function minutesSince(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) intdiv((int) Clock::parse($stored)->diffInSeconds(Clock::now(), false), 60);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every reading counted in the window, or null when the totals could not be read.
     *
     * @param  array<string, mixed>  $totals
     */
    private function sumReadings(array $totals): ?int
    {
        $total = 0;

        foreach ($this->timingMetrics() as $name) {
            $total += (int) ($totals['by_metric'][$name]['readings'] ?? 0);
        }

        return $total;
    }
}
