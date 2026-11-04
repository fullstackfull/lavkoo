<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Traces: where one request's time actually went.
 *
 * Every other performance section answers "which route is slow". Only a trace answers the question
 * that follows it — of the 724ms, which 410 were a query, which 80 were an outbound call, and what
 * was the application itself doing for the rest. That is the whole purpose of this section, so the
 * span waterfall is the page and the list above it is only the way to reach one.
 *
 * Two decisions here are about honesty rather than presentation.
 *
 * The by-kind split is read from the request's own counters on monitoring_traces, not from summing
 * the spans. Spans nest — a controller span contains the query spans inside it — so adding their
 * durations together double counts, and a stacked bar built that way would routinely claim more
 * milliseconds than the request took. The counters are accumulated per category and cannot.
 *
 * The remainder is never called "application time" without saying what else is in it. It is the
 * total minus what was measured, so it holds PHP execution AND everything this build does not
 * instrument. Labelling it as pure application code would be a measurement nobody took.
 */
class TracesPanel implements Panel
{
    /**
     * Traces listed per window.
     *
     * A trace list is a way in, not a report: nobody reads the fiftieth row, and started_at is
     * indexed so the newest fifty cost one range scan.
     */
    private const LIST_LIMIT = 50;

    /**
     * Spans rendered for the selected trace.
     *
     * Collection already caps a trace at monitoring.tracing.max_spans_per_trace, but that ceiling
     * is configurable and this one bounds the page regardless of what was stored.
     */
    private const SPAN_LIMIT = 300;

    /** Ceiling on the grouped read behind the filter row. */
    private const OPTION_ROWS = 300;

    /** Attribute keys shown per span, and how much of each value. Redacted SQL runs long. */
    private const ATTRIBUTE_KEYS = 6;

    private const ATTRIBUTE_CHARS = 300;

    /** Why a trace was kept, as TraceRecorder writes it. */
    private const CAPTURED = ['error', 'slow', 'sampled'];

    /**
     * The vocabularies this system writes, published so the view can tell its own words apart from
     * stored ones.
     *
     * translate() persists any key it has not already seen into resources/lang/*\/new-messages.php,
     * so a value read out of a column must never reach it — one unrecognised span kind mints a
     * language key per value, which is the leak the settings section was fixed for. These columns
     * are free strings at the database level, so knowing which values are ours is the only way the
     * view can translate a label without translating whatever happened to be stored.
     */
    private const KINDS = ['db', 'cache', 'http', 'queue', 'view', 'middleware', 'controller', 'auth', 'app'];

    /** The account kinds TraceRecorder labels a request with. */
    private const USER_TYPES = ['admin', 'vendor', 'customer', 'guest'];

    /** The counters TraceRecorder stores in a trace's meta blob. */
    private const META_KEYS = ['jobs_dispatched', 'external_calls', 'cache_hits', 'cache_misses'];

    /**
     * Narrowest bar drawn, in percent.
     *
     * A 0.4ms span inside a two-second trace is 0.02% wide, which renders as nothing at all — and
     * a span that happened must not be invisible. The bar is widened to stay clickable; its
     * printed duration stays the real one.
     */
    private const MIN_BAR_PCT = 0.35;

    private const SOURCE = 'monitoring_traces + monitoring_spans';

    private ?Redactor $redactor = null;

    public function __construct(private readonly SeriesReader $reader)
    {
    }

