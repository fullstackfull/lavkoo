<?php

namespace App\Services\Monitoring\Ingest;

use App\Services\Monitoring\Support\Redactor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Folds one request into the minute it belongs to.
 *
 * The whole cost of monitoring a request lives here, and it is deliberately: a handful of integer
 * increments in memory, one buffer write after the response has been sent, and nothing at all in
 * the database on the hot path.
 *
 * The one thing worth spelling out is the route KEY. Recording the raw path would make the metrics
 * table grow with the catalogue — a million products is a million series — so what gets stored is
 * the route PATTERN (`/product/{slug}`). Requests that match no route are folded into a single
 * `__unmatched__` series, because a scanner hitting ten thousand random URLs must not be able to
 * write ten thousand rows.
 */
class RequestRecorder
{
    public function __construct(
        private readonly MetricSink $sink,
        private readonly Redactor $redactor,
    ) {
    }

    /**
     * Attach the listeners that measure where a request's time goes.
     *
     * Query counting is always on: it is a closure over an already-emitted event and costs
     * nothing measurable. Capturing the SQL itself only happens for a request the sampler has
     * chosen to trace, or for a query slow enough to be worth a row on its own.
     */
    public function beginRequest(RequestContext $context, Request $request): void
    {
        $context->tracing = $this->shouldTrace();

        DB::listen(function (QueryExecuted $query) use ($context) {
            try {
                $context->dbQueries++;
                $context->dbMs += $query->time;

                $slowThreshold = (float) config('monitoring.tracing.slow_query_ms', 200);
                if ($query->time >= $slowThreshold) {
                    // Bounded: a pathological request must not accumulate thousands of these.
                    if (count($context->slowQueries) < 25) {
                        $context->slowQueries[] = [
                            'sql' => $this->redactor->sql($query->sql),
                            'ms' => (float) $query->time,
                            'connection' => $query->connectionName,
                        ];
                    }
                }

                if ($context->tracing) {
                    $context->addSpan(
                        kind: 'db',
                        name: $this->queryLabel($query->sql),
                        startOffsetMs: max(0, $context->elapsedMs() - $query->time),
                        durationMs: (float) $query->time,
                        attributes: ['sql' => $this->redactor->sql($query->sql), 'connection' => $query->connectionName],
                    );
                }
            } catch (\Throwable) {
                // A listener must never be able to break the query it is watching.
            }
        });
    }

    /**
     * Everything that happens after the response has been flushed to the client.
     */
    public function finishRequest(RequestContext $context, Request $request, Response $response): void
    {
        $durationMs = $context->elapsedMs();
        $path = trim($request->path(), '/');

        if ($this->isIgnored($path)) {
            return;
        }

        $status = $response->getStatusCode();
        $channel = $this->channelOf($path, $context);
        $route = $this->routeKey($request);
        $method = $request->getMethod();
        $bucket = BucketWriter::REQUEST_PREFIX . $channel . '|' . $method . '|' . $route;

        $this->sink->increment($bucket, 'hits');
        if ($status >= 500) {
            $this->sink->increment($bucket, 'errors');
        } elseif ($status >= 400) {
            $this->sink->increment($bucket, 'client_errors');
        }
        if ($durationMs >= (float) config('monitoring.timeout_threshold_ms', 30000)) {
            $this->sink->increment($bucket, 'timeouts');
        }

        $this->sink->increment($bucket, 'dur_sum', (int) round($durationMs));
        $this->sink->increment($bucket, 'hist.' . $this->histogramIndex($durationMs));
        $this->sink->observeExtremes($bucket, 'dur', (int) round($durationMs));

        $this->sink->increment($bucket, 'db_ms', (int) round($context->dbMs));
        $this->sink->increment($bucket, 'db_q', $context->dbQueries);
        $this->sink->increment($bucket, 'cache_ms', (int) round($context->cacheMs));
        $this->sink->increment($bucket, 'ext_ms', (int) round($context->externalMs));
        $this->sink->increment($bucket, 'ext_calls', $context->externalCalls);
        $this->sink->increment($bucket, 'jobs', $context->jobsDispatched);
        $this->sink->increment($bucket, 'mem_kb', (int) round(memory_get_peak_usage(true) / 1024));
        $this->sink->increment($bucket, 'res_bytes', $this->responseBytes($response));
        $this->sink->increment($bucket, 'req_bytes', (int) $request->headers->get('Content-Length', '0'));

        // Platform mix, so "is it only the Android app?" is answerable without a second system.
        //
        // The error counts are separate metrics rather than extra fields: monitoring_series stores
        // samples, a sum and three extremes, and nothing else — a counter that needs its own total
        // needs its own name. Without them the platform cards could say how much an app talked and
        // how slowly, but not whether it was being answered, which is the question actually being
        // asked when somebody opens the Android section.
        if ($context->platform !== null) {
            $platform = BucketWriter::SERIES_PREFIX . 'requests.by_platform|' . $context->platform;
            $this->sink->increment($platform, 'n');
            $this->sink->increment($platform, 'sum', (int) round($durationMs));

            if ($status >= 500) {
                $this->sink->increment(BucketWriter::SERIES_PREFIX . 'requests.by_platform.errors|' . $context->platform, 'n');
            } elseif ($status >= 400) {
                $this->sink->increment(BucketWriter::SERIES_PREFIX . 'requests.by_platform.client_errors|' . $context->platform, 'n');
            }

            // The version the app announced. Captured by the middleware since this system was
            // built and then dropped on the floor — so "the crashes are all on 4.2.1" was a
            // question nothing here could answer. The label is bounded by BucketWriter's
            // client-labelled cap, because this string comes from a header.
            if ($context->appVersion !== null) {
                $version = $context->platform . ':' . $context->appVersion;
                $this->sink->increment(BucketWriter::SERIES_PREFIX . 'requests.by_app_version|' . $version, 'n');
                $this->sink->increment(BucketWriter::SERIES_PREFIX . 'requests.by_app_version|' . $version, 'sum', (int) round($durationMs));

                if ($status >= 500) {
                    $this->sink->increment(BucketWriter::SERIES_PREFIX . 'requests.by_app_version.errors|' . $version, 'n');
                }
            }
        }
        $this->sink->increment(BucketWriter::SERIES_PREFIX . 'requests.by_status|' . $this->statusClass($status), 'n');

        $this->sink->flush(intdiv((int) $context->startedAt, 60) * 60);

        // Traces, slow queries and errors are separate stores with their own retention; they are
        // written by their own recorders so this hot path stays a list of increments.
        app(TraceRecorder::class)->persist($context, $request, $response, $route, $channel, $durationMs);
        app(SlowQueryRecorder::class)->persist($context, $route);
    }

