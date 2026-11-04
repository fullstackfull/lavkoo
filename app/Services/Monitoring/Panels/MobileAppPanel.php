<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Ingest\AppHealthRecorder;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\ReadsFoldedSeries;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One shop's mobile app, from the only two places anything can be known about it.
 *
 * THE SERVER SEES CONVERSATION, NOT EXPERIENCE. Every request the app makes is measured here
 * already — how much it talks, how fast it is answered, which versions are in the wild — and none
 * of that needs the app to cooperate, because the shop is the other end of the conversation. It is
 * also everything the server can ever know. An app that crashed on launch sends nothing, and
 * "sends nothing" is indistinguishable from a quiet Tuesday.
 *
 * SO STABILITY IS SELF-REPORTED, AND SAYS SO. Sessions, crashes and ANRs arrive from the app
 * itself, at POST /api/v1/app-health. The card carries that provenance rather than presenting a
 * crash-free percentage as measured — a 100% crash-free figure derived from an app that never
 * reported anything is a lie with a reassuring shape. When nothing has been reported the card is
 * not_configured with the exact call to make, not a zero.
 *
 * THE VERSION TABLE IS WHY THE PAGE EXISTS. Traffic and crashes are both broken down by the
 * version that produced them, side by side, because the useful sentence is almost never "the app
 * is crashing" — it is "4.1.9 is crashing and 4.2.1 is not", which is the difference between an
 * outage and a rollout to finish. The two halves come from different sources and the table says
 * which cells came from where.
 *
 * COUNTS ARE READ FROM `samples`, NEVER FROM `value_sum`. A counter row holds how many requests it
 * saw in samples and whatever the writer chose to total in value_sum — milliseconds, for every
 * series on this page. The chart under the word "requests" once drew value_sum and put 11,788 on a
 * minute that had 62, so every read here counts the same way and the mean is the only figure
 * derived from the sum.
 *
 * Android and iOS are the same page over a different platform, so they are the same class over a
 * different platform(). The subclasses carry only what is genuinely not shared: the platform
 * string, the user-agent fallback, and the copy explaining what "Android" and "iOS" each mean,
 * which is a different and non-interchangeable caveat on the two platforms. When this was two
 * files, the caption an empty version table shows after a FAILED read was fixed on one of them and
 * the other went on telling operators to start sending a header they were already sending.
 */
abstract class MobileAppPanel implements Panel
{
    use ReadsFoldedSeries;

    /** Traffic, measured server-side. `n` is requests, `sum` is total milliseconds. */
    private const TRAFFIC = 'requests.by_platform';

    private const TRAFFIC_ERRORS = 'requests.by_platform.errors';

    private const TRAFFIC_CLIENT_ERRORS = 'requests.by_platform.client_errors';

    /** The same, split by the version the app announced in X-App-Version. */
    private const VERSION_TRAFFIC = 'requests.by_app_version';

    private const VERSION_ERRORS = 'requests.by_app_version.errors';

    /** The label BucketWriter folds the long tail of client-supplied labels into. */
    private const FOLDED_LABEL = '__other__';

    /** Versions listed before the rest are summarised as a remainder. */
    private const MAX_VERSIONS = 12;

    /**
     * Buckets the chart draws, newest first, before the tail is dropped.
     *
     * Two metrics per bucket, so the row cap is twice the point cap. Read newest-first and sorted
     * back for display: ordering oldest-first and truncating would drop the newest buckets, which
     * is the half of the chart anybody opened it for.
     */
    private const MAX_TIMELINE_POINTS = 200;

    private const TRAFFIC_SOURCE = 'monitoring_series (requests.by_platform*, requests.by_app_version*)';

    private const VERSION_SOURCE = 'monitoring_series (requests.by_app_version)';

    private const HEALTH_SOURCE = 'monitoring_series (app.health.*)';

    protected const TIMELINE_SOURCE = 'monitoring_series (requests.by_platform, requests.by_platform.errors)';

    protected const MIDDLEWARE = 'app/Http/Middleware/MonitorRequest.php';

