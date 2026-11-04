<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\SellerInsight;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\SellerIntelligence\SellerInsightEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything waiting for the seller, in one list, worst first.
 *
 * Before this, each screen counted its own problems and none of them agreed: the dashboard knew
 * about unchecked orders, the stock page about low stock, the scorecard about breaches, and nothing
 * told a seller what to do first. This reads the one insight store, so Home, this list and — later —
 * notifications cannot contradict each other.
 *
 * Every entry carries an action and the thing it is about, because "3 products have problems" is not
 * something a seller can act on and "3 listings are missing images — open them" is.
 */
class SellerActionCenterController extends Controller
{
    public function __construct(private readonly SellerInsightEngine $insights)
    {
    }

    #[ApiDoc(
        summary: 'Everything waiting for the seller right now',
        description: 'Open insights, worst first, each with what it is about and the action to take. '
            . 'Accepts type (repeatable), severity=critical|high|medium|low and limit. Also returns a '
            . 'count per severity for a home badge. An empty list means nothing needs attention — '
            . 'entries are produced from real records only, never invented to fill the screen.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;

        $insights = $this->insights->open(
            sellerId: $sellerId,
            types: $this->types($request),
            severity: $this->severity($request),
            limit: $this->limit($request),
        );

        return response()->json([
            'counts' => $this->insights->counts($sellerId),
            'severities' => array_keys(SellerInsight::SEVERITY_ORDER),
            'insights' => $insights->map(fn (SellerInsight $insight) => [
                'id' => $insight->id,
                'type' => $insight->type,
                'severity' => $insight->severity,
                'title' => $insight->title,
                'body' => $insight->body,
                'entity_type' => $insight->entity_type,
                'entity_id' => $insight->entity_id,
                'metric' => $insight->metric,
                'impact' => $insight->impact,
                'action_key' => $insight->action_key,
                'action_params' => $insight->action_params,
                // Critical standing cannot be hidden; the client should not offer to.
                'dismissible' => $insight->severity !== SellerInsight::SEVERITY_CRITICAL,
                'created_at' => $insight->created_at,
            ])->values(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Stop showing one insight',
        description: 'Hides a single insight for this seller. Critical insights cannot be dismissed — '
            . 'a seller may choose not to act on a suggestion, but not to hide that their account is '
            . 'at risk. An insight belonging to another seller answers 404, the same as one that does '
            . 'not exist.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function dismiss(Request $request, $id): JsonResponse
    {
        if (!$this->insights->dismiss($request->seller->id, $id)) {
            return response()->json(['errors' => [
                ['code' => 'insight', 'message' => translate('this_cannot_be_dismissed')],
            ]], 404);
        }

        return response()->json(['message' => translate('dismissed')], 200);
    }

    /** @return array<int, string>|null */
    private function types(Request $request): ?array
    {
        $types = $request->query('type');
        $types = is_array($types) ? $types : ($types === null ? [] : [$types]);
        $types = array_values(array_filter($types, 'is_string'));

        return $types === [] ? null : $types;
    }

    private function severity(Request $request): ?string
    {
        $severity = $request->query('severity');

        return is_string($severity) && isset(SellerInsight::SEVERITY_ORDER[$severity]) ? $severity : null;
    }

    private function limit(Request $request): int
    {
        $limit = $request->query('limit');

        return max(1, min(is_string($limit) ? (int) $limit : 50, 100));
    }
}
