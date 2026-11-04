<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Monitoring\Ingest\AppHealthRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the mobile apps report the one thing the server cannot observe: that they stayed running.
 *
 * A crash produces no request. It is the absence of traffic, and no server-side monitor can tell
 * that apart from a quiet evening — so without this endpoint the Android and iOS sections could
 * describe how much each app talked and how fast it was answered, and could never say whether it
 * kept working in the customer's hand.
 *
 * Public and unauthenticated on purpose: an app that has just crashed is in no position to refresh
 * a token, and requiring one would mean the only sessions ever reported are the healthy ones —
 * which is precisely the bias that makes a crash-free figure worthless.
 *
 * That trade is bounded rather than waved through. Only three counters are accepted, each capped;
 * the platform must be one of two words and the version must match the same short pattern the
 * request middleware already enforces; nothing free-form is stored, so there is nothing here that
 * could carry a customer's data into a monitoring table. The endpoint answers 204 to everything,
 * so a prober learns nothing from it, and the panels label the figure self-reported rather than
 * presenting it as measured.
 */
class AppHealthController extends Controller
{
    #[ApiDoc(
        summary: 'Report app sessions, crashes and ANRs so crash-free rate can be shown',
        description: 'Counters only — no stack traces, device identifiers or user ids are accepted or stored. '
            . 'Send one call per app launch batch: {"platform":"android","app_version":"4.2.1","sessions":1,"crashes":0}. '
            . 'Always answers 204; monitoring never fails the caller.',
        audience: ApiDoc::CUSTOMER_APP,
        visibility: ApiDoc::PARTNER_VISIBLE,
        stability: ApiDoc::STABLE,
        since: 'v1',
    )]
    public function __invoke(Request $request, AppHealthRecorder $recorder): JsonResponse
    {
        // 204 for everything, including a payload this rejects entirely: an app cannot act on the
        // difference, and an error status would only teach a prober what the endpoint accepts.
        $silence = response()->json(null, 204);

        if (!config('monitoring.enabled', true)) {
            return $silence;
        }

        // Guarded, not cast. This endpoint is public and unauthenticated, and `{"platform":["x"]}`
        // would make `(string) $array` raise the warning this application turns into a throw — a 500
        // from the one endpoint whose whole contract is that it always answers 204 and can never
        // fail the app reporting to it. A field that is not a string is a field that was not sent.
        $recorder->record(
            platform: $this->text($request->input('platform')) ?? (string) $request->headers->get('X-Platform', ''),
            version: $this->text($request->input('app_version')) ?? $request->headers->get('X-App-Version'),
            counters: [
                'sessions' => $request->input('sessions'),
                'crashes' => $request->input('crashes'),
                'anrs' => $request->input('anrs'),
            ],
        );

        return $silence;
    }

    /** A value the recorder can use, or null when the caller sent something that is not one. */
    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