    protected const RECORDER = 'app/Services/Monitoring/Ingest/RequestRecorder.php';

    protected const HEALTH_RECORDER = 'app/Services/Monitoring/Ingest/AppHealthRecorder.php';

    private const HEALTH_INGEST = 'app/Http/Controllers/RestAPI/v1/AppHealthController.php';

    private const FOLD_WRITER = 'app/Services/Monitoring/Ingest/BucketWriter.php';

    /** What an empty section standing on a read that threw tells the operator to check. */
    private const READ_FAILURE_REMEDY = 'The series table is created by `php artisan migrate`. Check the monitoring connection is reachable and migrated.';

    /** The one thing that stops the fold, named wherever a fold is reported. */
    private const LABEL_CEILING_REMEDY = 'Raise `monitoring.max_labels_per_client_series` (40 by default) past the number of platform:version labels reporting in one minute — '
        . self::FOLD_WRITER . '::capClientLabelledSeries() folds everything beyond it into one row that carries no platform, and no read can undo a fold.';

    public function __construct(protected readonly SeriesReader $reader)
    {
    }

    /** Which app this page is about. The only difference between the two sections. */
    abstract protected function platform(): string;

    /**
     * The header the app must send for its traffic to be attributed here at all.
     *
     * Named on the page rather than assumed: a shop whose app sends no X-Platform is classified
     * from its user agent, which works for okhttp and CFNetwork and not for a custom one — so an
     * empty page has two possible causes and the operator needs to be able to tell them apart.
     */
    abstract protected function userAgentHint(): string;

    /**
     * What the middleware counts as this platform, transcribed branch by branch.
     *
     * Left to the subclasses because the two rules are not the same rule with a word swapped: the
     * Android guess catches every browser on an Android handset, and the iOS guess catches Mac
     * clients while missing an iPad in desktop mode. A shared sentence would be wrong on both.
     *
     * @return array<string, mixed>
     */
    abstract protected function identification(): array;

    /**
     * The questions this section is opened for that this deployment cannot answer.
     *
     * Also per-platform: the remedies differ in kind, not in wording — MetricKit hands iOS a
     * launch histogram and symbolicated crashes it only has to forward, and ANR is an Android
     * mechanism that iOS does not have at all.
     *
     * @return array<string, mixed>
     */
    abstract protected function notMeasured(): array;

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $traffic = $this->traffic($range, $window);
        $health = $this->health($range, $window);

