<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\Support\ClientEventIngest;
use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Monitoring\Ingest\WebVitalsRecorder;
use App\Services\Telemetry\TelemetryRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the browser reports what a page load cannot.
 *
 * Two things are invisible to server-side collection, and both matter here. The storefront's
 * product filter navigates with history.pushState and a jQuery request, so filtering, sorting and
 * changing a price range are real page changes that produce no HTML response and are therefore
 * never counted. And anything that happens without a navigation at all — a wishlist toggle, a
 * quick view — leaves no trace on the server beyond an endpoint hit that could be anything.
 *
 * The security shape is the interesting part, because this is a public unauthenticated POST:
 *
 *  - Only allow-listed event names are accepted, and NONE of them involve money. A page that could
 *    post an order with a value of its choosing would make revenue analytics a suggestion box.
 *  - No value the client sends is trusted for anything except a page identity. Prices, totals and
 *    order ids come from the server or not at all.
 *  - Same-origin only, rate limited per visitor, and a hard cap on events per request.
 *  - It always answers 204 regardless. Telling a prober which names are valid is free
 *    reconnaissance, and a beacon that returns an error just makes the browser retry.
 */
class AnalyticsCollectController extends Controller
{
    public function __invoke(Request $request, Analytics $analytics): JsonResponse
    {
        // 204 for everything: this endpoint has nothing to say to its caller, and the browser is
        // using sendBeacon, which cannot read a response anyway.
        $silence = response()->json(null, 204);

        if (!app(AnalyticsPolicy::class)->enabled() || !config('analytics.beacon.enabled', true)) {
            return $silence;
        }

        if (!$this->sameOrigin($request)) {
            return $silence;
        }

        // The payload rules live in the ingest, which the app's own endpoint shares: a field this
        // door refuses must not be smuggled in through that one.
        app(ClientEventIngest::class)->ingest($request->input('events'), $request);

        $analytics->flush($request);

        $this->recordVitals($request);

        return $silence;
    }

    /**
     * Core Web Vitals, which only the browser can see.
     *
     * They arrive on the same beacon as the events because they leave on the same trip — one
     * request as the page goes away rather than two — but they are NOT analytics events. A vital
     * is a measurement of this shop's speed, so it goes to monitoring's own series store where the
     * rollup, the retention and the alert rules already know what to do with a number over time.
     *
     * The path is normalised on the server from what the client sent, which is the only field of
     * the payload the client influences at all: everything else is a bounded number.
     */
    private function recordVitals(Request $request): void
    {
        $vitals = $request->input('vitals');

        if (!is_array($vitals) || $vitals === [] || !config('monitoring.enabled', true)) {
            return;
        }

        try {
            app(WebVitalsRecorder::class)->record(
                readings: $vitals,
                request: $request,
                path: app(PathNormalizer::class)->normalise((string) $request->input('path', '/')),
            );
        } catch (\Throwable) {
            // A vital that cannot be stored is a gap in a chart, never an error on a page.
        }
    }

    /**
     * Only accept beacons that came from this shop's own pages.
     *
     * sendBeacon cannot set headers, so a CSRF token is not available — but the browser does set
     * Origin on a cross-origin POST, and a request whose Origin is not ours has no business
     * writing into this shop's analytics.
     */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        if (!is_string($origin) || $origin === '') {
            // No Origin at all is a same-origin navigation-time POST in some browsers, and also
            // what a bare script sends. A browser that is about to send a beacon was served a page
            // first, and that response set the visitor cookie — so requiring it here costs a real
            // visitor nothing and asks a script for something it has no reason to have. The
            // payload could do nothing dangerous either way; this keeps junk out of the reports.
            return $request->cookie(TelemetryRecorder::VISITOR_COOKIE) !== null;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, $request->getHost()) === 0;
    }
}
