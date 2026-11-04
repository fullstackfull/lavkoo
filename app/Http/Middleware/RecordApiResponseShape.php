<?php

namespace App\Http\Middleware;

use App\Services\DeveloperPortal\ResponseShapeRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Learns what the API answers with, without anyone writing it down.
 *
 * Terminable, so the caller has their response before any of this runs. Everything inside is
 * best-effort: the portal knowing less about an endpoint is not a reason for that endpoint to
 * behave differently.
 */
class RecordApiResponseShape
{
    public function __construct(private readonly ResponseShapeRecorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->recorder->record($request, $response);
        } catch (\Throwable) {
            // Never surface a documentation failure to a request that has already succeeded.
        }
    }
}