    public function data(string $range, Request $request): array
    {
        $since = $this->reader->since($range);
        $filters = $this->filters($request);
        $capture = $this->capture();
        $options = $this->options($since);
        $traces = $this->traces($since, $filters);

        if ($traces['state'] === 'empty') {
            // Why the list is empty decides whether this page reads as "nothing was slow" or as
            // "nothing is being recorded", and only the panel holds the facts that tell them apart.
            $traces['reason'] = $this->emptyReason($range, $filters, $options, $capture);
        }

        return [
            'window' => [
                'range' => $range,
                'minutes' => $this->reader->window($range)['minutes'],
                'since' => Clock::display($since)->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'filters' => $filters,
            'options' => $options,
            'capture' => $capture,
            'vocabulary' => [
                'captured' => self::CAPTURED,
                'kinds' => self::KINDS,
                'user_types' => self::USER_TYPES,
                'meta' => self::META_KEYS,
            ],
            'summary' => $this->summary($options),
            'traces' => $traces,
            'selected' => $filters['trace'] === null ? null : $this->selectedTrace($filters['trace']),
            'source' => self::SOURCE,
        ];
    }

    /**
     * The filter row, normalised.
     *
     * Every value below reaches a query, so each is clamped here rather than trusted: the trace id
     * to the hex the recorder writes, the route to its column width, and the millisecond floor to
     * a real duration — an arbitrary one would be a scan waiting to be asked for.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $captured = $this->queryText($request, 'captured') ?? 'all';
        if (!in_array($captured, array_merge(self::CAPTURED, ['all']), true)) {
            $captured = 'all';
        }

        // Only something that reads as a number is a threshold. A word, a blank or an array is
        // nobody asking for a floor, and turning one into an integer would filter the list against
        // a millisecond figure the operator never typed.
        $minMs = $this->queryText($request, 'min_ms');
        $minMs = $minMs !== null && is_numeric($minMs) ? (int) $minMs : 0;

        $trace = strtolower($this->queryText($request, 'trace') ?? '');

        return [
            'captured' => $captured,
            // 191 is the column width in the migration: a longer value cannot match a row, so
            // binding it would only buy a scan.
            'route' => $this->trimmed($request, 'route', 191),
            // One hour is beyond any request this application can serve, and the cap keeps a
            // pasted URL from asking for a comparison the column cannot satisfy.
            'min_ms' => max(0, min(3600000, $minMs)),
            'trace' => preg_match('/^[0-9a-f]{8,32}$/', $trace) === 1 ? $trace : null,
        ];
    }

    /**
     * One query value as trimmed text, or null when the URL did not carry a scalar there.
     *
     * A query string is whatever shape somebody put in it: `?captured[]=x` hands PHP an array, and
     * casting that to string raises a warning which Laravel turns into an ErrorException — which
     * blanked this entire section behind the registry's generic failure card, from a URL anyone who
     * can open the page could type. A value that is not a scalar is not a filter, so it is dropped
     * here rather than coerced into one.
     */
    private function queryText(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : null;
    }

    private function trimmed(Request $request, string $key, int $maxLength): ?string
    {
        $value = $this->queryText($request, $key) ?? '';

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /**
     * What the window holds, for the filter row and for the empty state.
     *
     * One grouped read answers "which routes were traced here", "how many of each kind of capture"
     * and "what was the slowest". Three separate distinct-value queries over the same rows would be
     * two scans too many on a page that must never become the incident.
     *
     * @return array<string, mixed>
     */
    private function options(Carbon $since): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_traces')
                ->where('started_at', '>=', $since->toDateTimeString())
                ->groupBy('captured_because', 'route')
                ->orderBy('captured_because')
                ->limit(self::OPTION_ROWS)
                ->select(['captured_because', 'route'])
                ->selectRaw('COUNT(*) AS trace_rows, MAX(duration_ms) AS slowest_ms')
                ->get();

            $captured = [];
            $routes = [];
            $total = 0;
            $slowest = null;

            foreach ($rows as $row) {
                $count = (int) $row->trace_rows;
                $total += $count;

                $reason = is_string($row->captured_because) ? trim($row->captured_because) : '';
                if ($reason !== '') {
                    $captured[$reason] = ($captured[$reason] ?? 0) + $count;
                }

                $route = is_string($row->route) ? trim($row->route) : '';
                if ($route !== '') {
                    $routes[$route] = ($routes[$route] ?? 0) + $count;
                }

                if ($row->slowest_ms !== null) {
                    $slowest = max((int) $slowest, (int) $row->slowest_ms);
                }
            }

            arsort($captured);
            arsort($routes);

            return [
                'state' => 'ok',
                'captured' => $captured,
                'routes' => array_keys($routes),
                'traces_in_window' => $total,
                'slowest_ms' => $slowest,
                // At the ceiling the counts below are a floor, not a total, and the view says so
                // rather than presenting a partial tally as the whole window.
                'truncated' => $rows->count() >= self::OPTION_ROWS,
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'captured' => [],
                'routes' => [],
                'traces_in_window' => null,
                'slowest_ms' => null,
                'truncated' => false,
                'message' => $this->failureNote($exception),
            ];
        }
    }

