<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Support\ClientEventIngest;
use App\Services\DeveloperPortal\ApiDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's door to the same beacon the storefront writes through.
 *
 * The web reports what a page load cannot see; the app has to report rather more, because in the
 * app almost nothing IS a page load. A shopper who taps a banner and lands on a product screen
 * makes one API call for that product and none that says a banner sent them there — so a merchant
 * comparing two banners had numbers for the web half of their audience and nothing at all for the
 * half on a phone.
 *
 * Public and unauthenticated, like the storefront's beacon and for the same reason: a banner is
 * shown to guests, and an endpoint that only accepted signed-in shoppers would report a biased
 * subset while looking complete. The trade is bounded the same way — the payload rules are the
 * beacon's own [ClientEventIngest], so only allow-listed names are accepted, none of them carry
 * money, ids are coerced to digits and every other key is dropped.
 *
 * It answers 204 to everything, including a payload it rejects entirely: an app cannot act on the
 * difference, and an error status would teach a prober what the endpoint accepts.
 */
class AnalyticsEventController extends Controller
{
    #[ApiDoc(
        summary: 'Report what only the app can see — a banner tapped, a list navigated',
        description: 'Send {"events":[{"name":"banner_clicked","entity_type":"banner","entity_id":"12"}]}. '
            . 'Only allow-listed event names are accepted (' . AnalyticsEvent::BANNER_CLICKED . ', '
            . AnalyticsEvent::PRODUCT_LIST_VIEWED . '); anything else is dropped silently. Nothing '
            . 'money-related is ever accepted from a client. Always answers 204 — analytics never '
            . 'fails the caller. The surface is taken from the X-Platform and X-App-Version headers, '
            . 'not from the body.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function __invoke(Request $request, Analytics $analytics, ClientEventIngest $ingest): JsonResponse
    {
        $silence = response()->json(null, 204);

        if (!app(AnalyticsPolicy::class)->enabled() || !config('analytics.beacon.enabled', true)) {
            return $silence;
        }

        $ingest->ingest($request->input('events'), $request);
        $analytics->flush($request);

        return $silence;
    }
}
