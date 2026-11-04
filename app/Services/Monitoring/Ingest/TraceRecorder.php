<?php

namespace App\Services\Monitoring\Ingest;

use Illuminate\Http\Request;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the sampled span trees.
 *
 * A trace is the only artefact that answers "where did the 724ms go" without guesswork, so what is
 * kept matters more than how much: a trace of a request that was fine is worth almost nothing,
 * while the trace of the one checkout that took nine seconds is worth all of them. The sampler in
 * RequestRecorder therefore decides who COLLECTS spans, and this decides who KEEPS them — a
 * sampled request that turned out to be fast and successful is dropped in favour of the ones that
 * were not.
 */
class TraceRecorder
{
    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }

    public function persist(
        RequestContext $context,
        Request $request,
        Response $response,
        string $route,
        string $channel,
        float $durationMs,
    ): void {
        try {
            if (!$context->tracing || $context->spans === []) {
                return;
            }

            $status = $response->getStatusCode();
            $reason = $this->keepBecause($status, $durationMs);
            if ($reason === null) {
                return;
            }

            $traceId = $context->traceId;

            $this->connection()->table('monitoring_traces')->insert([
                'trace_id' => $traceId,
                'correlation_id' => $context->correlationId,
                'route' => Str::limit($route, 185, ''),
                'method' => $request->getMethod(),
                'channel' => $channel,
                'status' => $status,
                'duration_ms' => (int) round($durationMs),
                'db_ms' => (int) round($context->dbMs),
                'db_queries' => $context->dbQueries,
                'cache_ms' => (int) round($context->cacheMs),
                'external_ms' => (int) round($context->externalMs),
                'memory_peak_kb' => (int) round(memory_get_peak_usage(true) / 1024),
                'user_type' => $this->userType(),
                'user_id' => config('monitoring.privacy.store_user_id', true) ? $this->userId() : null,
                'platform' => $context->platform,
                'app_version' => $context->appVersion,
                'release' => app_release_version(),
                'captured_because' => $reason,
                'has_error' => $status >= 500,
                'meta' => json_encode([
                    'jobs_dispatched' => $context->jobsDispatched,
                    'external_calls' => $context->externalCalls,
                    'cache_hits' => $context->cacheHits,
                    'cache_misses' => $context->cacheMisses,
                ]),
                'started_at' => Clock::stamp((int) $context->startedAt),
            ]);

            $spans = [];
            foreach ($context->spans as $span) {
                $spans[] = [
                    'trace_id' => $traceId,
                    'span_id' => $span['span_id'],
                    'parent_span_id' => $span['parent_span_id'] ?? null,
                    'kind' => $span['kind'],
                    'name' => $span['name'],
                    'start_offset_ms' => $span['start_offset_ms'],
                    'duration_ms' => $span['duration_ms'],
                    'failed' => $span['failed'],
                    'attributes' => json_encode($span['attributes'] ?? []),
                ];
            }

            foreach (array_chunk($spans, 100) as $chunk) {
                $this->connection()->table('monitoring_spans')->insert($chunk);
            }
        } catch (\Throwable) {
            // A trace that cannot be written is a trace that does not exist, nothing more.
        }
    }

    /**
     * Why this trace is worth keeping — or null to drop it.
     */
    private function keepBecause(int $status, float $durationMs): ?string
    {
        if ($status >= 500 && config('monitoring.tracing.always_trace_errors', true)) {
            return 'error';
        }

        $slowMs = (float) config('monitoring.tracing.always_trace_slower_than_ms', 1500);
        if ($slowMs > 0 && $durationMs >= $slowMs) {
            return 'slow';
        }

        // A healthy fast request: keep a thin slice of them so there is a baseline to compare the
        // bad ones against, and drop the rest.
        return (mt_rand() / mt_getrandmax()) < 0.1 ? 'sampled' : null;
    }

    private function userType(): ?string
    {
        foreach (['admin' => 'admin', 'seller' => 'vendor', 'customer' => 'customer'] as $guard => $type) {
            try {
                if (auth($guard)->check()) {
                    return $type;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return 'guest';
    }

    private function userId(): ?int
    {
        foreach (['admin', 'seller', 'customer'] as $guard) {
            try {
                if (auth($guard)->check()) {
                    return (int) auth($guard)->id();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