    /**
     * The tiles above the table, read off the same grouped scan the filter row used.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function summary(array $options): array
    {
        if ($options['state'] !== 'ok') {
            return ['state' => 'unavailable', 'message' => $options['message'] ?? null];
        }

        $captured = $options['captured'];

        return [
            // A truncated grouped read can still be counted, but only as "at least this many".
            'state' => $options['truncated'] ? 'partial' : 'ok',
            'traces' => (int) $options['traces_in_window'],
            'errors' => (int) ($captured['error'] ?? 0),
            'slow' => (int) ($captured['slow'] ?? 0),
            'sampled' => (int) ($captured['sampled'] ?? 0),
            'slowest_ms' => $options['slowest_ms'],
            'source' => 'monitoring_traces',
        ];
    }

    /**
     * The bounded list of traces in the window, newest first.
     *
     * Newest rather than slowest on purpose: started_at is the indexed column that already carries
     * the window, and "slowest in the window" is one keystroke away in the millisecond filter
     * without asking the database to sort a range scan it cannot satisfy from an index.
     *
     * @return array<string, mixed>
     */
    private function traces(Carbon $since, array $filters): array
    {
        try {
            $rows = $this->traceQuery($since, $filters)
                ->orderByDesc('started_at')
                ->limit(self::LIST_LIMIT)
                ->get([
                    'trace_id', 'correlation_id', 'route', 'method', 'channel', 'status',
                    'duration_ms', 'db_ms', 'db_queries', 'cache_ms', 'external_ms',
                    'memory_peak_kb', 'user_type', 'platform', 'app_version', 'release',
                    'captured_because', 'has_error', 'started_at',
                ]);

            $presented = [];
            foreach ($rows as $row) {
                $presented[] = $this->presentTrace($row, $filters['trace']);
            }

            return [
                'state' => $presented === [] ? 'empty' : 'ok',
                'rows' => $presented,
                'limit' => self::LIST_LIMIT,
                // At the ceiling the window holds more than is shown, and narrowing is the answer
                // rather than a second page — deep paging into traces is not a real workflow.
                'capped' => count($presented) >= self::LIST_LIMIT,
                'source' => 'monitoring_traces',
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unavailable',
                'rows' => [],
                'limit' => self::LIST_LIMIT,
                'capped' => false,
                'message' => $this->failureNote($exception),
                'remedy' => 'monitoring_traces could not be read on the `' . (string) config('monitoring.connection', 'monitoring') . '` connection. Run `php artisan migrate` to create the monitoring tables, and check that connection\'s credentials.',
                'source' => 'monitoring_traces',
            ];
        }
    }

    /**
     * The filter row translated to SQL.
     *
     * started_at carries the window and is indexed on its own and as the second half of
     * (route, started_at), so adding a route narrows the scan rather than widening it.
     */
    private function traceQuery(Carbon $since, array $filters): Builder
    {
        $query = $this->reader->connection()->table('monitoring_traces')
            ->where('started_at', '>=', $since->toDateTimeString());

        if ($filters['captured'] !== 'all') {
            $query->where('captured_because', $filters['captured']);
        }

        if ($filters['route'] !== null) {
            $query->where('route', $filters['route']);
        }

        if ($filters['min_ms'] > 0) {
            $query->where('duration_ms', '>=', $filters['min_ms']);
        }

        return $query;
    }

