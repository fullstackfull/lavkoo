<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Live traffic: who is on the shop in the last few minutes, and what they are hitting.
 *
 * This section reads two recorders that measure the same shop and do not agree, on purpose.
 * monitoring_request_buckets is folded per minute and written by a flush that runs from cron, so
 * it carries percentiles and route detail but is always at least a minute behind — and stops dead
 * when the scheduler does. telemetry_requests is written inline with each response, so it is
 * current to the second but has no histogram behind it. A live page that showed only the first
 * would go blank during exactly the incident it exists for, so both are read, both are labelled,
 * and where they disagree the page says why rather than picking a winner.
 *
 * The other thing this section must not do is imply "right now". Every figure here is an average
 * over a window of minutes; the window is in the payload so the view can name it beside the
 * numbers instead of letting the section title make a promise the arithmetic cannot keep.
 */
class LiveTrafficPanel implements Panel
{
    /** The shortest window the range picker offers: five minutes. */
    private const LIVE_RANGE = 'live';

    /**
     * The widest window this section will read.
     *
     * Longer ranges are not refused — they are narrowed, and the payload says so. "Live" over six
     * hours is not a live reading, and silently answering one would be the same lie as printing a
     * six-hour average under a heading that says right now.
     */
    private const WIDEST_RANGE = '15m';

    /** Routes in the busiest table. Enough to see the shape of a spike, short enough to read. */
    private const ROUTE_ROWS = 12;

    /** Distinct status codes read back per window. Bounded: a shop that returns more than this
     *  many different codes in five minutes has a bigger problem than a truncated table. */
    private const STATUS_ROWS = 40;

    /** Individual codes listed beside the four-class bar, most frequent first. */
    private const TOP_STATUS_ROWS = 8;

    /** Labels in a split. Platform is three values and device is five; the cap is the guard. */
    private const SPLIT_ROWS = 8;

    private const BUCKETS = 'monitoring_request_buckets';

    private const RECORDER = 'telemetry_requests';

    private const SESSIONS = 'visit_sessions';

    public function __construct(private readonly SeriesReader $reader)
    {
    }

    public function data(string $range, Request $request): array
    {
        $effective = $this->effectiveRange($range);
        $window = $this->reader->window($effective);
        $minutes = $window['minutes'];
        $collection = $this->collectionState();
        $recorded = $this->recordedRequests($minutes);

        return [
            'window' => $this->window($range, $effective, $window),
            'collection' => $collection,
            'rate' => $this->rate($effective, $collection),
            'traffic' => $recorded['traffic'],
            'statuses' => $recorded['statuses'],
            'visitors' => $this->visitors($minutes),
            'routes' => $this->busiestRoutes($effective, $collection),
            'platforms' => $this->platformSplit($effective, $window['resolution']),
            'devices' => $this->deviceSplit($minutes),
            'browsers' => $this->browsers(),
            'country' => $this->country(),
            'timeline' => $this->timeline($effective, $collection),
        ];
    }

    /**
     * The window this section will actually read, whatever the header asked for.
     */
    private function effectiveRange(string $range): string
    {
        return $range === self::LIVE_RANGE ? self::LIVE_RANGE : self::WIDEST_RANGE;
    }

    /**
     * What the numbers below cover, in the operator's own timezone.
     *
     * @param  array{minutes: int, resolution: string, points: int}  $window
     * @return array<string, mixed>
     */
    private function window(string $range, string $effective, array $window): array
    {
        return [
            'requested_range' => $range,
            'range' => $effective,
            'minutes' => $window['minutes'],
            'resolution' => $window['resolution'],
            'since' => Clock::display($this->reader->since($effective))->toDateTimeString(),
            'until' => Clock::display(Clock::now())->toDateTimeString(),
            'timezone' => Clock::displayTimezone(),
            'clamped' => $range !== $effective,
            'note' => 'Every figure on this page is the average over the last ' . $window['minutes']
                . ' minutes, not an instantaneous reading.'
                . ($range !== $effective
                    ? ' The selected range was narrowed to ' . $window['minutes'] . ' minutes because this section only reads live windows; the Requests section answers longer ones.'
                    : ''),
        ];
    }

