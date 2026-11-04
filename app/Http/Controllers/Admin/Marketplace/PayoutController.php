<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\Seller;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\PayoutService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin lifecycle for seller-initiated ledger payouts (Phase 3, Stage B).
 *
 * The seller side (request / cancel) shipped complete, but there was no admin surface calling
 * PayoutService::review / markPaid / releaseReservation — so a request could only ever be cancelled by
 * the seller and its reserved funds stayed locked. This is that missing surface. All money movement
 * still runs through PayoutService, so the reservation/release/paid ledger semantics the tests cover
 * are exactly what the buttons trigger.
 */
class PayoutController extends BaseController
{
    public function __construct(private readonly PayoutService $payoutService)
    {
    }

    public function index(?Request $request = null, ?string $type = null): View
    {
        $request ??= request();

        if (!Schema::hasTable('vendor_payout_requests')) {
            return view('admin-views.marketplace.payouts', [
                'payouts' => collect(), 'sellers' => collect(), 'counts' => [], 'status' => null, 'unavailable' => true,
            ]);
        }

        $status = $request->get('status');
        $payouts = VendorPayoutRequest::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->get('seller_id'), fn ($q) => $q->where('seller_id', $request->get('seller_id')))
            ->latest('id')
            ->paginate(20)
            ->appends($request->all());

        $sellerIds = $payouts->pluck('seller_id')->unique()->filter()->all();
        $sellers = Seller::with('shop:id,seller_id,name')->whereIn('id', $sellerIds)->get(['id', 'f_name', 'l_name'])->keyBy('id');

        return view('admin-views.marketplace.payouts', [
            'payouts' => $payouts,
            'sellers' => $sellers,
            'counts' => $this->statusCounts(),
            'status' => $status,
            'unavailable' => false,
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        $request = $this->findOpen($id);
        if (!$request) {
            return back();
        }

        $done = $this->payoutService->review(
            request: $request,
            approve: true,
            reviewer: auth('admin')->id(),
            note: request('note'),
        );

        $done ? ToastMagic::success(translate('payout_approved'))
              : ToastMagic::error(translate('this_payout_can_no_longer_be_approved'));

        return back();
    }

    public function markPaid(int $id): RedirectResponse
    {
        $request = VendorPayoutRequest::find($id);
        if (!$request) {
            ToastMagic::error(translate('payout_not_found'));
            return back();
        }

        // Separation of duties: when settlement_maker_checker is on, the admin who approved a payout
        // cannot also pay it — dual control over money leaving the platform.
        if (getWebConfig(name: 'settlement_maker_checker') && (int) $request->reviewed_by === (int) auth('admin')->id()) {
            ToastMagic::error(translate('a_different_admin_must_mark_this_payout_paid'));
            return back();
        }

        // Large-payout dual control: a still-pending approval must clear before payment.
        if (!$this->payoutService->dualControlSatisfied($request)) {
            ToastMagic::error(translate('this_payout_needs_its_dual-control_approval_completed_before_it_can_be_paid'));
            return back();
        }

        $done = $this->payoutService->markPaid(request: $request, paymentReference: request('payment_reference'));

        $done ? ToastMagic::success(translate('payout_marked_paid'))
              : ToastMagic::error(translate('only_an_approved_payout_can_be_marked_paid'));

        return back();
    }

    /**
     * A transfer the bank sent back.
     *
     * STATUS_FAILED existed on the model and the badge was already coloured for it; nothing in the
     * application set it, so a bounced payout stayed marked PAID with money the seller never got.
     * The reservation goes back to available, because a failed transfer means the money never left.
     */
    public function markFailed(int $id): RedirectResponse
    {
        $request = VendorPayoutRequest::find($id);

        if (!$request) {
            ToastMagic::error(translate('payout_not_found'));

            return back();
        }

        $this->payoutService->markFailed($request, request('note'))
            ? ToastMagic::success(translate('the_payout_was_marked_failed_and_the_money_is_back_in_the_sellers_available_balance'))
            : ToastMagic::error(translate('only_an_approved_processing_or_paid_payout_can_be_marked_failed'));

        return back();
    }

    /** Send a failed payout again — a fresh request, because the reservation was released. */
    public function reissue(int $id): RedirectResponse
    {
        $request = VendorPayoutRequest::find($id);

        if (!$request) {
            ToastMagic::error(translate('payout_not_found'));

            return back();
        }

        $result = $this->payoutService->reissue($request);

        ($result['ok'] ?? false)
            ? ToastMagic::success(translate('a_new_payout_request_was_opened') . ' — ' . $result['request']->reference)
            : ToastMagic::error(translate($result['reason'] ?? 'that_payout_could_not_be_sent_again'));

        return back();
    }

    public function reject(int $id): RedirectResponse
    {
        $request = $this->findOpen($id);
        if (!$request) {
            return back();
        }

        $done = $this->payoutService->releaseReservation(
            request: $request,
            toStatus: VendorPayoutRequest::STATUS_REJECTED,
            note: request('note'),
        );

        $done ? ToastMagic::success(translate('payout_rejected_and_funds_released'))
              : ToastMagic::error(translate('this_payout_can_no_longer_be_rejected'));

        return back();
    }

    private function findOpen(int $id): ?VendorPayoutRequest
    {
        $request = VendorPayoutRequest::find($id);
        if (!$request) {
            ToastMagic::error(translate('payout_not_found'));
            return null;
        }
        return $request;
    }

    private function statusCounts(): array
    {
        return VendorPayoutRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }
}