    /**
     * The stable identity of a route.
     *
     * `/product/{slug}` for a matched route; the FIRST SEGMENT ONLY for anything unmatched, which
     * keeps a scanner probing /wp-admin, /.env, /phpmyadmin from writing a series each while still
     * showing that something is probing.
     */
    private function routeKey(Request $request): string
    {
        $route = $request->route();
        if ($route !== null) {
            $uri = '/' . ltrim((string) $route->uri(), '/');

            return $uri === '/' ? '/' : Str::limit($uri, 185, '');
        }

        $first = explode('/', trim($request->path(), '/'))[0] ?? '';

        return $first === '' ? '/' : '__unmatched__/' . Str::limit($first, 60, '');
    }

    private function channelOf(string $path, RequestContext $context): string
    {
        if (str_starts_with($path, 'api/')) {
            return 'api';
        }
        if (str_starts_with($path, 'admin/')) {
            return 'admin';
        }
        if (str_starts_with($path, 'vendor/') || str_starts_with($path, 'seller/')) {
            return 'vendor';
        }

        return 'web';
    }

    private function statusClass(int $status): string
    {
        return match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            default => '2xx',
        };
    }

    /**
     * Which latency bucket a duration falls into. The last index is the overflow bucket, so a
     * 90-second request is counted rather than dropped.
     */
    private function histogramIndex(float $durationMs): int
    {
        $bounds = (array) config('monitoring.latency_buckets_ms', []);
        foreach ($bounds as $index => $bound) {
            if ($durationMs <= $bound) {
                return $index;
            }
        }

        return count($bounds);
    }

    private function responseBytes(Response $response): int
    {
        $declared = $response->headers->get('Content-Length');
        if ($declared !== null && is_numeric($declared)) {
            return (int) $declared;
        }

        // A streamed or file response has no body to measure here, and reading it would consume it.
        $content = $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? ''
            : (string) $response->getContent();

        return strlen($content);
    }

    private function isIgnored(string $path): bool
    {
        foreach ((array) config('telemetry.ignore_prefixes', []) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        // The monitoring UI polls itself; recording that would make the dashboard its own busiest
        // route and the page's own latency its own worst metric.
        return str_starts_with($path, 'admin/monitoring');
    }

    /**
     * Whether to collect a full span tree for this request.
     *
     * The decision has to be made BEFORE the request runs, because spans can only be captured as
     * they happen — which means it cannot depend on how slow the request turns out to be. So it is
     * an honest sample: a small percentage of requests carry the cost of building a span per query,
     * and the rest carry none.
     *
     * A slow or failed request that was not sampled is not left unexplained: its route bucket still
     * has its timing split (db / cache / external), its slow queries are still recorded with their
     * fingerprints, and an exception still produces a full error record. What is missing is only
     * the waterfall — and raising the sample rate is one setting away when a specific route is
     * under investigation.
     */
    private function shouldTrace(): bool
    {
        if (!config('monitoring.tracing.enabled', true)) {
            return false;
        }

        $rate = (float) config('monitoring.tracing.sample_rate', 0.02);
        if ($rate <= 0.0) {
            return false;
        }

        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;
    }

    private function queryLabel(string $sql): string
    {
        $normalised = ltrim($sql);
        $verb = strtoupper(strtok($normalised, ' ') ?: 'QUERY');
        if (preg_match('/\b(?:from|into|update|join)\s+`?([a-zA-Z0-9_]+)`?/i', $normalised, $match) === 1) {
            return $verb . ' ' . $match[1];
        }

        return $verb;
    }
}
