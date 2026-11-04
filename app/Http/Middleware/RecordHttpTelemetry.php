<?php

namespace App\Http\Middleware;

use App\Services\Telemetry\TelemetryRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feeds the Monitoring and Analytics areas. Terminable: the row is written
 * after the response has been sent, so recording adds nothing the shopper
 * can feel — and the recorder itself swallows every failure.
 */
class RecordHttpTelemetry
{
    public function __construct(private readonly TelemetryRecorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Stored on the request, not on $this: Laravel resolves a FRESH
        // middleware instance for terminate(), so an instance property would
        // be gone and every duration would read as ~0ms.
        //
        // LARAVEL_START, not microtime() here: the clock has to start where the request did.
        // Starting it inside this middleware excluded framework bootstrap and every middleware
        // outside this one, so the same request was recorded with two different durations —
        // shorter here than in monitoring, which measures from the same constant.
        $request->attributes->set(
            'telemetry_started_at',
            defined('LARAVEL_START') ? LARAVEL_START : microtime(true),
        );
        $this->recorder->ensureVisitorCookie($request);
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $startedAt = $request->attributes->get('telemetry_started_at')
            ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true));
        $this->recorder->record($request, $response, (float) $startedAt);
    }
}
