<?php

namespace App\Http\Middleware;

use App\Services\Monitoring\Ingest\RequestContext;
use App\Services\Monitoring\Ingest\RequestRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Measures every request without being felt by anyone making one.
 *
 * handle() does almost nothing: start a context, adopt or mint a correlation id, and put it on the
 * response so a support conversation can start from a header rather than a guess. Everything
 * expensive happens in terminate(), after the response bytes have gone out — the shopper is
 * already reading the page while the bucket is being updated.
 *
 * This sits alongside the existing RecordHttpTelemetry rather than replacing it: that one feeds
 * the visits/sources/Analytics story and is a row per request by design; this one feeds
 * operational monitoring and never writes a row per request at all.
 */
class MonitorRequest
{
    public const CORRELATION_HEADER = 'X-Correlation-Id';
    public const REQUEST_HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestRecorder $recorder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Registered globally so requests that match no route are measured too — a 404 storm is
            // exactly the thing an operations page must be able to see. The groups still list it,
            // so this runs twice per matched request; the context binding is the marker that says
            // the first pass already started.
            if (config('monitoring.enabled', true) && !app()->bound(RequestContext::class)) {
                $context = new RequestContext(
                    correlationId: $this->inboundCorrelationId($request),
                    startedAt: defined('LARAVEL_START') ? LARAVEL_START : microtime(true),
                );
                $context->platform = $this->platform($request);
                $context->appVersion = $this->appVersion($request);

                // Bound to the container so anything, anywhere in the request, can contribute
                // without being handed a reference through six constructors.
                app()->instance(RequestContext::class, $context);
                $this->recorder->beginRequest($context, $request);
            }
        } catch (\Throwable) {
            // Instrumentation that cannot start is instrumentation that is off, not a broken page.
        }

        $response = $next($request);

        try {
            if (app()->bound(RequestContext::class)) {
                $context = app(RequestContext::class);
                $response->headers->set(self::CORRELATION_HEADER, $context->correlationId);
                $response->headers->set(self::REQUEST_HEADER, $context->requestId);
            }
        } catch (\Throwable) {
            // headers are a convenience, not a requirement
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (!app()->bound(RequestContext::class)) {
                return;
            }
            $this->recorder->finishRequest(app(RequestContext::class), $request, $response);
        } catch (\Throwable) {
            // Never surface a monitoring failure to a request that has already succeeded.
        }
    }

    /**
     * A correlation id supplied by the caller, if it looks like one.
     *
     * Validated rather than trusted: this value ends up in log lines and database columns, and an
     * attacker-controlled string of arbitrary length has no business in either.
     */
    private function inboundCorrelationId(Request $request): ?string
    {
        $inbound = (string) $request->headers->get(self::CORRELATION_HEADER, '');

        return preg_match('/^[A-Za-z0-9\-]{8,40}$/', $inbound) === 1 ? $inbound : null;
    }

    /**
     * Which client this is. Derived from the app's own header when it sends one, otherwise from
     * the user agent — never guessed beyond that.
     */
    private function platform(Request $request): ?string
    {
        $declared = strtolower((string) $request->headers->get('X-Platform', ''));
        if (in_array($declared, ['android', 'ios', 'web'], true)) {
            return $declared;
        }

        $agent = strtolower((string) $request->userAgent());
        if ($agent === '') {
            return null;
        }
        if (str_contains($agent, 'okhttp') || str_contains($agent, 'android')) {
            return 'android';
        }
        if (str_contains($agent, 'darwin') || str_contains($agent, 'cfnetwork') || str_contains($agent, 'iphone') || str_contains($agent, 'ipad')) {
            return 'ios';
        }

        return 'web';
    }

    private function appVersion(Request $request): ?string
    {
        $version = (string) $request->headers->get('X-App-Version', '');

        return preg_match('/^[0-9A-Za-z\.\-\+]{1,32}$/', $version) === 1 ? $version : null;
    }
}