    /**
     * One trace row, reduced to what the table and the header show.
     *
     * @return array<string, mixed>
     */
    private function presentTrace(object $row, ?string $selectedTraceId): array
    {
        $traceId = (string) $row->trace_id;
        $capturedBecause = (string) ($row->captured_because ?? 'sampled');

        return [
            'trace_id' => $traceId,
            // The head of the id is enough to recognise a row; the full value stays for the link
            // and the tooltip so it can still be pasted into a log search.
            'short_id' => mb_substr($traceId, 0, 12),
            'correlation_id' => $this->nullableString($row->correlation_id ?? null),
            'route' => $this->nullableString($row->route ?? null),
            'method' => $this->nullableString($row->method ?? null),
            'channel' => $this->nullableString($row->channel ?? null),
            'status' => $this->nullableInt($row->status ?? null),
            'duration_ms' => $this->nullableInt($row->duration_ms ?? null),
            'db_ms' => $this->nullableInt($row->db_ms ?? null),
            'db_queries' => $this->nullableInt($row->db_queries ?? null),
            'cache_ms' => $this->nullableInt($row->cache_ms ?? null),
            'external_ms' => $this->nullableInt($row->external_ms ?? null),
            'memory_peak_kb' => $this->nullableInt($row->memory_peak_kb ?? null),
            'user_type' => $this->nullableString($row->user_type ?? null),
            'platform' => $this->nullableString($row->platform ?? null),
            'app_version' => $this->nullableString($row->app_version ?? null),
            'release' => $this->nullableString($row->release ?? null),
            'captured_because' => $capturedBecause,
            'has_error' => (bool) ($row->has_error ?? false),
            'started_at' => $this->moment($row->started_at ?? null),
            'severity' => match ($capturedBecause) {
                'error' => 'critical',
                'slow' => 'warning',
                default => 'minor',
            },
            'is_selected' => $selectedTraceId !== null && $selectedTraceId === $traceId,
        ];
    }

    /**
     * The selected trace, its span tree, and the answer to where its milliseconds went.
     *
     * @return array<string, mixed>
     */
    private function selectedTrace(string $traceId): array
    {
        try {
            $row = $this->reader->connection()->table('monitoring_traces')
                ->where('trace_id', $traceId)
                ->limit(1)
                ->first();
        } catch (\Throwable $exception) {
            return ['state' => 'unavailable', 'trace_id' => $traceId, 'message' => $this->failureNote($exception)];
        }

        if ($row === null) {
            return [
                'state' => 'no_data',
                'trace_id' => $traceId,
                'note' => 'This trace is no longer stored.',
                'remedy' => 'Traces are pruned after monitoring.retention.trace_days (currently ' . app(MonitoringSettings::class)->retentionDays('trace_days', 3) . ' days). Raise MONITORING_RETENTION_TRACE_DAYS to keep them longer — spans are the largest table monitoring writes, so the default is deliberately short.',
            ];
        }

        $trace = $this->presentTrace($row, $traceId);
        $spans = $this->spans($traceId, $trace['duration_ms']);

        return [
            'state' => 'ok',
            'trace_id' => $traceId,
            'trace' => $trace,
            'meta' => $this->meta($row->meta ?? null),
            'split' => $this->timeSplit($trace),
            'spans' => $spans,
        ];
    }

