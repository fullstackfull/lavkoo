<?php

namespace App\Services\Monitoring\Ingest;

use Illuminate\Support\Str;

/**
 * What we learned about the request currently being served.
 *
 * A singleton for the lifetime of one request. Everything that wants to contribute a measurement —
 * the query listener, the outbound HTTP client, a dispatched job, the tracer — writes here, and
 * the recorder folds the whole thing into one bucket update at the end.
 *
 * It also owns the correlation id, which is the thread that ties a request to its logs, its jobs,
 * its outbound calls and the payment webhook that comes back twenty seconds later. Without one,
 * "why did this order fail" is a manual search across four systems.
 */
class RequestContext
{
    /** Ties everything belonging to one logical operation together, across processes. */
    public readonly string $correlationId;

    /** Identifies this single request. */
    public readonly string $requestId;

    public readonly string $traceId;

    public readonly float $startedAt;

    public float $dbMs = 0.0;

    public int $dbQueries = 0;

    public float $cacheMs = 0.0;

    public int $cacheHits = 0;

    public int $cacheMisses = 0;

    public float $externalMs = 0.0;

    public int $externalCalls = 0;

    public int $jobsDispatched = 0;

    /** Set once the sampler decides this request's span tree is worth keeping. */
    public bool $tracing = false;

    /** @var array<int, array<string, mixed>> */
    public array $spans = [];

    /** @var array<int, array{sql: string, ms: float, connection: string}> */
    public array $slowQueries = [];

    public ?string $platform = null;

    public ?string $appVersion = null;

    public function __construct(?string $correlationId = null, ?float $startedAt = null)
    {
        // An inbound correlation id is honoured so a mobile app, a queue worker or an upstream
        // service can hand us the thread it already started.
        $this->correlationId = $correlationId ?: (string) Str::uuid();
        $this->requestId = bin2hex(random_bytes(8));
        $this->traceId = bin2hex(random_bytes(16));
        $this->startedAt = $startedAt ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true));
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }

    public function addSpan(string $kind, string $name, float $startOffsetMs, float $durationMs, array $attributes = [], bool $failed = false): void
    {
        if (!$this->tracing || count($this->spans) >= (int) config('monitoring.tracing.max_spans_per_trace', 200)) {
            return;
        }

        $this->spans[] = [
            'span_id' => bin2hex(random_bytes(8)),
            'kind' => $kind,
            'name' => Str::limit($name, 185, ''),
            'start_offset_ms' => (int) round($startOffsetMs),
            'duration_ms' => (int) round($durationMs),
            'failed' => $failed,
            'attributes' => $attributes,
        ];
    }
}
