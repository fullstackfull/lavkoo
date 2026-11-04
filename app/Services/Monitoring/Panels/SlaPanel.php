<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Availability, the error budget it spends, and how long the incidents took.
 *
 * Nothing in this system stores an uptime percentage, so every number on this page is derived here
 * from measurements taken elsewhere: the check.up series CheckRunner writes on every probe, the
 * timestamps IncidentManager stamps on an incident, and the request and dependency buckets the
 * ingest already folds. That is deliberate — a stored uptime figure is a claim, and a figure
 * recomputed from the probes each time it is asked for can be argued with.
 *
 * ONE RULE MAKES THE NUMBER DEFENSIBLE, and it is CheckRunner's, mirrored here rather than
 * reinvented: a check that was not_configured, not_supported or unknown is NEITHER UP NOR DOWN and
 * is excluded from the denominator entirely. It is never folded in as 100%. CheckRunner enforces
 * this at the point of writing — those statuses produce no check.up sample at all — so the
 * exclusion is already in the data; what this panel adds is saying out loud which checks it
 * removed and why, because an availability figure whose denominator is invisible is a slogan.
 *
 * The second thing this page must not be allowed to imply: availability here means THE CHECKS THAT
 * RAN SAID THE COMPONENT ANSWERED. It does not mean customers could shop. A probe of the database
 * connection is not a checkout, and unless a synthetic journey is configured nothing on this
 * deployment ever exercises one. So the basis block states the denominator, the probe cadence and
 * whether any journey contributed, and the view leads with it rather than with the percentage.
 *
 * Availability is counted in PROBES, not minutes, and the error budget with it. Minutes between two
 * probes are not observed, so spending a budget in minutes would be spending time nobody measured;
 * the minute figures on this page are labelled as what they are — the probe count multiplied by the
 * five-minute cadence — and the probe counts are what the arithmetic is done in.
 *
 * MTTD is real but degenerate, and says so beside itself: detected_at is stamped when the alert
 * rule fired and started_at is when the metric first breached, so their difference is the rule's
 * hold time rather than how long anybody took to notice. MTTR is genuine. Both carry the number of
 * incidents they were averaged over, because a mean with no n beside it is not a statistic.
 */
class SlaPanel implements Panel
{
    /** The availability series CheckRunner publishes: 1.0 for up, 0.0 for down, one point per probe. */
    private const UP_METRIC = 'check.up';

    private const SERIES_SOURCE = 'monitoring_series (check.up)';

    private const RESULTS_SOURCE = 'monitoring_check_results';

    private const INCIDENTS_SOURCE = 'monitoring_incidents';

    private const ROUTES_SOURCE = 'monitoring_request_buckets';

    private const DEPENDENCIES_SOURCE = 'monitoring_dependency_buckets';

    /** The class whose exclusion rule this page mirrors, named so the claim can be checked. */
    private const RULE_SOURCE = 'app/Services/Monitoring/Checks/CheckRunner.php';

    /** `monitoring:check` in bootstrap/app.php. The cadence every coverage figure is measured against. */
    private const PROBE_INTERVAL_MINUTES = 5;

    /**
     * Neither up nor down.
     *
     * CheckRunner::publishSeries() skips these statuses, so they never reach the denominator. The
     * list is restated here because this page has to be able to name what it left out.
     */
    private const EXCLUDED_STATUSES = ['not_configured', 'not_supported', 'unknown'];

    /** The only status that writes a 1.0. A degraded probe answered late or wrongly and counts as down. */
    private const UP_STATUSES = ['ok'];

    /** Statuses that write a 0.0. */
    private const DOWN_STATUSES = ['degraded', 'failing'];

    /** The whole CheckResult vocabulary — the allowlist that makes translate() safe on a stored value. */
    private const CHECK_STATUSES = ['ok', 'degraded', 'failing', 'unknown', 'not_configured', 'not_supported'];

    /** Check kinds the schema allows, same reason. */
    private const CHECK_KINDS = ['health', 'synthetic'];

    /** The incident severity vocabulary the schema allows. */
    private const INCIDENT_SEVERITIES = ['critical', 'major', 'minor', 'warning'];

    /** The incident status vocabulary the schema allows. */
    private const INCIDENT_STATUSES = ['open', 'investigating', 'monitoring', 'resolved'];

    /** The prefix SyntheticCheck gives every journey key, so a journey is recognisable in the series. */
    private const SYNTHETIC_PREFIX = 'synthetic';

    /** Distinct checks the availability table draws. */
    private const MAX_CHECKS = 50;

    /** Distinct check_key + status groups read out of the history. */
    private const MAX_RESULT_GROUPS = 200;

    /** Incidents folded into MTTD and MTTR. */
    private const MAX_INCIDENTS = 1000;

    /** Incidents listed individually under the means. */
    private const MAX_INCIDENT_ROWS = 25;

    /** Routes in the per-route success-rate table. */
    private const MAX_ROUTES = 25;

    /** Outbound services in the dependency table. */
    private const MAX_SERVICES = 50;

    /** Readings drawn as single values above the tables. Every one is honestly one number. */
    private const HEADLINE = [
        'availability_across_checks',
        'checks_in_the_denominator',
        'probes_recorded',
        'probe_coverage',
        'mean_time_to_detect',
        'mean_time_to_recover',
        'incidents_started_in_this_window',
    ];

    /**
     * The exception behind each failed block, kept out of the payload.
     *
     * Metric's only FAILED factory takes a Throwable, and a card built from a block's note alone
     * would have to invent an exception class to wrap it in. The real one is kept here — never
     * serialised, so the payload stays plain arrays — and handed back when the card is built.
     *
     * @var array<string, \Throwable>
     */
    private array $failures = [];