    /**
     * The waterfall, already positioned.
     *
     * Percentages are computed here rather than in the view because the scale is a decision, not a
     * formatting step: it needs the trace's own duration, the furthest point any span reached, and
     * a rule for what to do when those disagree.
     *
     * @return array<string, mixed>
     */
    private function spans(string $traceId, ?int $traceDurationMs): array
    {
        try {
            $rows = $this->reader->connection()->table('monitoring_spans')
                ->where('trace_id', $traceId)
                ->orderBy('start_offset_ms')
                ->limit(self::SPAN_LIMIT + 1)
                ->get(['span_id', 'parent_span_id', 'kind', 'name', 'start_offset_ms', 'duration_ms', 'failed', 'attributes']);
        } catch (\Throwable $exception) {
            return ['state' => 'unavailable', 'rows' => [], 'message' => $this->failureNote($exception)];
        }

        if ($rows->isEmpty()) {
            return [
                'state' => 'no_data',
                'rows' => [],
                'note' => 'The trace was recorded but no span was stored with it.',
                'remedy' => 'Spans are written only for a request the sampler chose to instrument. A trace kept as `slow` or `error` after tracing was switched off mid-request has no spans to show.',
            ];
        }

        $truncated = $rows->count() > self::SPAN_LIMIT;
        $rows = $rows->take(self::SPAN_LIMIT);

        $furthest = 0;
        foreach ($rows as $row) {
            $furthest = max($furthest, (int) $row->start_offset_ms + (int) $row->duration_ms);
        }

        // The trace's own duration is the honest axis: it is the wall clock the shopper waited.
        // Spans can only overrun it when the request finished before a late span was closed, and
        // in that case the axis stretches to the furthest one so no bar is drawn off the end.
        $scale = max((int) $traceDurationMs, $furthest);
        $basis = $traceDurationMs !== null && $traceDurationMs >= $furthest ? 'trace_duration' : 'span_extent';

        if ($scale <= 0) {
            return [
                'state' => 'no_scale',
                'rows' => [],
                'note' => 'Neither the trace duration nor any span carries a length, so there is no axis to draw the waterfall against.',
            ];
        }

        $depths = $this->depths($rows);
        $waterfall = [];
        $byKind = [];

        foreach ($rows as $row) {
            $start = max(0, (int) $row->start_offset_ms);
            $duration = max(0, (int) $row->duration_ms);
            $kind = $this->nullableString($row->kind) ?? 'app';

            // Kept a hair short of the end so a span that started at the last millisecond still
            // has room for its minimum bar rather than overflowing the track.
            $left = min(100 - self::MIN_BAR_PCT, 100 * $start / $scale);
            $width = max(self::MIN_BAR_PCT, min(100 - $left, 100 * $duration / $scale));

            $spanId = (string) $row->span_id;
            $waterfall[] = [
                'span_id' => $spanId,
                'parent_span_id' => $this->nullableString($row->parent_span_id ?? null),
                'kind' => $kind,
                'name' => (string) $row->name,
                'depth' => $depths[$spanId] ?? 0,
                'start_offset_ms' => $start,
                'end_offset_ms' => $start + $duration,
                'duration_ms' => $duration,
                'share_pct' => round(100 * $duration / $scale, 1),
                'failed' => (bool) $row->failed,
                'left_pct' => round($left, 3),
                'width_pct' => round($width, 3),
                // True when the bar had to be widened to stay visible, so the view can avoid
                // implying a length the span did not have. The zero-length case counts: a span
                // that took no measurable time is exactly where a floor-width bar claims the most
                // that was never recorded, and on a real trace most of the rows are that case.
                'widened' => (100 * $duration / $scale) < self::MIN_BAR_PCT,
                'attributes' => $this->attributes($row->attributes ?? null),
            ];

            $tally = $byKind[$kind] ?? ['kind' => $kind, 'spans' => 0, 'total_ms' => 0, 'max_ms' => 0, 'failed' => 0];
            $tally['spans']++;
            $tally['total_ms'] += $duration;
            $tally['max_ms'] = max($tally['max_ms'], $duration);
            $tally['failed'] += $row->failed ? 1 : 0;
            $byKind[$kind] = $tally;
        }

        uasort($byKind, static fn (array $a, array $b) => $b['total_ms'] <=> $a['total_ms']);

        return [
            'state' => 'ok',
            'rows' => $waterfall,
            'count' => count($waterfall),
            // Span totals are kept apart from the stacked bar above them: these sum nested spans,
            // so they can exceed the trace duration and are a census of what was instrumented, not
            // a division of the request's time.
            'by_kind' => array_values($byKind),
            'scale' => ['basis' => $basis, 'total_ms' => $scale],
            'truncated' => $truncated,
            'span_ceiling' => self::SPAN_LIMIT,
            'collection_ceiling' => (int) config('monitoring.tracing.max_spans_per_trace', 200),
            'source' => 'monitoring_spans',
        ];
    }

    /**
     * How deeply each span is nested, for the indent in front of its name.
     *
     * Walked with a visited set: a parent id that points at an ancestor would otherwise be an
     * infinite loop on a page whose whole job is to stay cheap.
     *
     * @param  iterable<object>  $rows
     * @return array<string, int>
     */
    private function depths(iterable $rows): array
    {
        $parents = [];
        foreach ($rows as $row) {
            $parents[(string) $row->span_id] = $this->nullableString($row->parent_span_id ?? null);
        }

        $depths = [];
        foreach (array_keys($parents) as $spanId) {
            $depth = 0;
            $seen = [$spanId => true];
            $cursor = $parents[$spanId];

            while ($cursor !== null && isset($parents[$cursor]) && !isset($seen[$cursor]) && $depth < 8) {
                $seen[$cursor] = true;
                $depth++;
                $cursor = $parents[$cursor];
            }

            $depths[$spanId] = $depth;
        }

        return $depths;
    }