    /**
     * The same instant as Clock, written the way the shop's own tables wrote theirs.
     *
     * visit_sessions and telemetry_requests are stamped by TelemetryRecorder with Carbon::now(),
     * which resolves to the PHP process timezone — not to UTC, and on this deployment not even to
     * app.timezone. Monitoring's tables are UTC. Comparing a UTC boundary against a column written
     * in local wall time is precisely the failure Clock exists to prevent: here it is a three-hour
     * error, which on a five-minute window returns nothing at all while the shop is busy.
     *
     * So the instant still comes from Clock and only its presentation changes, once, here.
     * date_default_timezone_get() is not a clock read — it is the zone Carbon::now() resolves in,
     * which is the only honest answer to "how were these rows stamped".
     */
    private function shopWindowStart(int $minutes): Carbon
    {
        return Clock::minutesAgo($minutes)->setTimezone(date_default_timezone_get());
    }

    /**
     * Is the bucket writer still keeping up?
     *
     * Asked before anything else, because on a five-minute window a stopped flush and a quiet shop
     * produce the identical empty payload, and only one of them is an incident.
     *
     * @return array<string, mixed>
     */
    private function collectionState(): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no bucket has been written since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'newest_bucket_at' => null,
                'age_seconds' => null,
            ];
        }

        $newest = $this->reader->newestBucketAt(self::BUCKETS);

        if ($newest === null) {
            return [
                'state' => 'no_data',
                'note' => 'No request has ever been folded into a bucket on this deployment.',
                'remedy' => 'Requests are buffered per minute and written by `php artisan monitoring:flush`, scheduled every minute. Check the scheduler is running: `php artisan schedule:list`.',
                'newest_bucket_at' => null,
                'age_seconds' => null,
            ];
        }

        $age = (int) Clock::parse($newest)->diffInSeconds(Clock::now());
        $staleAfter = (int) config('monitoring.stale_after_seconds', 180);

        return [
            'state' => $age > $staleAfter ? 'stale' : 'ok',
            'note' => $age > $staleAfter
                ? 'The newest request bucket is ' . $age . ' seconds old. On a live window that is most of the window, so the bucket figures below describe a period that has already ended.'
                : null,
            'remedy' => $age > $staleAfter
                ? 'Check `php artisan monitoring:flush` is still running every minute from cron.'
                : null,
            'newest_bucket_at' => Clock::display($newest)->toDateTimeString(),
            'age_seconds' => $age,
        ];
    }

    /**
     * Arrival rate from the folded buckets, which is also where the percentiles live.
     *
     * @return array<string, mixed>
     */
    private function rate(string $range, array $collection): array
    {
        try {
            $summary = $this->reader->requestSummary($range);
        } catch (\Throwable $exception) {
            return array_merge($this->emptyRate(), ['state' => 'failed', 'note' => $this->failureNote($exception)]);
        }

        if (!$summary['has_data']) {
            return array_merge($this->emptyRate(), $this->emptyReason($collection));
        }

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'hits' => $summary['hits'],
            'per_second' => $summary['requests_per_second'],
            'per_minute' => $summary['requests_per_minute'],
            'errors' => $summary['errors'],
            'error_rate' => $summary['error_rate'],
            'p95' => $summary['p95'],
            'source' => self::BUCKETS,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRate(): array
    {
        return [
            'hits' => 0,
            'per_second' => null,
            'per_minute' => null,
            'errors' => 0,
            'error_rate' => null,
            'p95' => null,
            'source' => self::BUCKETS,
        ];
    }

    /**
     * The per-request log, read once for both the arrival rate and the status split.
     *
     * One grouped read rather than two: the two answers come out of the same rows, and a live page
     * that scans the same window twice is a page that costs more the busier the shop gets.
     *
     * @return array{traffic: array<string, mixed>, statuses: array<string, mixed>}
     */
    private function recordedRequests(int $minutes): array
    {
        if (!config('telemetry.enabled', true)) {
            return $this->unrecorded([
                'state' => 'not_configured',
                'note' => 'Per-request telemetry is switched off, so nothing has been written to ' . self::RECORDER . ' since it was disabled.',
                'remedy' => 'Set TELEMETRY_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ]);
        }

        try {
            $rows = DB::table(self::RECORDER)
                ->where('created_at', '>=', $this->shopWindowStart($minutes))
                ->groupBy('status')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::STATUS_ROWS)
                ->get(['status', DB::raw('COUNT(*) as hits')]);
        } catch (\Throwable $exception) {
            return $this->unrecorded(['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null]);
        }

        $byClass = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
        $top = [];
        $total = 0;

        foreach ($rows as $row) {
            $status = (int) $row->status;
            $hits = (int) $row->hits;
            $total += $hits;
            $class = $this->statusClass($status);
            // A 1xx cannot be shown in a four-class bar, and dropping it would break the sum. It
            // is not reachable through this recorder, but the guard costs nothing and a silently
            // wrong total is the one outcome this page must not produce.
            if (isset($byClass[$class])) {
                $byClass[$class] += $hits;
            }
            $top[] = ['status' => $status, 'hits' => $hits, 'class' => $class];
        }

        if ($total === 0) {
            return $this->unrecorded([
                'state' => 'no_data',
                'note' => 'No request reached the shop in this window. Nothing is broken — this is a reading of zero traffic.',
                'remedy' => null,
            ]);
        }

        $classes = [];
        foreach ($byClass as $class => $hits) {
            $classes[] = [
                'class' => $class,
                'hits' => $hits,
                'share_pct' => round(100 * $hits / $total, 1),
                'severity' => $this->statusSeverity($class),
            ];
        }

        // Already ordered by the query; the slice is the cap on how many are worth reading.
        $top = array_map(
            fn (array $row) => array_merge($row, [
                'share_pct' => round(100 * $row['hits'] / $total, 1),
                'severity' => $this->statusSeverity($row['class']),
            ]),
            array_slice($top, 0, self::TOP_STATUS_ROWS),
        );

        $errors = $byClass['5xx'];

        return [
            'traffic' => [
                'state' => 'ok',
                'note' => null,
                'remedy' => null,
                'hits' => $total,
                'per_second' => round($total / max(1, $minutes * 60), 3),
                'per_minute' => round($total / max(1, $minutes), 2),
                'errors' => $errors,
                'error_rate' => round(100 * $errors / $total, 3),
                'source' => self::RECORDER,
            ],
            'statuses' => [
                'state' => 'ok',
                'note' => null,
                'remedy' => null,
                'total' => $total,
                'classes' => $classes,
                'top' => $top,
                'source' => self::RECORDER,
            ],
        ];
    }

    /**
     * Both halves of the per-request read when there is nothing to divide.
     *
     * @param  array{state: string, note: string, remedy: string|null}  $reason
     * @return array{traffic: array<string, mixed>, statuses: array<string, mixed>}
     */
    private function unrecorded(array $reason): array
    {
        $reason['source'] = self::RECORDER;

        return [
            // Counts stay at zero and rates stay null on purpose: no request arriving is a real
            // zero, but a rate over a window nothing was measured in is not a number at all.
            'traffic' => array_merge($reason, ['hits' => 0, 'per_second' => null, 'per_minute' => null, 'errors' => 0, 'error_rate' => null]),
            'statuses' => array_merge($reason, ['total' => 0, 'classes' => [], 'top' => []]),
        ];
    }

    /**
     * A status class drawn in the same colour wherever it appears: red 5xx, amber 4xx, and neutral
     * for a redirect, which is neither a success nor a fault.
     */
    private function statusSeverity(string $class): string
    {
        return match ($class) {
            '5xx' => 'critical',
            '4xx' => 'warning',
            '3xx' => 'info',
            default => 'ok',
        };
    }

    private function statusClass(int $status): string
    {
        return match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            $status >= 200 => '2xx',
            default => '1xx',
        };
    }

    /**
     * Who is on the site, and how many of them the shop knows by name.
     *
     * Sessions only advance on real HTML navigations on the web channel, so this counts shoppers
     * with a browser open — not app users and not API callers. Saying that out loud matters: the
     * figure is a floor on "people using the shop", never the whole of it.
     *
     * @return array<string, mixed>
     */
    private function visitors(int $minutes): array
    {
        if (!config('telemetry.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Visit sessions are not being recorded because telemetry is switched off.',
                'remedy' => 'Set TELEMETRY_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'active' => null,
                'sessions' => 0,
                'authenticated' => 0,
                'guests' => 0,
                'by_type' => [],
                'source' => self::SESSIONS,
            ];
        }

        try {
            $since = $this->shopWindowStart($minutes);

            $rows = DB::table(self::SESSIONS)
                ->where('last_activity_at', '>=', $since)
                ->groupBy('user_type')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::SPLIT_ROWS)
                ->get(['user_type', DB::raw('COUNT(*) as sessions'), DB::raw('COUNT(DISTINCT visitor_id) as visitors')]);

            // Distinct visitors are counted once across the whole window rather than summed from
            // the rows above: one person who signs in mid-visit appears under two user types, and
            // adding those together would report two people where there is one.
            $active = (int) DB::table(self::SESSIONS)
                ->where('last_activity_at', '>=', $since)
                ->distinct()
                ->count('visitor_id');

            $newest = DB::table(self::SESSIONS)
                ->orderByDesc('last_activity_at')
                ->limit(1)
                ->value('last_activity_at');
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => $this->failureNote($exception),
                'remedy' => null,
                'active' => null,
                'sessions' => 0,
                'authenticated' => 0,
                'guests' => 0,
                'by_type' => [],
                'source' => self::SESSIONS,
            ];
        }

        if ($newest === null) {
            return [
                'state' => 'no_data',
                'note' => 'No visit session has ever been recorded on this deployment.',
                'remedy' => 'Sessions are written by the telemetry middleware on HTML page views. Confirm `telemetry.enabled` is true and that the storefront is being browsed by something other than the admin panel.',
                'active' => null,
                'sessions' => 0,
                'authenticated' => 0,
                'guests' => 0,
                'by_type' => [],
                'source' => self::SESSIONS,
            ];
        }

        $byType = [];
        $sessions = 0;
        $authenticated = 0;
        $guests = 0;

        foreach ($rows as $row) {
            // A null user_type predates the column being populated; it is not evidence of a guest,
            // so it is counted as its own label rather than folded into one.
            $type = $row->user_type === null ? 'unknown' : (string) $row->user_type;
            $count = (int) $row->sessions;
            $sessions += $count;

            if ($type === 'guest') {
                $guests += $count;
            } elseif ($type !== 'unknown') {
                $authenticated += $count;
            }

            $byType[] = ['type' => $type, 'sessions' => $count, 'visitors' => (int) $row->visitors];
        }

        return [
            'state' => 'ok',
            'note' => $sessions === 0
                ? 'Nobody has loaded a page in this window. The last session activity was ' . Clock::display($this->shopStampAsUtc($newest))->toDateTimeString() . '.'
                : null,
            'remedy' => null,
            'active' => $active,
            'sessions' => $sessions,
            'authenticated' => $authenticated,
            'guests' => $guests,
            'by_type' => $byType,
            'last_activity_at' => Clock::display($this->shopStampAsUtc($newest))->toDateTimeString(),
            'source' => self::SESSIONS,
        ];
    }

    /**
     * A shop-table timestamp, moved onto the UTC axis the rest of the dashboard renders from.
     *
     * The mirror of shopWindowStart(): those rows carry local wall time with no offset in them, so
     * they have to be told which zone they were written in before Clock::display() can be trusted
     * to put them beside a monitoring timestamp.
     */
    private function shopStampAsUtc(string $stamp): Carbon
    {
        return Carbon::parse($stamp, date_default_timezone_get())->setTimezone(Clock::TIMEZONE);
    }

    /**
     * What the shop is answering most right now.
     *
     * The per-route errors and error_rate published here are the same reading the Requests
     * section is gated on, which is why this whole section sits behind monitoring_errors in
     * MonitoringPermissionService. Dropping those two columns is what would hand it back to a
     * view-only operator; nothing in this panel decides that, and nothing in it should.
     *
     * @return array<string, mixed>
     */
    private function busiestRoutes(string $range, array $collection): array
    {
        try {
            $routes = $this->reader->routeBreakdown($range, sort: 'hits', limit: self::ROUTE_ROWS);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'rows' => [], 'source' => self::BUCKETS];
        }

        if ($routes === []) {
            return array_merge($this->emptyReason($collection), ['rows' => [], 'source' => self::BUCKETS]);
        }

        $rows = array_map(fn (array $route) => [
            'route' => $route['route'],
            'method' => $route['method'],
            'channel' => $route['channel'],
            'hits' => $route['hits'],
            'per_minute' => $route['requests_per_minute'],
            'avg' => $route['avg'],
            'p95' => $route['p95'],
            'errors' => $route['errors'],
            'error_rate' => $route['error_rate'],
            'severity' => $this->errorSeverity($route['error_rate']),
        ], $routes);

        return ['state' => 'ok', 'note' => null, 'remedy' => null, 'rows' => $rows, 'source' => self::BUCKETS];
    }

    /**
     * Which client the traffic came from, read straight from the series table.
     *
     * SeriesReader is the way into monitoring_series everywhere it answers the question, and here
     * it does not: series() folds the labels together and reads value_sum, which for this counter
     * is total milliseconds. The request count is in `samples`, and a split needs one row per
     * label — so this is a grouped read on the same index (metric, resolution, bucket_at) that
     * SeriesReader itself uses.
     *
     * @return array<string, mixed>
     */
    private function platformSplit(string $range, string $resolution): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->where('metric', 'requests.by_platform')
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $this->reader->since($range))
                ->groupBy('label')
                ->orderByDesc(DB::raw('SUM(samples)'))
                ->limit(self::SPLIT_ROWS)
                ->get(['label', DB::raw('SUM(samples) as requests'), DB::raw('SUM(value_sum) as duration_sum')]);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'total' => 0, 'rows' => [], 'source' => 'monitoring_series'];
        }

        $total = 0;
        $split = [];
        foreach ($rows as $row) {
            $requests = (int) $row->requests;
            $total += $requests;
            $split[] = [
                'label' => $row->label === '' ? 'unknown' : (string) $row->label,
                'hits' => $requests,
                'avg_ms' => $requests > 0 ? round((float) $row->duration_sum / $requests, 1) : null,
            ];
        }

        if ($total === 0) {
            return [
                'state' => 'no_data',
                'note' => 'No platform was recorded in this window. A request is filed under the platform its X-Platform header declares, or the one its user agent implies.',
                'remedy' => null,
                'total' => 0,
                'rows' => [],
                'source' => 'monitoring_series',
            ];
        }

        foreach ($split as $index => $row) {
            $split[$index]['share_pct'] = round(100 * $row['hits'] / $total, 1);
        }

        return ['state' => 'ok', 'note' => null, 'remedy' => null, 'total' => $total, 'rows' => $split, 'source' => 'monitoring_series'];
    }

    /**
     * Desktop, mobile, tablet or a crawler — as classified when the session opened.
     *
     * @return array<string, mixed>
     */
    private function deviceSplit(int $minutes): array
    {
        if (!config('telemetry.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Devices are read from visit sessions, which are not being recorded because telemetry is switched off.',
                'remedy' => 'Set TELEMETRY_ENABLED=true in .env, then run `php artisan optimize:clear`.',
                'total' => 0,
                'rows' => [],
                'source' => self::SESSIONS,
            ];
        }

        try {
            $rows = DB::table(self::SESSIONS)
                ->where('last_activity_at', '>=', $this->shopWindowStart($minutes))
                ->groupBy('device')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit(self::SPLIT_ROWS)
                ->get(['device', DB::raw('COUNT(*) as sessions')]);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'total' => 0, 'rows' => [], 'source' => self::SESSIONS];
        }

        $total = 0;
        $split = [];
        foreach ($rows as $row) {
            $sessions = (int) $row->sessions;
            $total += $sessions;
            $split[] = ['label' => $row->device === null ? 'unknown' : (string) $row->device, 'hits' => $sessions];
        }

        if ($total === 0) {
            return [
                'state' => 'no_data',
                'note' => 'No session was active in this window, so there is no device mix to divide.',
                'remedy' => null,
                'total' => 0,
                'rows' => [],
                'source' => self::SESSIONS,
            ];
        }

        foreach ($split as $index => $row) {
            $split[$index]['share_pct'] = round(100 * $row['hits'] / $total, 1);
        }

        return ['state' => 'ok', 'note' => null, 'remedy' => null, 'total' => $total, 'rows' => $split, 'source' => self::SESSIONS];
    }

    /**
     * Browser mix, which this deployment cannot answer.
     *
     * The user agent is classified into a device bucket when the session opens and then thrown
     * away, so the browser family was never stored. Inferring one from the device column would be
     * inventing a reading, so this returns the same value object every unmeasurable metric does
     * and the view renders it as the gap it is.
     */
    private function browsers(): Metric
    {
        return Metric::notConfigured(
            source: self::SESSIONS,
            remedy: 'Recording it needs a browser column on visit_sessions, filled at session start from the same user agent TelemetryRecorder::device() already parses. Existing sessions cannot be backfilled: the agent string was never stored.',
            note: 'visit_sessions keeps only the device class derived from the user agent — desktop, mobile, tablet or bot — and never the agent string itself.',
        );
    }

    /**
     * Visitor country, which this deployment also cannot answer.
     */
    private function country(): Metric
    {
        return Metric::notConfigured(
            source: self::SESSIONS,
            remedy: 'Country needs two things: a GeoIP database on the server (a MaxMind GeoLite2-City .mmdb read through the geoip2/geoip2 package or ext-maxminddb), and a country column on visit_sessions resolved at session start. It cannot be backfilled — visit_sessions stores only a salted one-way ip_hash, so the addresses of past visits are already unrecoverable.',
            note: 'visit_sessions has no country column and no GeoIP database is installed on this server, so there is nothing to read and nothing honest to infer it from.',
        );
    }

    /**
     * The per-minute shape of the window, so a five-minute average is never read as a flat line.
     *
     * @return array<string, mixed>
     */
    private function timeline(string $range, array $collection): array
    {
        try {
            $timeline = $this->reader->requestTimeline($range);
        } catch (\Throwable $exception) {
            return ['state' => 'failed', 'note' => $this->failureNote($exception), 'remedy' => null, 'points' => [], 'source' => self::BUCKETS];
        }

        if (($timeline['points'] ?? []) === []) {
            return array_merge($timeline, $this->emptyReason($collection), ['source' => self::BUCKETS]);
        }

        return array_merge($timeline, ['state' => 'ok', 'note' => null, 'remedy' => null, 'source' => self::BUCKETS]);
    }

    /**
     * Why a bucket-sourced part of this page has nothing in it.
     *
     * Three different silences, and on a live window they are easy to confuse: collection is off,
     * nothing has ever been recorded, or the flush has fallen so far behind that the last few
     * minutes have not been written yet. Only the last one looks like a quiet shop and is not.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function emptyReason(array $collection): array
    {
        if (in_array($collection['state'], ['not_configured', 'no_data'], true)) {
            return [
                'state' => $collection['state'],
                'note' => (string) $collection['note'],
                'remedy' => $collection['remedy'],
            ];
        }

        if ($collection['state'] === 'stale') {
            return [
                'state' => 'stale',
                'note' => 'The newest bucket is ' . (int) $collection['age_seconds'] . ' seconds old, which is older than this whole window — so this is the flush falling behind, not the shop going quiet.',
                'remedy' => $collection['remedy'],
            ];
        }

        return [
            'state' => 'no_data',
            'note' => 'No request was folded into a bucket in this window. Buckets are written a minute in arrears, so the newest minute is often still in the buffer.',
            'remedy' => null,
        ];
    }

    /**
     * How loudly a failure rate is drawn, against the same thresholds every other section scores
     * against — so a route called amber here is not called green two clicks away.
     */
    private function errorSeverity(?float $errorRate): string
    {
        return match (true) {
            // Null is "not measured", which is not a quiet reading and must not be styled as one.
            $errorRate === null => 'info',
            $errorRate >= (float) config('monitoring.thresholds.error_rate_critical', 5.0) => 'critical',
            $errorRate >= (float) config('monitoring.thresholds.error_rate_warning', 1.0) => 'warning',
            default => 'ok',
        };
    }

    private function failureNote(\Throwable $exception): string
    {
        return Metric::describeFailure($exception);
    }
}