        return [
            'platform' => $this->platform(),
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'identification' => $this->identification(),
            'traffic' => $traffic,
            'timeline' => $this->timeline($range, $window),
            'stability' => $health,
            'versions' => $this->versions($traffic, $health),
            'not_measured' => $this->notMeasured(),
            'reporting' => $this->reporting(),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the server measured

    /**
     * Requests, latency and error rates for this platform, and the same split by app version.
     *
     * One read for all five series rather than five reads: they share a resolution, a window and an
     * index, and the split by metric costs nothing in PHP.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function traffic(string $range, array $window): array
    {
        $prefix = $this->platform() . ':';

        try {
            $read = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $this->reader
                ->connection()
                ->table('monitoring_series')
                ->selectRaw('metric, label, SUM(samples) AS readings, SUM(value_sum) AS total')
                ->whereIn('metric', [
                    self::TRAFFIC, self::TRAFFIC_ERRORS, self::TRAFFIC_CLIENT_ERRORS,
                    self::VERSION_TRAFFIC, self::VERSION_ERRORS,
                ])
                ->where(fn ($query) => $query
                    ->where('label', $this->platform())
                    ->orWhere('label', 'like', $prefix . '%')
                    ->orWhere('label', self::FOLDED_LABEL))
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('metric', 'label'));
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => self::READ_FAILURE_REMEDY,
                'source' => self::TRAFFIC_SOURCE,
                'metrics' => [],
                'versions' => [],
                'folded' => 0,
            ];
        }

        $totals = ['requests' => 0, 'duration_ms' => 0.0, 'errors' => 0, 'client_errors' => 0];
        $versions = [];
        $folded = 0;

        foreach ($read['rows'] as $row) {
            $readings = (int) $row->readings;

            match ($row->metric) {
                self::TRAFFIC => $this->addTraffic($totals, $readings, (float) $row->total),
                self::TRAFFIC_ERRORS => $totals['errors'] += $readings,
                self::TRAFFIC_CLIENT_ERRORS => $totals['client_errors'] += $readings,
                default => null,
            };

            if ($row->metric !== self::VERSION_TRAFFIC && $row->metric !== self::VERSION_ERRORS) {
                continue;
            }

            // The folded label is not this platform's — BucketWriter folds every client-labelled
            // series into one `__other__` row, so it may hold another platform's tail. Counted as
            // a remainder rather than attributed to a version that would then be wrong.
            if ($row->label === self::FOLDED_LABEL) {
                $folded += $row->metric === self::VERSION_TRAFFIC ? $readings : 0;
                continue;
            }

            $version = substr($row->label, strlen($prefix));
            $versions[$version] ??= ['version' => $version, 'requests' => 0, 'duration_ms' => 0.0, 'errors' => 0];

            if ($row->metric === self::VERSION_TRAFFIC) {
                $versions[$version]['requests'] += $readings;
                $versions[$version]['duration_ms'] += (float) $row->total;
            } else {
                $versions[$version]['errors'] += $readings;
            }
        }

        return [
            'state' => $totals['requests'] > 0 ? 'ok' : 'no_data',
            'note' => $totals['requests'] > 0
                ? null
                : 'This app sent no request in this window. That is a statement about traffic, not about quality — an app nobody opened and an app that cannot reach the shop look identical from here.',
            'remedy' => $totals['requests'] > 0
                ? null
                : 'Traffic is attributed by the X-Platform header, falling back to the user agent (' . $this->userAgentHint() . '). An app sending neither is counted as web.',
            'source' => self::TRAFFIC_SOURCE,
            'metrics' => $this->trafficMetrics($totals, $versions),
            'totals' => $totals,
            'versions' => $versions,
            'folded' => $folded,
        ];
    }

    /**
     * @param  array<string, float|int>  $totals
     */
    private function addTraffic(array &$totals, int $readings, float $duration): void
    {
        $totals['requests'] += $readings;
        $totals['duration_ms'] += $duration;
    }

    /**
     * @param  array<string, float|int>  $totals
     * @param  array<string, array<string, float|int|string>>  $versions
     * @return array<string, Metric>
     */
    private function trafficMetrics(array $totals, array $versions): array
    {
        $requests = (int) $totals['requests'];

        if ($requests === 0) {
            // Every figure below is a ratio of this, so none of them exists yet. Drawn as no_data
            // rather than as the zeroes the arithmetic would produce.
            return [
                'requests_from_this_app' => Metric::noData(self::TRAFFIC_SOURCE),
                'mean_response_time' => Metric::noData(self::TRAFFIC_SOURCE),
                'server_error_rate' => Metric::noData(self::TRAFFIC_SOURCE),
                'client_error_rate' => Metric::noData(self::TRAFFIC_SOURCE),
                'app_versions_seen' => Metric::noData(self::TRAFFIC_SOURCE),
            ];
        }

        return [
            'requests_from_this_app' => Metric::of($requests, self::TRAFFIC_SOURCE, 'requests'),
            'mean_response_time' => Metric::of(
                round($totals['duration_ms'] / $requests, 1),
                self::TRAFFIC_SOURCE,
                'ms',
                'The mean, not a percentile: the per-minute buckets hold a sum and a count, so a p95 cannot be derived from them.',
            ),
            'server_error_rate' => Metric::of(round(100 * $totals['errors'] / $requests, 2), self::TRAFFIC_SOURCE, '%'),
            'client_error_rate' => Metric::of(
                round(100 * $totals['client_errors'] / $requests, 2),
                self::TRAFFIC_SOURCE,
                '%',
                'A 4xx is usually the app asking for something that is gone or sending an expired token, not the server failing.',
            ),
            // Published beside the traffic it splits rather than only when there is none, which
            // took the card away at the exact moment it had something to count.
            'app_versions_seen' => Metric::of(
                value: count($versions),
                source: self::VERSION_SOURCE,
                unit: null,
                note: $versions === []
                    ? 'This app sent traffic but no request carried an X-App-Version header, so none of it can be attributed to a release.'
                    : null,
            ),
        ];
    }

    /**
     * Requests and 5xx responses from this app, one point per bucket.
     *
     * Read straight from the two counters rather than through SeriesReader::series(), which returns
     * `value_sum` for a counter — correct for a summed gauge, and the millisecond total rather than
     * the request count for this one.
     *
     * Bounded three ways: the window, the indexed bucket_at it is bounded on, and a hard row cap.
     * Rides monitoring_series_lookup (metric, resolution, bucket_at); the label is filtered after
     * the index has already narrowed the read to two metrics inside one window.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $window): array
    {
        $resolution = $window['resolution'];
        $rowLimit = self::MAX_TIMELINE_POINTS * 2;

        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->whereIn('metric', [self::TRAFFIC, self::TRAFFIC_ERRORS])
                ->where('label', $this->platform())
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $this->reader->since($range))
                // Newest first, and sorted back into time order below. Ordering oldest-first and
                // cutting at the limit would drop the most recent buckets, which is the end of the
                // chart anybody opened it for.
                ->orderByDesc('bucket_at')
                ->limit($rowLimit + 1)
                ->get(['bucket_at', 'metric', 'samples', 'value_sum']);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing the chart must not blank the
            // traffic, version and stability cards, which were read perfectly well.
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => self::READ_FAILURE_REMEDY,
                'source' => self::TIMELINE_SOURCE,
                'resolution' => $resolution,
                'points' => [],
                'truncated' => false,
                'limit' => self::MAX_TIMELINE_POINTS,
            ];
        }

        $truncated = $rows->count() > $rowLimit;
        $buckets = [];

        foreach ($rows->take($rowLimit) as $row) {
            $at = $this->isoStamp($row->bucket_at);
            if ($at === null) {
                continue;
            }

            // hits starts null, not 0: a bucket holding only an error row would otherwise claim a
            // measured zero requests beside a non-zero error count.
            $buckets[$at] ??= ['t' => $at, 'hits' => null, 'errors' => 0, 'avg_ms' => null];

            $samples = (int) $row->samples;

            if ($row->metric === self::TRAFFIC) {
                $buckets[$at]['hits'] = $samples;
                $buckets[$at]['avg_ms'] = $samples > 0 ? round((float) $row->value_sum / $samples, 1) : null;

                continue;
            }

            $buckets[$at]['errors'] = $samples;
        }

        // Keyed by the ISO stamp, which Clock always renders in UTC — so one lexical sort is a
        // chronological one, and two rows for the same bucket meet in the same point.
        ksort($buckets);

        $orphans = count(array_filter($buckets, static fn (array $point) => $point['hits'] === null));
        $points = array_values(array_filter($buckets, static fn (array $point) => $point['hits'] !== null));

        return [
            // A single reading is not a line, and the chart renderer needs two points to draw one.
            'state' => count($points) >= 2 ? 'ok' : 'no_data',
            'note' => $this->timelineNote($points, $resolution, $orphans),
            // The counts above this chart are read across the fold seam and this chart is not, so
            // the one thing an empty chart must never imply is that the counts are wrong.
            'remedy' => count($points) >= 2 || $resolution === 'minute'
                ? null
                : 'Choose a shorter range to read the minute samples directly, or check that the rollup is running: `php artisan schedule:list`.',
            'source' => self::TIMELINE_SOURCE,
            'resolution' => $resolution,
            'points' => $points,
            'truncated' => $truncated,
            'limit' => self::MAX_TIMELINE_POINTS,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    private function timelineNote(array $points, string $resolution, int $orphans): ?string
    {
        $note = match (count($points)) {
            0 => 'No ' . $resolution . ' bucket in this window holds a request from this app, so there is no line to draw.',
            1 => 'Only one ' . $resolution . ' bucket in this window holds a request from this app. One reading is a value, not a trend.',
            default => $resolution === 'minute'
                ? null
                : 'The newest ' . $resolution . ' bucket is still filling, so its point covers a part of that ' . $resolution . ' rather than all of it.',
        };

        // An empty chart on a coarse range is regularly not an empty window. Requests are counted
        // into minute rows on the hot path and folded into hour and day parents by the rollup, so
        // this read can find nothing while the minute rows under it are full — and the counts above
        // the chart are read across both, which is why they can disagree with it in exactly this
        // one direction. Left unsaid, the empty chart reads as a contradiction of the cards.
        if (count($points) < 2 && $resolution !== 'minute') {
            $note .= ' This range draws ' . $resolution . ' rows, which the monitoring rollup builds from the minute'
                . ' samples the request path writes directly — so it can be empty while the counts above it, which are'
                . ' read from both, are not.';
        }

        if ($orphans > 0) {
            // Never silently dropped: a bucket with an error count and no request count means the
            // two counters disagree, which is a finding about the writer rather than about the app.
            $note = trim(($note ?? '') . ' ' . $orphans . ' bucket(s) recorded a failed response with no matching request count and are not drawn.');
        }

        return $note === '' ? null : $note;
    }

    private function isoStamp(mixed $stored): ?string
    {
        try {
            return Clock::parse($stored)->toIso8601String();
        } catch (\Throwable) {
            // A bucket whose stamp cannot be parsed has no place on a time axis, and guessing one
            // would put a real measurement at an invented moment.
            return null;
        }
    }

    // -------------------------------------------------------------------------------------------
    // What the app reported about itself

    /**
     * Sessions, crashes and ANRs as the app counted them.
     *
     * The folded remainder is read as well as this platform's own labels, because it is where the
     * label ceiling puts the tail of every client-labelled series — and a denominator that quietly
     * loses part of itself produces a crash-free percentage that is wrong in the reassuring
     * direction. It carries no platform, so it is counted apart and never added here: the shop
     * cannot tell which of the two apps those sessions came from, and inventing a split would put
     * the other section's crashes on this one.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function health(string $range, array $window): array
    {
        $prefix = $this->platform() . ':';

        try {
            $read = $this->acrossSeam($range, $window, fn (string $resolution, Carbon $from, ?Carbon $until) => $this->reader
                ->connection()
                ->table('monitoring_series')
                ->selectRaw('metric, label, SUM(samples) AS readings')
                ->where('metric', 'like', AppHealthRecorder::SERIES . '%')
                ->where(fn ($query) => $query
                    ->where('label', 'like', $prefix . '%')
                    ->orWhere('label', self::FOLDED_LABEL))
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('bucket_at', '<', $until))
                ->groupBy('metric', 'label'));
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => self::READ_FAILURE_REMEDY,
                'source' => self::HEALTH_SOURCE,
                'metrics' => [],
                'versions' => [],
                'folded' => array_fill_keys(AppHealthRecorder::KINDS, 0),
                'reported' => false,
            ];
        }

        $totals = array_fill_keys(AppHealthRecorder::KINDS, 0);
        $folded = array_fill_keys(AppHealthRecorder::KINDS, 0);
        $versions = [];

        foreach ($read['rows'] as $row) {
            $kind = substr($row->metric, strlen(AppHealthRecorder::SERIES));

            if (!in_array($kind, AppHealthRecorder::KINDS, true)) {
                continue;
            }

            if ($row->label === self::FOLDED_LABEL) {
                $folded[$kind] += (int) $row->readings;
                continue;
            }

            $version = substr($row->label, strlen($prefix));
            $versions[$version] ??= array_fill_keys(AppHealthRecorder::KINDS, 0);
            $versions[$version][$kind] += (int) $row->readings;
            $totals[$kind] += (int) $row->readings;
        }

        $sessions = $totals['sessions'];
        $shortfall = $this->foldedRemainder($folded);

        if ($sessions === 0) {
            $report = 'Have the app POST to /api/v1/app-health on launch: {"platform":"' . $this->platform() . '","app_version":"…","sessions":1,"crashes":0}. '
                . 'Counters only — no stack traces, device identifiers or user ids are accepted. It needs no token and always answers 204.';

            return [
                'state' => 'not_configured',
                'note' => $shortfall === null
                    ? 'This app has not reported a single session, so nothing here can say whether it stayed running. A crash sends no request — it is the absence of traffic — so the server cannot infer this one.'
                    : 'No session in this window carries this platform\'s label. ' . $shortfall
                        . ' So this app may have reported and been folded out of reach, or may never have reported at all — different facts, and this window cannot tell them apart.',
                'remedy' => $shortfall === null ? $report : $report . ' ' . self::LABEL_CEILING_REMEDY,
                'source' => self::HEALTH_SOURCE,
                'metrics' => [],
                'versions' => $versions,
                'folded' => $folded,
                'reported' => false,
            ];
        }

        // Either end of the share was folded, so the share itself cannot be stated — only the two
        // counters it would have been computed from, each as the floor it now is.
        $shareIsPartial = $folded['sessions'] > 0 || $folded['crashes'] > 0;

        return [
            'state' => 'ok',
            'note' => $shortfall === null
                ? 'Reported by the app itself, not measured here.'
                : 'Reported by the app itself, not measured here. ' . $shortfall,
            'remedy' => $shortfall === null ? null : self::LABEL_CEILING_REMEDY,
            'source' => self::HEALTH_SOURCE,
            'metrics' => [
                // A share of a denominator known to be short is worse than no share at all: it
                // reads as measured, it is wrong in the flattering direction, and nothing on the
                // card would say so.
                'crash_free_sessions' => $shareIsPartial
                    ? Metric::notConfigured(
                        source: self::HEALTH_SOURCE,
                        remedy: self::LABEL_CEILING_REMEDY,
                        note: 'Not drawn: ' . $shortfall . ' A percentage over the sessions that survived the fold would read as a percentage over all of them.',
                    )
                    : Metric::of(round(100 * max(0, $sessions - $totals['crashes']) / $sessions, 2), self::HEALTH_SOURCE, '%'),
                'sessions_reported' => Metric::of($sessions, self::HEALTH_SOURCE, 'sessions', $shortfall),
                'crashes_reported' => Metric::of($totals['crashes'], self::HEALTH_SOURCE, 'crashes', $shortfall),
                'app_not_responding' => Metric::of($totals['anrs'], self::HEALTH_SOURCE, 'events', $shortfall),
            ],
            'versions' => $versions,
            'totals' => $totals,
            'folded' => $folded,
            'reported' => true,
        ];
    }

    /**
     * What the label ceiling took out of this platform's counters, as the card says it.
     *
     * Null when nothing was folded, so every caller decides whether it has a shortfall to declare
     * from the sentence it would print rather than from a flag that could disagree with it. All
     * three counters are named even at zero: they were read, and a zero here is a measurement.
     *
     * @param  array<string, int>  $folded
     */
    private function foldedRemainder(array $folded): ?string
    {
        if (array_sum($folded) === 0) {
            return null;
        }

        return $folded['sessions'] . ' session(s), ' . $folded['crashes'] . ' crash(es) and ' . $folded['anrs']
            . ' ANR report(s) in this window were folded into the shared `' . self::FOLDED_LABEL
            . '` remainder, which carries no platform and may hold the other app\'s tail as well as this one\'s.';
    }

    // -------------------------------------------------------------------------------------------
    // The two halves, laid against each other

    /**
     * One row per version: what it sent, and what it reported about itself.
     *
     * A version can appear on one side and not the other — traffic with no health report is an app
     * that has not been updated to send them; a health report with no traffic is an app that
     * crashed before its first call, which is the most interesting row on the page. Both are kept,
     * with the missing half left null rather than filled with a zero that would read as a measured
     * "no crashes".
     *
     * The table takes its state from whether it has rows, which is the truth when the reads behind
     * it succeeded and a lie when they did not: two failed reads produce an empty table captioned
     * "no app version has identified itself", under a remedy telling the operator to start sending
     * a header. That is a measurement nobody took with an instruction attached, so an empty table
     * standing on a failed read says which read failed instead.
     *
     * @param  array<string, mixed>  $traffic
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    private function versions(array $traffic, array $health): array
    {
        $names = array_unique(array_merge(
            array_keys($traffic['versions'] ?? []),
            array_keys($health['versions'] ?? []),
        ));

        $rows = [];

        foreach ($names as $version) {
            $seen = $traffic['versions'][$version] ?? null;
            $reported = $health['versions'][$version] ?? null;
            $sessions = $reported['sessions'] ?? 0;

            $rows[] = [
                'version' => $version,
                'requests' => $seen['requests'] ?? null,
                'mean_ms' => ($seen['requests'] ?? 0) > 0 ? round($seen['duration_ms'] / $seen['requests'], 1) : null,
                'error_rate' => ($seen['requests'] ?? 0) > 0 ? round(100 * $seen['errors'] / $seen['requests'], 2) : null,
                'sessions' => $reported === null ? null : $sessions,
                'crashes' => $reported === null ? null : $reported['crashes'],
                'crash_free' => $sessions > 0 ? round(100 * max(0, $sessions - $reported['crashes']) / $sessions, 2) : null,
            ];
        }

        // Busiest first, and a version that only reported crashes still sorts by what it did report
        // rather than sinking below every version with a single request.
        usort($rows, static fn (array $a, array $b) => (($b['requests'] ?? 0) + ($b['sessions'] ?? 0))
            <=> (($a['requests'] ?? 0) + ($a['sessions'] ?? 0)));

        $failure = match (true) {
            ($traffic['state'] ?? null) === 'failed' => $traffic['note'] ?? null,
            ($health['state'] ?? null) === 'failed' => $health['note'] ?? null,
            default => null,
        };

        [$state, $note, $remedy] = match (true) {
            $rows !== [] => ['ok', null, null],
            $failure !== null => [
                'failed',
                'This table is empty because the reads behind it failed, not because no release reported: ' . $failure,
                self::READ_FAILURE_REMEDY,
            ],
            default => [
                'no_data',
                'No app version has identified itself in this window.',
                'Send X-App-Version on every request (any string of up to 32 characters: 4.2.1, 4.2.1-rc3). Without it traffic is still counted for the platform, it just cannot be attributed to a release.',
            ],
        };

        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => self::TRAFFIC_SOURCE . ' + ' . self::HEALTH_SOURCE,
            'rows' => array_slice($rows, 0, self::MAX_VERSIONS),
            'truncated' => count($rows) > self::MAX_VERSIONS,
            'limit' => self::MAX_VERSIONS,
            // Requests whose version was folded away by the client-label cap. Stated rather than
            // dropped: a table whose rows do not add up to the headline has to say why.
            'folded_requests' => $traffic['folded'] ?? 0,
        ];
    }

    /**
     * The contract, on the page.
     *
     * Everything above is either present or absent because of these three things, and an operator
     * looking at an empty section needs them without leaving it for the developer portal.
     *
     * @return array<string, mixed>
     */
    private function reporting(): array
    {
        return [
            'platform_header' => 'X-Platform: ' . $this->platform(),
            'version_header' => 'X-App-Version: 4.2.1',
            'user_agent_fallback' => $this->userAgentHint(),
            'health_endpoint' => 'POST ' . rtrim((string) config('app.url'), '/') . '/api/v1/app-health',
            'health_body' => '{"platform":"' . $this->platform() . '","app_version":"4.2.1","sessions":1,"crashes":0,"anrs":0}',
            'measured_by' => self::MIDDLEWARE,
            'reported_to' => self::HEALTH_INGEST,
        ];
    }
}