    /**
     * Where the milliseconds went, as shares of the whole request.
     *
     * Read from the trace's own counters rather than from the spans: spans nest, so summing them
     * double counts and would routinely claim more time than the request took.
     *
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function timeSplit(array $trace): array
    {
        $total = $trace['duration_ms'];
        $recorded = [
            'db' => $trace['db_ms'],
            'cache' => $trace['cache_ms'],
            'http' => $trace['external_ms'],
        ];

        $missing = array_keys(array_filter($recorded, static fn (?int $ms) => $ms === null));
        $measured = array_sum(array_map(
            static fn (?int $ms) => (int) $ms,
            array_filter($recorded, static fn (?int $ms) => $ms !== null),
        ));

        $segments = [];
        foreach ($recorded as $kind => $ms) {
            $segments[] = [
                'kind' => $kind,
                'state' => $ms === null ? 'not_recorded' : 'ok',
                'ms' => $ms,
                'share_pct' => null,
                'basis' => 'measured',
            ];
        }

        if ($total === null || $total <= 0) {
            return [
                'state' => 'no_total',
                'total_ms' => $total,
                'segments' => $segments,
                'missing' => $missing,
                'note' => 'This trace has no recorded duration, so its parts cannot be expressed as shares of anything.',
            ];
        }

        if ($measured > $total) {
            // Concurrent work — a queued external call overlapping a query — legitimately produces
            // this. Drawing it would need a segment longer than the bar, so the figures are shown
            // and the division is not.
            return [
                'state' => 'over_attributed',
                'total_ms' => $total,
                'measured_ms' => $measured,
                'segments' => $segments,
                'missing' => $missing,
                'note' => 'The measured parts add up to more than the request took, which happens when work overlaps. The parts are listed, but they cannot be drawn as a division of the whole.',
            ];
        }

        foreach ($segments as $index => $segment) {
            if ($segment['ms'] !== null) {
                $segments[$index]['share_pct'] = round(100 * $segment['ms'] / $total, 1);
            }
        }

        $remainder = $total - $measured;
        $segments[] = [
            'kind' => 'app',
            'state' => 'ok',
            'ms' => $remainder,
            'share_pct' => round(100 * $remainder / $total, 1),
            // Named as a remainder, not as a measurement: it is whatever the counters above did
            // not account for, which is PHP execution plus anything this build does not instrument.
            'basis' => 'remainder',
        ];

        return [
            'state' => $missing === [] ? 'ok' : 'partial',
            'total_ms' => $total,
            'measured_ms' => $measured,
            'segments' => $segments,
            'missing' => $missing,
            'note' => $missing === []
                ? null
                : 'This trace has no ' . implode(', ', $missing) . ' counter, so that time is inside the remainder rather than beside it.',
        ];
    }

    /**
     * A span's attributes, safe to put on a screen.
     *
     * Redacted on the way out as well as on the way in: this panel is the last gate before stored
     * SQL and sanitised URLs reach a browser.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private function attributes(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $clean = $this->redactor()->array($decoded);
        $attributes = [];

        foreach ($clean as $key => $value) {
            if (count($attributes) >= self::ATTRIBUTE_KEYS) {
                break;
            }

            $attributes[] = [
                'key' => mb_substr((string) $key, 0, 40),
                'value' => mb_substr($this->scalarText($value), 0, self::ATTRIBUTE_CHARS),
            ];
        }

        return $attributes;
    }

    /**
     * Any attribute value as text, including the nested arrays a redacted payload can hold.
     */
    private function scalarText(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    /**
     * The trace's own meta counters, which are not spans and not durations.
     *
     * @return array<int, array{key: string, value: string}>
     */
    private function meta(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $meta = [];
        foreach ($this->redactor()->array($decoded) as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $meta[] = ['key' => (string) $key, 'value' => mb_substr((string) $value, 0, 60)];
        }

        return $meta;
    }

    /**
     * Whether tracing is on, what it keeps, and for how long.
     *
     * An empty trace list is the normal state of a healthy shop at a 2% sample rate, so the page
     * cannot be read at all without knowing the sampling rules it was filled under.
     *
     * @return array<string, mixed>
     */
    private function capture(): array
    {
        $collecting = (bool) config('monitoring.enabled', true);
        $tracing = (bool) config('monitoring.tracing.enabled', true);
        $sampleRate = (float) config('monitoring.tracing.sample_rate', 0.02);
        $slowMs = (float) config('monitoring.tracing.always_trace_slower_than_ms', 1500);
        $retentionDays = app(MonitoringSettings::class)->retentionDays('trace_days', 3);

        $base = [
            'collection_enabled' => $collecting,
            'tracing_enabled' => $tracing,
            'sample_rate' => $sampleRate,
            'sample_pct' => round($sampleRate * 100, 2),
            'always_trace_slower_than_ms' => $slowMs > 0 ? (int) $slowMs : null,
            'always_trace_errors' => (bool) config('monitoring.tracing.always_trace_errors', true),
            'retention_days' => $retentionDays,
            'remedy' => 'Tracing keeps every 5xx, every request slower than MONITORING_TRACE_SLOW_MS, and MONITORING_TRACE_SAMPLE_RATE of the rest. Set MONITORING_TRACING=true and run `php artisan optimize:clear` to switch it on.',
        ];

        try {
            return array_merge($base, [
                'state' => 'ok',
                // An existence probe, not a count: the question is only whether this store has ever
                // held a trace, and COUNT(*) with no useful predicate is the scan this page must
                // never perform.
                'ever_recorded' => $this->reader->connection()->table('monitoring_traces')->limit(1)->exists(),
            ]);
        } catch (\Throwable $exception) {
            return array_merge($base, [
                'state' => 'unavailable',
                'ever_recorded' => null,
                'message' => $this->failureNote($exception),
                'remedy' => 'The monitoring tables could not be read on the `' . (string) config('monitoring.connection', 'monitoring') . '` connection. Run `php artisan migrate` and check MONITORING_DB_* in .env.',
            ]);
        }
    }

    /**
     * Why the list came back empty, as a key the view can phrase.
     *
     * Six silences, and they are not interchangeable — one of them is good news, three are
     * settings, one is a shared link asking for more history than is kept, and one is a fault.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $capture
     */
    private function emptyReason(string $range, array $filters, array $options, array $capture): string
    {
        if ($capture['state'] !== 'ok' || $options['state'] !== 'ok') {
            return 'unreadable';
        }

        if ($capture['collection_enabled'] === false) {
            return 'collection_off';
        }

        if ($capture['tracing_enabled'] === false) {
            return 'tracing_off';
        }

        $narrowed = $filters['captured'] !== 'all' || $filters['route'] !== null || $filters['min_ms'] > 0;
        if ((int) $options['traces_in_window'] > 0 && $narrowed) {
            return 'filtered_out';
        }

        if ($capture['ever_recorded'] === false) {
            return 'never_recorded';
        }

        // A 30-day window against a three-day retention is empty by design, and reading that as
        // "the shop had no slow requests last month" is the wrong conclusion entirely.
        if ($this->reader->window($range)['minutes'] > $capture['retention_days'] * 1440) {
            return 'beyond_retention';
        }

        return 'quiet_window';
    }

    /**
     * A stored UTC timestamp, ready to render and ready to age.
     *
     * @return array{at: string, age_seconds: int}|null
     */
    private function moment(mixed $stamp): ?array
    {
        if (!is_string($stamp) && !$stamp instanceof \DateTimeInterface) {
            return null;
        }

        // MySQL hands back zero dates as strings on a non-strict connection, and Carbon throws on
        // them.
        if (is_string($stamp) && ($stamp === '' || str_starts_with($stamp, '0000-'))) {
            return null;
        }

        try {
            $moment = Clock::parse($stamp instanceof \DateTimeInterface ? Carbon::instance($stamp) : $stamp);
        } catch (\Throwable) {
            return null;
        }

        return [
            'at' => Clock::display($moment)->toDateTimeString(),
            'age_seconds' => max(0, (int) $moment->diffInSeconds(Clock::now())),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function failureNote(\Throwable $exception): string
    {
        return Metric::describeFailure($exception);
    }

    private function redactor(): Redactor
    {
        return $this->redactor ??= Redactor::make();
    }
}