    public function __construct(
        private readonly SeriesReader $reader,
        private readonly MonitoringSettings $settings,
        private readonly Redactor $redactor,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $history = $this->history($range);
        $availability = $this->availability($range, $window, $history);
        $objective = $this->objective($availability, $window);
        // The objective is what turns a percentage into a verdict, so the tone it implies is
        // written back onto the availability rows rather than re-derived in the view. Without a
        // stored objective every row stays unscored, which is the honest colour for a number
        // nothing is judging.
        $availability = $this->scored($availability, $objective);
        $incidents = $this->incidents($range);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'basis' => $this->basis($window, $availability, $history),
            'headline' => $this->headline($availability, $incidents),
            'availability' => $availability,
            'excluded' => $this->excluded($history),
            'objective' => $objective,
            'incidents' => $incidents,
            'routes' => $this->routes($range, $window),
            'dependencies' => $this->dependencies($range, $window),
            // This panel reads no collector, so there is no reading it could be quietly dropping.
            // Published empty rather than omitted so the footer that names undrawn readings is the
            // same shape on every section.
            'unrendered' => [],
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the figures on this page are figures OF

    /**
     * The denominator, stated before the percentage that rests on it.
     *
     * Every field here is a fact about the measurement rather than a measurement, which is why it
     * carries no state: none of it can be missing. The one three-valued entry is whether a customer
     * journey contributed — true, false, or null when availability could not be read at all and the
     * question therefore has no answer yet.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<string, mixed>  $availability
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function basis(array $window, array $availability, array $history): array
    {
        $rawDays = app(MonitoringSettings::class)->retentionDays('hour_days', 90);
        $rolledDays = app(MonitoringSettings::class)->retentionDays('day_days', 400);
        $windowDays = $window['minutes'] / 1440;

        $synthetic = null;
        if ($availability['state'] === 'ok') {
            $synthetic = false;
            foreach ($availability['rows'] as $row) {
                if ($row['is_journey']) {
                    $synthetic = true;

                    break;
                }
            }
        }

        return [
            'probe_interval_minutes' => self::PROBE_INTERVAL_MINUTES,
            'up_statuses' => self::UP_STATUSES,
            'down_statuses' => self::DOWN_STATUSES,
            'excluded_statuses' => self::EXCLUDED_STATUSES,
            // The vocabularies a stored value may be translated against. A status column is a free
            // string at the database level, and translate() persists any key it has not seen — so
            // the view translates a value only when it is one of ours.
            'check_statuses' => self::CHECK_STATUSES,
            'check_kinds' => self::CHECK_KINDS,
            'rule_source' => self::RULE_SOURCE,
            'series_source' => self::SERIES_SOURCE,
            'results_source' => self::RESULTS_SOURCE,
            // Null, not false: with no availability reading, "no journey contributed" would be a
            // finding about the shop rather than an admission that nothing was read.
            'journey_in_denominator' => $synthetic,
            'monitoring_enabled' => (bool) config('monitoring.enabled', true),
            'raw_history_days' => $rawDays,
            'rolled_history_days' => $rolledDays,
            // Past the raw history the only surviving record is the daily rollup of check.up, which
            // is what this window is then read from. Said out loud because a report that silently
            // stops at ninety days is a report that quietly lies about the quarter.
            'beyond_raw_history' => $windowDays > $rawDays,
            'checks_that_ran' => $history['state'] === 'ok' ? $history['checks_seen'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $availability
     * @param  array<string, mixed>  $incidents
     * @return array<string, Metric>
     */
    private function headline(array $availability, array $incidents): array
    {
        $totals = $availability['totals'];
        $unavailable = $availability['state'] !== 'ok';

        $readings = [
            'availability_across_checks' => $unavailable || $totals['availability_pct'] === null
                ? $this->missing($availability, self::SERIES_SOURCE, 'availability')
                : Metric::of(
                    value: $totals['availability_pct'],
                    source: self::SERIES_SOURCE,
                    unit: '%',
                    note: 'Pooled over every probe of every check that ran.',
                ),
            'checks_in_the_denominator' => $unavailable
                ? $this->missing($availability, self::SERIES_SOURCE, 'availability')
                : Metric::of(value: $totals['checks'], source: self::SERIES_SOURCE),
            'probes_recorded' => $unavailable
                ? $this->missing($availability, self::SERIES_SOURCE, 'availability')
                : Metric::of(value: $totals['samples'], source: self::SERIES_SOURCE),
            'probe_coverage' => $unavailable || $totals['coverage_pct'] === null
                ? $this->missing($availability, self::SERIES_SOURCE, 'availability')
                : Metric::of(
                    value: $totals['coverage_pct'],
                    source: self::SERIES_SOURCE,
                    unit: '%',
                    note: 'Of the probes a ' . self::PROBE_INTERVAL_MINUTES . '-minute cadence should have produced.',
                ),
            'mean_time_to_detect' => $incidents['mttd_seconds'] === null
                ? $this->missing($incidents, self::INCIDENTS_SOURCE, 'incidents')
                : Metric::of(
                    value: $incidents['mttd_seconds'],
                    source: self::INCIDENTS_SOURCE,
                    unit: 's',
                    note: 'Averaged over ' . $incidents['mttd_samples'] . '.',
                ),
            'mean_time_to_recover' => $incidents['mttr_seconds'] === null
                ? $this->missing($incidents, self::INCIDENTS_SOURCE, 'incidents')
                : Metric::of(
                    value: $incidents['mttr_seconds'],
                    source: self::INCIDENTS_SOURCE,
                    unit: 's',
                    note: 'Averaged over ' . $incidents['mttr_samples'] . '.',
                ),
            'incidents_started_in_this_window' => $incidents['state'] === 'failed'
                ? $this->missing($incidents, self::INCIDENTS_SOURCE, 'incidents')
                : Metric::of(value: $incidents['started'], source: self::INCIDENTS_SOURCE),
        ];

        $headline = [];
        foreach (self::HEADLINE as $name) {
            $metric = $readings[$name] ?? null;
            if (!$metric instanceof Metric) {
                continue;
            }
            if ($metric->isOk() && !is_scalar($metric->value)) {
                continue;
            }

            $headline[$name] = $metric;
        }

        return $headline;
    }

    /**
     * A block's unavailability, lifted into a Metric so a card can carry the same reason the table does.
     *
     * @param  array<string, mixed>  $block
     * @param  string  $key  the block's name in $failures, so a failed card names the real exception
     */
    private function missing(array $block, string $source, string $key): Metric
    {
        $state = (string) ($block['state'] ?? 'no_data');
        $note = $block['note'] ?? null;
        $remedy = $block['remedy'] ?? null;

        return match ($state) {
            'not_configured' => Metric::notConfigured(
                source: $source,
                remedy: (string) ($remedy ?? 'Configure the source named beside this reading.'),
                note: $note,
            ),
            'not_supported' => Metric::notSupported(
                source: $source,
                note: (string) ($note ?? 'This deployment cannot produce this reading.'),
                remedy: $remedy,
            ),
            'permission_denied' => Metric::permissionDenied(
                source: $source,
                note: (string) ($note ?? 'The store exists but could not be read.'),
                remedy: $remedy,
            ),
            'collector_offline' => Metric::collectorOffline(
                source: $source,
                note: (string) ($note ?? 'Nothing has written to this store.'),
                remedy: $remedy,
            ),
            // A read that threw must not be labelled "no data": that reads as "nothing happened",
            // which is the single most reassuring thing this page could say about a failure.
            'failed' => Metric::failed(
                source: $source,
                exception: $this->failures[$key] ?? new \RuntimeException((string) ($note ?? 'The read failed and left no message.')),
            ),
            default => Metric::noData(source: $source, note: $note),
        };
    }

    // -------------------------------------------------------------------------------------------
    // Availability, per check, out of the probes that actually ran

    /**
     * Per-check availability over the window.
     *
     * Read as SUM(value_sum) / SUM(samples) rather than value_last: value_last is the newest probe,
     * which is a status, and averaging statuses over a window is the whole question. The rollup
     * folds both columns additively, so the same arithmetic is correct at minute, hour and day
     * resolution — which is what lets a ninety-day figure be read from the daily rows without
     * changing the definition of the number.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function availability(string $range, array $window, array $history): array
    {
        $expected = intdiv(max(0, (int) $window['minutes']), self::PROBE_INTERVAL_MINUTES);

        $base = [
            'source' => self::SERIES_SOURCE,
            'resolution' => $window['resolution'],
            'folded' => $window['resolution'] !== 'minute',
            'seam_at' => null,
            'probe_interval_minutes' => self::PROBE_INTERVAL_MINUTES,
            'expected_samples' => $expected,
            'resolutions_present' => [],
            'counted_results' => null,
            'discrepancy' => null,
            'tail_probes' => null,
            'rows' => [],
            // Null rather than zero on every unavailable path: a denominator that was never read is
            // not a denominator of nothing.
            'totals' => [
                'checks' => null, 'samples' => null, 'up' => null, 'down' => null,
                'availability_pct' => null, 'coverage_pct' => null,
                'down_minutes_at_probe_interval' => null,
            ],
            'truncated' => false,
            'limit' => self::MAX_CHECKS,
        ];

        try {
            $connection = $this->reader->connection();
            $from = $this->reader->since($range);
            $seam = $window['resolution'] === 'minute' ? null : $this->foldSeam($window['resolution'], $from);

            $columns = [
                'label',
                $connection->raw('SUM(samples) AS probes'),
                $connection->raw('SUM(value_sum) AS up'),
                $connection->raw('MIN(bucket_at) AS first_at'),
                $connection->raw('MAX(bucket_at) AS last_at'),
            ];

            $rows = $connection->table('monitoring_series')
                ->where('metric', self::UP_METRIC)
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($query) => $query->where('bucket_at', '<', $seam))
                ->groupBy('label')
                ->limit(self::MAX_CHECKS + 1)
                ->get($columns);

            $tail = null;
            if ($seam !== null) {
                // The tail past the seam, read from the minute rows the rollup has not folded yet.
                // Without it a coarse window is short by everything since the last fold, and the
                // availability headline would disagree with the check history under it.
                $tail = $connection->table('monitoring_series')
                    ->where('metric', self::UP_METRIC)
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->max($from))
                    ->groupBy('label')
                    ->limit(self::MAX_CHECKS + 1)
                    ->get($columns);

                $rows = $rows->concat($tail);
            }
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing availability must not take the
            // incident timings, the route table and the dependency table down with it.
            $this->failures['availability'] = $exception;

            return array_merge($base, [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
            ]);
        }

        $counters = [];
        foreach ($rows as $row) {
            $label = (string) $row->label;
            $counters[$label] ??= ['probes' => 0, 'up' => 0.0, 'first_at' => null, 'last_at' => null];
            $counters[$label]['probes'] += (int) $row->probes;
            $counters[$label]['up'] += (float) $row->up;
            $counters[$label]['first_at'] = $this->earlier($counters[$label]['first_at'], $row->first_at);
            $counters[$label]['last_at'] = $this->later($counters[$label]['last_at'], $row->last_at);
        }

        $present = $this->resolutionsPresent($range);
        // Published even when it is zero. The seam skips the newest folded bucket and reads its
        // minutes instead, so a zero here beside a recent seam is the one shape that means those
        // minutes are missing from the figure rather than simply not folded yet.
        $tailProbes = $tail === null ? null : (int) $tail->sum(static fn ($row) => (int) $row->probes);

        if ($counters === []) {
            return array_merge($base, $this->whyNoAvailability($history, $present, $window), [
                'seam_at' => $seam === null ? null : $this->displayStamp($seam),
                'resolutions_present' => $present,
                'tail_probes' => $tailProbes,
            ]);
        }

        $truncated = count($counters) > self::MAX_CHECKS;
        $counters = array_slice($counters, 0, self::MAX_CHECKS, preserve_keys: true);

        $rowsOut = [];
        $totalProbes = 0;
        $totalUp = 0;
        foreach ($counters as $label => $counter) {
            $probes = $counter['probes'];
            $up = (int) round($counter['up']);
            $down = max(0, $probes - $up);
            $totalProbes += $probes;
            $totalUp += $up;

            $rowsOut[] = [
                'check' => $label,
                'is_journey' => $this->isJourney($label),
                'probes' => $probes,
                'up' => $up,
                'down' => $down,
                'availability_pct' => $probes > 0 ? round(100 * $up / $probes, 3) : null,
                // What one failed probe is worth on this figure. A window holding twelve probes
                // cannot express 99.9% at all, and a percentage printed without its own resolution
                // invites an objective to be judged against arithmetic that could not reach it.
                'one_probe_pct' => $probes > 0 ? round(100 / $probes, 3) : null,
                'expected_probes' => $expected,
                'coverage_pct' => $expected > 0 ? round(100 * $probes / $expected, 1) : null,
                // Derived from the cadence, not observed: the minutes between two probes are not
                // watched, so this is what the failed probes stand for rather than measured outage.
                'down_minutes_at_probe_interval' => $down * self::PROBE_INTERVAL_MINUTES,
                'first_probe_at' => $this->displayStamp($counter['first_at']),
                'last_probe_at' => $this->displayStamp($counter['last_at']),
                // Filled in from the stored objective, where there is one. A percentage with no
                // target behind it is not healthy or critical, it is simply the measurement.
                'target_pct' => null,
                'level' => 'unscored',
            ];
        }

        usort($rowsOut, static fn (array $a, array $b) => [$a['availability_pct'] ?? 101, $a['check']]
            <=> [$b['availability_pct'] ?? 101, $b['check']]);

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'seam_at' => $seam === null ? null : $this->displayStamp($seam),
            'resolutions_present' => $present,
            'counted_results' => $history['state'] === 'ok' ? $history['counted_runs'] : null,
            'discrepancy' => $this->discrepancy($totalProbes, $history, $window),
            'tail_probes' => $tailProbes,
            'rows' => $rowsOut,
            'totals' => [
                'checks' => count($rowsOut),
                'samples' => $totalProbes,
                'up' => $totalUp,
                'down' => max(0, $totalProbes - $totalUp),
                'availability_pct' => $totalProbes > 0 ? round(100 * $totalUp / $totalProbes, 3) : null,
                'coverage_pct' => $expected > 0 && $rowsOut !== []
                    ? round(100 * $totalProbes / ($expected * count($rowsOut)), 1)
                    : null,
                'down_minutes_at_probe_interval' => max(0, $totalProbes - $totalUp) * self::PROBE_INTERVAL_MINUTES,
            ],
            'truncated' => $truncated,
        ]);
    }

    /**
     * Where the folded series buckets stop and the raw minutes take over.
     *
     * SeriesReader::foldSeam() is private and reads monitoring_request_buckets; this is the same
     * seam on monitoring_series. The rollup folds every metric in one pass, so the newest folded
     * bucket at a resolution is the seam for all of them.
     */
    private function foldSeam(string $resolution, Carbon $from): Carbon
    {
        $newestFolded = $this->reader->connection()->table('monitoring_series')
            ->where('resolution', $resolution)
            ->max('bucket_at');

        return $newestFolded !== null ? Clock::parse($newestFolded) : $from;
    }

    /**
     * Which resolutions hold probes for this window, whatever resolution the range reads.
     *
     * Three rows at most, on the series lookup index. It exists so "this range reads minute rows
     * and the probes are in the hour rows" cannot be reported as "nothing was ever written", which
     * sends an operator to check a collector that is working perfectly.
     *
     * @return array<string, int>
     */
    private function resolutionsPresent(string $range): array
    {
        try {
            $connection = $this->reader->connection();
            $rows = $connection->table('monitoring_series')
                ->where('metric', self::UP_METRIC)
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('resolution')
                ->limit(8)
                ->get(['resolution', $connection->raw('SUM(samples) AS probes')]);
        } catch (\Throwable) {
            return [];
        }

        $present = [];
        foreach ($rows as $row) {
            $probes = (int) $row->probes;
            if ($probes > 0) {
                $present[(string) $row->resolution] = $probes;
            }
        }

        return $present;
    }

    /**
     * The series total held against a second, independent record of the same probes.
     *
     * monitoring_check_results is written by the same run as the series and by a different
     * statement, so the two counts should agree. Where they do not, the disagreement is the
     * finding: a silent shortfall in an availability figure is indistinguishable from good news.
     *
     * Only compared where a comparison is meaningful — the raw history is pruned long before the
     * daily rollup is, and a truncated group read has not counted everything it saw.
     *
     * @param  array<string, mixed>  $history
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>|null
     */
    private function discrepancy(int $probes, array $history, array $window): ?array
    {
        $rawDays = app(MonitoringSettings::class)->retentionDays('hour_days', 90);

        if ($history['state'] !== 'ok' || $history['truncated'] || $window['minutes'] / 1440 > $rawDays) {
            return null;
        }

        $counted = (int) $history['counted_runs'];
        if ($counted === $probes) {
            return null;
        }

        return [
            'series_probes' => $probes,
            'recorded_results' => $counted,
            'difference' => $probes - $counted,
            'note' => $probes < $counted
                ? 'The availability series holds fewer probes than the check history recorded, so the percentages above are computed over less than was measured.'
                : 'The availability series holds more probes than the check history recorded, so one of the two records is incomplete.',
            'remedy' => 'A coarse range skips the newest folded bucket and reads its minutes directly; where the minute rows have been pruned or were never written, that bucket is missing here. Compare against a shorter range, and check the rollup with `php artisan schedule:list`.',
        ];
    }

    /**
     * Why there is no availability, which is six different situations with six different fixes.
     *
     * They all draw the same empty table and lead an operator to opposite places — "the checks are
     * not running", "the checks ran and every one of them was excluded", "the probes are here but
     * at another resolution", "this window is simply older than the first probe" — so the reason is
     * read out of the check history rather than left to be guessed.
     *
     * @param  array<string, mixed>  $history
     * @param  array<string, int>  $present
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function whyNoAvailability(array $history, array $present, array $window): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no probe has been recorded since it was disabled. This is not a reading of zero uptime.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if ($present !== []) {
            // The probes are here; this range is reading past them. Saying "nothing was recorded"
            // would send somebody to restart a check runner that has been working all along.
            $stored = implode(', ', array_keys($present));
            $probes = array_sum($present);

            return [
                'state' => 'no_data',
                'note' => 'This range reads the ' . $window['resolution'] . ' rows, and the ' . $probes
                    . ' probes recorded in this window are stored at ' . $stored . ' resolution. They were measured; they are not readable at this range.',
                'remedy' => 'Choose a range that reads those rows — live to 6h read minute rows, 24h and 7d read hour rows, 30d and 90d read day rows — or build the missing resolution with `php artisan monitoring:rollup`.',
            ];
        }

        if ($history['state'] === 'failed') {
            // The series was read and held nothing; it is the EXPLANATION that could not be read.
            // Calling the whole block failed would report a probe store that answered perfectly
            // well as broken, so the state stays no_data and the missing explanation is named.
            return [
                'state' => 'no_data',
                'note' => 'No probe of any check is recorded in this window. Why is not answerable here: the check history that would explain it could not be read — ' . (string) $history['note'],
                'remedy' => null,
            ];
        }

        if ($history['ever'] === false) {
            return [
                'state' => 'collector_offline',
                'note' => 'No health check has ever recorded a result on this deployment, so there is no availability to compute — for this window or any other.',
                'remedy' => 'Run `php artisan monitoring:check` once, then confirm cron fires it every five minutes with `php artisan schedule:list`.',
            ];
        }

        if ($history['state'] === 'ok' && $history['counted_runs'] === 0 && $history['excluded_runs'] > 0) {
            return [
                'state' => 'no_data',
                'note' => 'Every check that ran in this window was excluded from the denominator: ' . $history['excluded_runs']
                    . ' of ' . $history['runs'] . ' results were not_configured, not_supported or unknown, and a check that could not run here is neither up nor down. Counting them as up would report an availability that is not about availability.',
                'remedy' => 'Configure the checks listed below, or read the figure as covering only the components that can be probed here.',
            ];
        }

        if ($history['state'] === 'ok' && $history['runs'] > 0) {
            return [
                'state' => 'collector_offline',
                'note' => $history['runs'] . ' check results were recorded in this window but nothing reached the availability series for them, so uptime cannot be computed from probes that were taken.',
                'remedy' => 'The series is written by the same run that records the result. Run `php artisan monitoring:check -v` and check the monitoring connection is writable.',
            ];
        }

        if ($history['newest_at'] !== null) {
            return [
                'state' => 'no_data',
                'note' => 'No check ran inside this window. The most recent probe on record is ' . $history['newest_at'] . ' (' . Clock::displayTimezone() . ').',
                'remedy' => 'Widen the range, or check that cron is firing `monitoring:check` every five minutes: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => 'No probe of any check is recorded in this window, so there is no availability to report for it.',
            'remedy' => 'Widen the range, or run `php artisan monitoring:check` to take a reading now.',
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The check history behind the denominator

    /**
     * What ran, how often, and what each run said.
     *
     * One grouped read serving three purposes: the reason an empty availability table is empty, the
     * list of checks excluded from the denominator, and the independent count of probes taken that
     * the series figure can be held against.
     *
     * @return array<string, mixed>
     */
    private function history(string $range): array
    {
        $base = [
            'source' => self::RESULTS_SOURCE,
            'rows' => [],
            'runs' => 0,
            'counted_runs' => 0,
            'excluded_runs' => 0,
            'checks_seen' => 0,
            'newest_at' => null,
            'ever' => null,
            'truncated' => false,
            'limit' => self::MAX_RESULT_GROUPS,
        ];

        try {
            $connection = $this->reader->connection();

            $rows = $connection->table('monitoring_check_results')
                ->where('checked_at', '>=', $this->reader->since($range))
                ->groupBy('check_key', 'kind', 'status')
                ->limit(self::MAX_RESULT_GROUPS + 1)
                ->get([
                    'check_key',
                    'kind',
                    'status',
                    $connection->raw('COUNT(*) AS runs'),
                    $connection->raw('MAX(checked_at) AS last_checked_at'),
                    $connection->raw('MAX(id) AS last_id'),
                ]);

            $newest = $connection->table('monitoring_check_results')->max('checked_at');
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
            ]);
        }

