<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\SellerInsight;
use App\Models\SellerStaff;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerIntelligence\ControlTowerService;
use App\Services\SellerIntelligence\DailyBriefingService;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The operational command centre: what is wrong, arranged by when it needs doing.
 *
 * Separate from the Action Center, which is one flat list, and from Home, which reports how the
 * business is going. This answers a different question — what should I do next — and the sections
 * are the answer rather than a way of organising one.
 */
class SellerControlTowerController extends Controller
{
    public function __construct(
        private readonly ControlTowerService $tower,
        private readonly DailyBriefingService $briefing,
    ) {
    }

    #[ApiDoc(
        summary: 'Everything wrong right now, by when it needs doing',
        description: 'Sections rather than one list: what is critical now, what is due today, and then '
            . 'each operational domain. Every section carries a count, how many things it is about, and '
            . 'the first few rows so the number can be opened rather than believed. Also a per-domain '
            . 'health state derived from the issues standing in it — "healthy" means nothing was '
            . 'detected, which is a narrower claim than "fine". An empty section means there is nothing '
            . 'there; nothing is generated to fill it.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->tower->forSeller($request->seller->id), 200);
    }

    #[ApiDoc(
        summary: 'The seller\'s day, counted rather than estimated',
        description: 'Orders, revenue, cancellations and returns for today with yesterday beside them, '
            . 'what is waiting (orders to ship, orders at SLA risk, returns to answer, low stock), the '
            . 'standing issue counts and the balance. Every figure is a query over real rows — no '
            . 'forecasting, no smoothing. A day-over-day percentage against a day with nothing in it is '
            . 'null rather than infinity, so the client says "no comparison" instead of inventing one.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function briefing(Request $request): JsonResponse
    {
        return response()->json($this->briefing->forSeller($request->seller->id), 200);
    }

    #[ApiDoc(
        summary: 'Move an issue along',
        description: 'Acknowledge it, start it, or park it as waiting on somebody else. Resolution is '
            . 'not settable: an issue is resolved when the condition that produced it stops being true, '
            . 'which the detector decides by ceasing to report it. Letting a seller mark a problem '
            . 'resolved while it is still happening would make the whole list a matter of opinion.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        group: 'vendors',
    )]
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:' . implode(',', SellerInsight::SELLER_SETTABLE_STATUSES),
            'assigned_staff_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $issue = SellerInsight::forSeller($request->seller->id)->find($id);

        if (!$issue || !$issue->isLive()) {
            // A closed issue is not editable, and one belonging to another seller is not found —
            // the same answer, so an id is not a way to discover another shop's problems.
            return response()->json(['errors' => [
                ['code' => 'issue', 'message' => translate('issue_not_found')],
            ]], 404);
        }

        $principal = $this->principal($request);

        // Defaults to the person acting: somebody who starts work on an issue owns it unless they
        // say otherwise, which is what happens on a team in practice.
        $assignee = $request->has('assigned_staff_id')
            ? $request['assigned_staff_id']
            : ($issue->assigned_staff_id ?? $principal->staffId());

        if ($assignee !== null && !$this->employs($request->seller->id, $assignee)) {
            // An id is not a way to reach into another shop's team, nor to leave a row pointing at
            // somebody who does not work here.
            return response()->json(['errors' => [
                ['code' => 'assigned_staff_id', 'message' => translate('that_person_does_not_work_in_this_shop')],
            ]], 403);
        }

        $issue->forceFill([
            'status' => $request['status'],
            'assigned_staff_id' => $assignee,
        ])->save();

        return response()->json([
            'message' => translate('issue_updated'),
            'status' => $issue->status,
            'assigned_staff_id' => $issue->assigned_staff_id,
        ], 200);
    }

    /** Is this staff id one of this shop's own people? */
    private function employs(int|string $sellerId, int|string $staffId): bool
    {
        return Schema::hasTable('seller_staff')
            && SellerStaff::where(['id' => $staffId, 'seller_id' => $sellerId])->exists();
    }

    private function principal(Request $request): SellerPrincipal
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        return $principal instanceof SellerPrincipal ? $principal : SellerPrincipal::owner($request->seller);
    }
}
