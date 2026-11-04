<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\ApprovalRequest;
use App\Services\ApprovalEngine;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The approvals inbox (Phase 3 — spec item 82).
 *
 * The operable surface of the reusable approval engine: every pending request from any workflow —
 * large refunds, payouts, price changes — in one queue, actioned in one place. The engine enforces
 * that the approver is not the maker and that one person cannot approve twice; this controller only
 * carries the admin's identity into it.
 */
class ApprovalController extends BaseController
{
    public function __construct(private readonly ApprovalEngine $engine)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $requests = collect();
        $workflows = [];

        if (Schema::hasTable('approval_requests')) {
            $requests = ApprovalRequest::query()
                ->when($request?->get('workflow'), fn ($q, $w) => $q->where('workflow', $w))
                ->when($request?->get('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', ApprovalRequest::STATUS_PENDING))
                ->latest('id')
                ->paginate(30)
                ->appends($request?->all() ?? []);

            $workflows = ApprovalRequest::query()->select('workflow')->distinct()->pluck('workflow')->filter()->values()->all();
        }

        return view('admin-views.marketplace.approvals', [
            'requests' => $requests,
            'workflows' => $workflows,
            'filters' => ['workflow' => $request?->get('workflow'), 'status' => $request?->get('status')],
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        return $this->decide($id, approve: true, note: $request->get('note'));
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        return $this->decide($id, approve: false, note: $request->get('note'));
    }

    private function decide(int $id, bool $approve, ?string $note): RedirectResponse
    {
        $approval = ApprovalRequest::find($id);
        if (!$approval) {
            ToastMagic::error(translate('approval_request_not_found'));

            return back();
        }

        $admin = auth('admin')->user();
        $result = $approve
            ? $this->engine->approve($approval, actorId: $admin->getKey(), actorType: 'admin', actorName: $admin->name ?? $admin->f_name ?? null, note: $note)
            : $this->engine->reject($approval, actorId: $admin->getKey(), actorType: 'admin', actorName: $admin->name ?? $admin->f_name ?? null, note: $note);

        if (!$result['ok']) {
            // The engine's own reasons are translation keys — "the person who requested cannot
            // approve", "already decided by this actor" — so the admin sees exactly why.
            ToastMagic::error(translate($result['reason']));

            return back();
        }

        ToastMagic::success(translate($approve ? 'approval_recorded' : 'request_rejected'));

        return back();
    }
}