        $truncated = $rows->count() > self::MAX_RESULT_GROUPS;
        $rows = $rows->take(self::MAX_RESULT_GROUPS);
        $details = $this->details($rows->pluck('last_id')->all());

        $out = [];
        $runs = 0;
        $counted = 0;
        $excluded = 0;
        $checks = [];
        foreach ($rows as $row) {
            $status = (string) $row->status;
            $isExcluded = in_array($status, self::EXCLUDED_STATUSES, true);
            $count = (int) $row->runs;
            $runs += $count;
            $excluded += $isExcluded ? $count : 0;
            $counted += $isExcluded ? 0 : $count;
            $checks[(string) $row->check_key] = true;

            $out[] = [
                'check' => (string) $row->check_key,
                'kind' => (string) $row->kind,
                'status' => $status,
                'excluded' => $isExcluded,
                'runs' => $count,
                'last_checked_at' => $this->displayStamp($row->last_checked_at),
                'detail' => $details[(int) $row->last_id] ?? null,
            ];
        }

        usort($out, static fn (array $a, array $b) => [$b['runs'], $a['check']] <=> [$a['runs'], $b['check']]);

        return array_merge($base, [
            'state' => $out === [] ? 'no_data' : 'ok',
            'note' => $out === [] ? 'No check recorded a result inside this window.' : null,
            'remedy' => $out === []
                ? 'Run `php artisan monitoring:check`, or widen the range.'
                : null,
            'rows' => $out,
            'runs' => $runs,
            'counted_runs' => $counted,
            'excluded_runs' => $excluded,
            'checks_seen' => count($checks),
            'newest_at' => $this->displayStamp($newest),
            // Three-valued: false only when the table was read and found empty.
            'ever' => $newest !== null,
            'truncated' => $truncated,
        ]);
    }

    /**
     * The detail line of the newest result in each group.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string|null>
     */
    private function details(array $ids): array
    {
        $ids = array_values(array_filter(array_map(static fn ($id) => is_numeric($id) ? (int) $id : null, $ids)));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->reader->connection()->table('monitoring_check_results')
                ->whereIn('id', array_slice($ids, 0, self::MAX_RESULT_GROUPS))
                ->get(['id', 'detail']);
        } catch (\Throwable) {
            // A missing detail costs a sentence; a throw here would cost the whole section.
            return [];
        }

        $details = [];
        foreach ($rows as $row) {
            $details[(int) $row->id] = $this->cleanText($row->detail);
        }

        return $details;
    }

    /**
     * The checks that ran and were left out of the denominator, with what each one said.
     *
     * Drawn as its own block rather than as blank rows in the availability table: "excluded" and
     * "0% available" are opposite claims about a component, and a row that is missing without
     * explanation reads as the second.
     *
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function excluded(array $history): array
    {
        $base = [
            'source' => self::RESULTS_SOURCE,
            'rows' => [],
            'runs' => 0,
            'truncated' => (bool) $history['truncated'],
            'statuses' => self::EXCLUDED_STATUSES,
        ];

        if ($history['state'] === 'failed') {
            return array_merge($base, [
                'state' => 'failed',
                'note' => $history['note'],
                'remedy' => null,
            ]);
        }

        $rows = [];
        foreach ($history['rows'] as $row) {
            if ($row['excluded']) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => $history['state'] === 'ok'
                    ? 'Every check that ran in this window produced a verdict that counts, so nothing was excluded from the denominator.'
                    : (string) $history['note'],
                'remedy' => $history['state'] === 'ok' ? null : $history['remedy'],
            ]);
        }

        $runs = 0;
        foreach ($rows as $row) {
            $runs += $row['runs'];
        }

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $rows,
            'runs' => $runs,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The objective, and the budget it implies

    /**
     * The error budget each availability figure spends.
     *
     * Counted in probes, because probes are what was observed. A budget in minutes would be
     * spending time nobody watched: between two probes five minutes pass unmeasured, and turning
     * that into "43 minutes of downtime allowed this month" dresses an inference as a measurement.
     * The minute equivalents travel beside the probe counts, labelled as the cadence arithmetic
     * they are.
     *
     * No objective is invented. With nothing stored, this is not_configured with the exact write
     * that would fix it — an availability figure judged against a target this page made up would be
     * the most confident wrong number on the dashboard.
     *
     * @param  array<string, mixed>  $availability
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function objective(array $availability, array $window): array
    {
        $remedy = 'No SLA objective is stored. Write one per check with `php artisan tinker`: '
            . "app(App\\Services\\Monitoring\\Support\\MonitoringSettings::class)->put('sla.targets', ['database' => 99.9, 'redis' => 99.5]); "
            . "— or a single objective for every check with put('sla.target', 99.9). This build has no settings form that writes it.";

        $base = [
            'source' => 'monitoring_settings (sla.targets, sla.target)',
            'rows' => [],
            'default_target_pct' => null,
            'stored_checks' => [],
            'window_minutes' => (int) $window['minutes'],
            'probe_interval_minutes' => self::PROBE_INTERVAL_MINUTES,
            'unmatched' => [],
        ];

        $targets = $this->targets();

        if ($targets['error'] !== null) {
            // MonitoringSettings answers an unreadable store with "no settings", which would print
            // "no objective is stored" over an objective that may well be stored. The store is
            // probed separately so the two can be told apart.
            return array_merge($base, [
                'state' => 'failed',
                'note' => $targets['error'],
                'remedy' => null,
                'invalid' => [],
            ]);
        }

        if ($targets['default'] === null && $targets['by_check'] === []) {
            return array_merge($base, [
                'state' => 'not_configured',
                'note' => 'No availability objective is stored, so the measured percentages below are not being judged against anything and no error budget can be derived from them.',
                'remedy' => $remedy,
                'invalid' => $targets['invalid'],
            ]);
        }

        $base['default_target_pct'] = $targets['default'];
        $base['stored_checks'] = array_keys($targets['by_check']);
        $base['invalid'] = $targets['invalid'];

        if ($availability['state'] !== 'ok') {
            return array_merge($base, [
                'state' => $availability['state'],
                'note' => 'An objective is stored, but no availability was measured in this window, so no budget can be shown as spent or remaining. ' . (string) ($availability['note'] ?? ''),
                'remedy' => $availability['remedy'],
            ]);
        }

        $rows = [];
        foreach ($availability['rows'] as $row) {
            $target = $targets['by_check'][$row['check']] ?? $targets['default'];
            if ($target === null || $row['availability_pct'] === null) {
                continue;
            }

            $probes = (int) $row['probes'];
            $allowed = $probes * (1 - $target / 100);
            $burned = (int) $row['down'];
            $met = $row['availability_pct'] >= $target;

            $rows[] = [
                'check' => $row['check'],
                'is_journey' => $row['is_journey'],
                'target_pct' => $target,
                'availability_pct' => $row['availability_pct'],
                'probes' => $probes,
                'budget_probes' => round($allowed, 3),
                'burned_probes' => $burned,
                'remaining_probes' => round($allowed - $burned, 3),
                'burn_pct' => $allowed > 0 ? round(100 * $burned / $allowed, 1) : null,
                // The same budget in minutes, at the probe cadence — a translation of the probe
                // count, never a second measurement of it.
                'budget_minutes_at_probe_interval' => round($allowed * self::PROBE_INTERVAL_MINUTES, 1),
                'burned_minutes_at_probe_interval' => $burned * self::PROBE_INTERVAL_MINUTES,
                // A window whose entire budget is smaller than one probe cannot test this objective
                // at all: the first failure overspends it, and the first success reads as perfect.
                'budget_below_one_probe' => $allowed < 1,
                'one_probe_pct' => $row['one_probe_pct'],
                'met' => $met,
                'level' => match (true) {
                    !$met => 'critical',
                    $allowed > 0 && $burned / $allowed >= 0.75 => 'degraded',
                    default => 'healthy',
                },
            ];
        }

        $measured = array_column($availability['rows'], 'check');
        $unmatched = array_values(array_diff(array_keys($targets['by_check']), $measured));

        if ($rows === []) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'An objective is stored, but not for any check that reported a probe in this window, so there is no budget to compute.',
                'remedy' => 'Store an objective for one of the checks listed above, or set a blanket one with `sla.target`.',
                'unmatched' => $unmatched,
            ]);
        }

        usort($rows, static fn (array $a, array $b) => [$a['availability_pct'] - $a['target_pct'], $a['check']]
            <=> [$b['availability_pct'] - $b['target_pct'], $b['check']]);

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $rows,
            'unmatched' => $unmatched,
        ]);
    }

    /**
     * The objective's verdict, carried back onto the availability rows it judges.
     *
     * @param  array<string, mixed>  $availability
     * @param  array<string, mixed>  $objective
     * @return array<string, mixed>
     */
    private function scored(array $availability, array $objective): array
    {
        if ($availability['state'] !== 'ok' || $objective['state'] !== 'ok') {
            return $availability;
        }

        $scored = [];
        foreach ($objective['rows'] as $row) {
            $scored[$row['check']] = $row;
        }

        foreach ($availability['rows'] as $index => $row) {
            $match = $scored[$row['check']] ?? null;
            if ($match === null) {
                continue;
            }

            $availability['rows'][$index]['target_pct'] = $match['target_pct'];
            $availability['rows'][$index]['level'] = $match['level'];
        }

        return $availability;
    }

    /**
     * Stored objectives, with anything unusable named rather than silently dropped.
     *
     * @return array{default: float|null, by_check: array<string, float>, invalid: array<int, string>, error: string|null}
     */
    private function targets(): array
    {
        $byCheck = [];
        $invalid = [];

        try {
            // The reachability probe MonitoringSettings does not expose: it turns an unreadable
            // store into an empty one, which here would become a false "nothing is configured".
            $this->reader->connection()->table('monitoring_settings')->limit(1)->exists();

            $stored = $this->settings->get('sla.targets', []);
            $default = $this->settings->get('sla.target');
        } catch (\Throwable $exception) {
            return [
                'default' => null,
                'by_check' => [],
                'invalid' => [],
                'error' => Metric::describeFailure($exception),
            ];
        }

        if (is_array($stored)) {
            foreach ($stored as $check => $target) {
                $key = is_string($check) ? mb_substr($check, 0, 96) : '';
                $percentage = $this->percentage($target);

                if ($key === '' || $percentage === null) {
                    // A stored objective that cannot be read is not the same as no objective, and
                    // dropping it silently would leave a check looking unprotected when somebody
                    // has already tried to protect it.
                    $invalid[] = $key === '' ? '(unnamed)' : $key;

                    continue;
                }

                $byCheck[$key] = $percentage;
            }
        }

        return [
            'default' => $this->percentage($default),
            'by_check' => array_slice($byCheck, 0, self::MAX_CHECKS, preserve_keys: true),
            'invalid' => array_slice(array_values(array_unique($invalid)), 0, self::MAX_CHECKS),
            'error' => null,
        ];
    }

    /** An availability objective, or null when the stored value is not one. */
    private function percentage(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $percentage = (float) $value;

        return $percentage > 0 && $percentage <= 100 ? round($percentage, 4) : null;
    }

    // -------------------------------------------------------------------------------------------
    // How long the incidents took

    /**
     * MTTD and MTTR over the incidents that started inside this window.
     *
     * Both means carry the count they were taken over, and a mean over zero incidents is null
     * rather than 0 — "nothing broke" and "we recover instantly" are opposite readings of the same
     * empty table. Durations that run backwards are excluded and counted out loud rather than
     * dragging a mean below zero.
     *
     * @return array<string, mixed>
     */
    private function incidents(string $range): array
    {
        $base = [
            'source' => self::INCIDENTS_SOURCE,
            'started' => 0,
            'resolved' => 0,
            'still_open' => 0,
            'undetected' => 0,
            'out_of_order' => 0,
            'mttd_seconds' => null,
            'mttd_samples' => 0,
            'mttr_seconds' => null,
            'mttr_samples' => 0,
            'longest_seconds' => null,
            'rows' => [],
            'truncated' => false,
            'limit' => self::MAX_INCIDENT_ROWS,
            'severities' => self::INCIDENT_SEVERITIES,
            'statuses' => self::INCIDENT_STATUSES,
            // The caveats travel with the numbers rather than in a footnote under them.
            'mttd_caveat' => 'detected_at is stamped when the alert rule fired and started_at is when the metric first breached, so this gap is the rule hold time rather than how long anybody took to notice.',
            'mttr_definition' => 'Measured from started_at to resolved_at, over incidents that started inside this window and have since closed.',
            'opened_by' => 'Every incident here was opened by the alert engine. An outage that broke no alert rule never became one, so these means describe the incidents that were detected, not the ones that happened.',
        ];

        try {
            $rows = $this->reader->connection()->table('monitoring_incidents')
                ->where('started_at', '>=', $this->reader->since($range))
                ->orderByDesc('started_at')
                ->limit(self::MAX_INCIDENTS + 1)
                ->get(['reference', 'title', 'severity', 'status', 'started_at', 'detected_at', 'resolved_at']);
        } catch (\Throwable $exception) {
            $this->failures['incidents'] = $exception;

            return array_merge($base, [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
            ]);
        }

        $truncated = $rows->count() > self::MAX_INCIDENTS;
        $rows = $rows->take(self::MAX_INCIDENTS);

        $detect = [];
        $resolve = [];
        $started = 0;
        $resolved = 0;
        $stillOpen = 0;
        $undetected = 0;
        $outOfOrder = 0;
        $listed = [];

        foreach ($rows as $row) {
            $started++;
            $isResolved = $row->resolved_at !== null;
            $detectSeconds = $this->elapsedSeconds($row->started_at, $row->detected_at);
            $resolveSeconds = $this->elapsedSeconds($row->started_at, $row->resolved_at);

            if (!$isResolved) {
                $stillOpen++;
            }

            if ($detectSeconds === null) {
                $undetected++;
            } elseif ($detectSeconds < 0) {
                $outOfOrder++;
                $detectSeconds = null;
            } else {
                $detect[] = $detectSeconds;
            }

            if ($isResolved) {
                $resolved++;
                if ($resolveSeconds !== null && $resolveSeconds < 0) {
                    $outOfOrder++;
                    $resolveSeconds = null;
                } elseif ($resolveSeconds !== null) {
                    $resolve[] = $resolveSeconds;
                }
            }

            if (count($listed) < self::MAX_INCIDENT_ROWS) {
                $listed[] = [
                    'reference' => (string) $row->reference,
                    'title' => $this->cleanText($row->title),
                    'severity' => (string) $row->severity,
                    'status' => (string) $row->status,
                    'started_at' => $this->displayStamp($row->started_at),
                    'detect_seconds' => $detectSeconds,
                    'resolve_seconds' => $resolveSeconds,
                    'resolved' => $isResolved,
                ];
            }
        }

        return array_merge($base, [
            'state' => $started === 0 ? 'no_data' : 'ok',
            'note' => $started === 0
                ? 'No incident started inside this window, so there is no time-to-detect or time-to-recover to average. This is the absence of a measurement, not a fast response.'
                : null,
            'remedy' => $started === 0
                ? 'Widen the range. Incidents are opened only by the alert engine — check enabled rules under Monitoring → Alerts if none has ever been opened.'
                : null,
            'started' => $started,
            'resolved' => $resolved,
            'still_open' => $stillOpen,
            'undetected' => $undetected,
            'out_of_order' => $outOfOrder,
            'mttd_seconds' => $detect === [] ? null : round(array_sum($detect) / count($detect), 1),
            'mttd_samples' => count($detect),
            'mttr_seconds' => $resolve === [] ? null : round(array_sum($resolve) / count($resolve), 1),
            'mttr_samples' => count($resolve),
            'longest_seconds' => $resolve === [] ? null : max($resolve),
            'rows' => $listed,
            'truncated' => $truncated,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // The other two success rates, which are different measurements and must not be averaged in

    /**
     * Per-route success rate, from the traffic the shop actually served.
     *
     * A different measurement from probe availability and never folded into it: this one is what
     * happened to real requests, and it can be poor while every probe was green. Success here means
     * "did not end in a server error" — 4xx are carried separately, because a request refused for
     * being wrong is not the service failing.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function routes(string $range, array $window): array
    {
        $base = [
            'source' => self::ROUTES_SOURCE,
            'rows' => [],
            'totals' => [
                'hits' => null, 'errors' => null, 'client_errors' => null,
                'success_rate' => null, 'client_error_rate' => null,
            ],
            'truncated' => false,
            'limit' => self::MAX_ROUTES,
            'definition' => 'Success is a response that was not a 5xx. Client errors are counted separately.',
        ];

        try {
            // Reachability, asked before the reader is trusted. SeriesReader swallows its own
            // throwables and hands back an empty summary, so an unreadable store and a quiet hour
            // arrive here as the same value — and only one of them is "no request was recorded".
            $present = $this->reader->connection()->table('monitoring_request_buckets')
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->limit(1)
                ->exists();

            $summary = $this->reader->requestSummary($range);
            $breakdown = $this->reader->routeBreakdown($range, sort: 'errors', limit: self::MAX_ROUTES + 1);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
            ]);
        }

        if (($summary['has_data'] ?? false) !== true) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => $present
                    ? 'Traffic rows exist in this window but none of them folded into a summary at ' . $window['resolution'] . ' resolution, so no route has a success rate here.'
                    : 'No request was recorded in this window, so no route has a success rate. Zero traffic and zero successes are different facts.',
                'remedy' => $window['resolution'] === 'minute'
                    ? 'Widen the range, or confirm the ingest is flushing: `php artisan monitoring:flush`.'
                    : 'This range reads the ' . $window['resolution'] . ' rows the rollup builds. Choose a shorter range to read the minute rows directly, or check the rollup with `php artisan schedule:list`.',
            ]);
        }

        $truncated = count($breakdown) > self::MAX_ROUTES;
        $rows = [];
        foreach (array_slice($breakdown, 0, self::MAX_ROUTES) as $route) {
            $hits = (int) ($route['hits'] ?? 0);
            $errors = (int) ($route['errors'] ?? 0);

            $rows[] = [
                'channel' => (string) ($route['channel'] ?? ''),
                'method' => (string) ($route['method'] ?? ''),
                'route' => (string) ($route['route'] ?? ''),
                'hits' => $hits,
                'errors' => $errors,
                'client_errors' => (int) ($route['client_errors'] ?? 0),
                'success_rate' => $hits > 0 ? round(100 * ($hits - $errors) / $hits, 3) : null,
                'client_error_rate' => $route['client_error_rate'] ?? null,
                'p95' => $route['p95'] ?? null,
            ];
        }

        $hits = (int) $summary['hits'];
        $errors = (int) $summary['errors'];

        return array_merge($base, [
            'state' => $rows === [] ? 'no_data' : 'ok',
            'note' => $rows === [] ? 'Traffic was recorded in this window but no route survived the per-route fold.' : null,
            'remedy' => null,
            'rows' => $rows,
            'totals' => [
                'hits' => $hits,
                'errors' => $errors,
                'client_errors' => (int) $summary['client_errors'],
                'success_rate' => $hits > 0 ? round(100 * ($hits - $errors) / $hits, 3) : null,
                'client_error_rate' => $summary['client_error_rate'],
            ],
            'truncated' => $truncated,
        ]);
    }

    /**
     * Per-dependency success rate, from the calls this application made outward.
     *
     * The third measurement on the page and, again, not the same thing as the other two: a payment
     * gateway failing one call in twenty is invisible to a liveness probe and invisible in the shop's
     * own error rate until a customer complains.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function dependencies(string $range, array $window): array
    {
        $base = [
            'source' => self::DEPENDENCIES_SOURCE,
            'resolution' => $window['resolution'],
            'rows' => [],
            'totals' => ['services' => null, 'calls' => null, 'failures' => null, 'success_rate' => null],
            'truncated' => false,
            'limit' => self::MAX_SERVICES,
        ];

        try {
            $connection = $this->reader->connection();
            $from = $this->reader->since($range);
            $seam = $window['resolution'] === 'minute' ? null : $this->dependencySeam($window['resolution'], $from);

            $columns = [
                'service',
                $connection->raw('SUM(calls) AS calls'),
                $connection->raw('SUM(failures) AS failures'),
                $connection->raw('SUM(timeouts) AS timeouts'),
                $connection->raw('SUM(server_errors) AS server_errors'),
                $connection->raw('SUM(client_errors) AS client_errors'),
                $connection->raw('SUM(duration_sum_ms) AS duration_sum_ms'),
                $connection->raw('MAX(last_success_at) AS last_success_at'),
                $connection->raw('MAX(last_failure_at) AS last_failure_at'),
            ];

            $rows = $connection->table('monitoring_dependency_buckets')
                ->where('resolution', $window['resolution'])
                ->where('bucket_at', '>=', $from)
                ->when($seam !== null, fn ($query) => $query->where('bucket_at', '<', $seam))
                ->groupBy('service')
                ->limit(self::MAX_SERVICES + 1)
                ->get($columns);

            if ($seam !== null) {
                $rows = $rows->concat($connection->table('monitoring_dependency_buckets')
                    ->where('resolution', 'minute')
                    ->where('bucket_at', '>=', $seam->max($from))
                    ->groupBy('service')
                    ->limit(self::MAX_SERVICES + 1)
                    ->get($columns));
            }
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
            ]);
        }

        $counters = [];
        foreach ($rows as $row) {
            $service = (string) $row->service;
            $counters[$service] ??= [
                'calls' => 0, 'failures' => 0, 'timeouts' => 0, 'server_errors' => 0,
                'client_errors' => 0, 'duration_sum_ms' => 0, 'last_success_at' => null, 'last_failure_at' => null,
            ];
            $counters[$service]['calls'] += (int) $row->calls;
            $counters[$service]['failures'] += (int) $row->failures;
            $counters[$service]['timeouts'] += (int) $row->timeouts;
            $counters[$service]['server_errors'] += (int) $row->server_errors;
            $counters[$service]['client_errors'] += (int) $row->client_errors;
            $counters[$service]['duration_sum_ms'] += (int) $row->duration_sum_ms;
            $counters[$service]['last_success_at'] = $this->later($counters[$service]['last_success_at'], $row->last_success_at);
            $counters[$service]['last_failure_at'] = $this->later($counters[$service]['last_failure_at'], $row->last_failure_at);
        }

        if ($counters === []) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'No outbound call to a third party was recorded in this window, so no dependency has a success rate here. Nothing in this reading says the integrations are healthy — it says none of them was used.',
                'remedy' => 'Outbound calls are recorded where the client is instrumented. Exercise a payment, SMS or push integration, or widen the range.',
            ]);
        }

        $truncated = count($counters) > self::MAX_SERVICES;
        $counters = array_slice($counters, 0, self::MAX_SERVICES, preserve_keys: true);

        $out = [];
        $totalCalls = 0;
        $totalFailures = 0;
        foreach ($counters as $service => $counter) {
            $calls = $counter['calls'];
            $failures = $counter['failures'];
            $totalCalls += $calls;
            $totalFailures += $failures;

            $out[] = [
                'service' => $service,
                'calls' => $calls,
                'failures' => $failures,
                'timeouts' => $counter['timeouts'],
                'server_errors' => $counter['server_errors'],
                'client_errors' => $counter['client_errors'],
                'success_rate' => $calls > 0 ? round(100 * ($calls - $failures) / $calls, 3) : null,
                'avg_ms' => $calls > 0 ? round($counter['duration_sum_ms'] / $calls, 1) : null,
                'last_success_at' => $this->displayStamp($counter['last_success_at']),
                'last_failure_at' => $this->displayStamp($counter['last_failure_at']),
            ];
        }

        usort($out, static fn (array $a, array $b) => [$a['success_rate'] ?? 101, $a['service']]
            <=> [$b['success_rate'] ?? 101, $b['service']]);

        return array_merge($base, [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'rows' => $out,
            'totals' => [
                'services' => count($out),
                'calls' => $totalCalls,
                'failures' => $totalFailures,
                'success_rate' => $totalCalls > 0 ? round(100 * ($totalCalls - $totalFailures) / $totalCalls, 3) : null,
            ],
            'truncated' => $truncated,
        ]);
    }

    /** The same seam as the series read, on the dependency buckets. */
    private function dependencySeam(string $resolution, Carbon $from): Carbon
    {
        $newestFolded = $this->reader->connection()->table('monitoring_dependency_buckets')
            ->where('resolution', $resolution)
            ->max('bucket_at');

        return $newestFolded !== null ? Clock::parse($newestFolded) : $from;
    }

    // -------------------------------------------------------------------------------------------

    /** A journey key, as SyntheticCheck writes it: `synthetic` alone, or `synthetic:<slug>`. */
    private function isJourney(string $label): bool
    {
        return $label === self::SYNTHETIC_PREFIX || str_starts_with($label, self::SYNTHETIC_PREFIX . ':');
    }

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the probe really ran,
            // and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    /** Seconds between two stored stamps, or null when either is missing or unreadable. */
    private function elapsedSeconds(mixed $from, mixed $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            return round((float) Clock::parse($to)->getTimestamp() - (float) Clock::parse($from)->getTimestamp(), 1);
        } catch (\Throwable) {
            return null;
        }
    }

    private function earlier(?string $current, mixed $candidate): ?string
    {
        $candidate = is_scalar($candidate) ? (string) $candidate : null;

        if ($candidate === null || $candidate === '') {
            return $current;
        }

        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private function later(?string $current, mixed $candidate): ?string
    {
        $candidate = is_scalar($candidate) ? (string) $candidate : null;

        if ($candidate === null || $candidate === '') {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    /**
     * One line of stored free text, redacted and bounded.
     *
     * A check detail and an incident title are written by this system, but they quote what they
     * found — a URL, a driver message — and those are among the most reliable places in an
     * application to find a token or a customer's address.
     */
    private function cleanText(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $line = trim(strtok(trim($value), "\n") ?: '');

        return $line === '' ? null : mb_substr($this->redactor->text($line), 0, 191);
    }
}
