<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Platform\Policy;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\VendorLedger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The seller's own payout requests, over the ledger (Phase 3, Stage B).
 *
 * A seller sees what they can actually withdraw — the ledger's withdrawable balance, net of anything
 * already in flight — and requests against it. All the rules live in PayoutService; this controller
 * only reaches auth('seller') for identity and never trusts a seller-supplied id.
 */
class PayoutController extends BaseController
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly VendorLedger $ledger,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellerId = auth('seller')->id();

        // Offer a payout currency only when the store runs multi-currency; single-currency stores keep
        // the simple form (the payout is in the base currency).
        $payoutCurrencies = collect();
        if (getWebConfig(name: 'currency_model') === 'multi_currency' && \Illuminate\Support\Facades\Schema::hasTable('currencies')) {
            $payoutCurrencies = \App\Models\Currency::orderBy('code')->pluck('code');
        }

        return view('vendor-views.marketplace.payouts', [
            'balances' => $this->ledger->balances($sellerId),
            'withdrawable' => $this->ledger->withdrawable($sellerId),
            'inCoolingPeriod' => $this->payouts->isInCoolingPeriod($sellerId),
            'payoutCurrencies' => $payoutCurrencies,
            'requests' => VendorPayoutRequest::where('seller_id', $sellerId)
                ->where('seller_is', 'seller')
                ->latest('id')
                ->paginate(15),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:' . max(0.01, app(Policy::class)->float('payout_minimum_amount')),
            'method' => 'nullable|string|max:40',
            'payout_currency' => 'nullable|string|max:10',
        ]);

        // The seller id comes from the session, never the request — a seller cannot request a payout
        // against another seller's balance.
        $result = $this->payouts->requestPayout(
            sellerId: auth('seller')->id(),
            amount: (float) $request->get('amount'),
            method: $request->get('method', 'bank_transfer'),
            methodDetails: $request->get('method_details', []),
            payoutCurrency: $request->get('payout_currency'),
        );

        if (!$result['ok']) {
            ToastMagic::error(translate($result['reason']));

            return back();
        }

        // Dual control on large payouts (spec item 83): above the marketplace's own threshold the
        // request is routed through the maker-checker approval engine and surfaces in the admin
        // approvals inbox. Opt-in — at zero nothing opens and the ordinary review path applies.
        $this->payouts->openApprovalIfLarge($result['request']);

        ToastMagic::success(translate('payout_requested') . ' — ' . $result['request']->reference);

        return back();
    }

    /** A seller may withdraw a request they made, as long as it has not been paid. */
    public function cancel(int $id): RedirectResponse
    {
        $payout = VendorPayoutRequest::where(['id' => $id, 'seller_id' => auth('seller')->id()])->first();

        if (!$payout || !$payout->isOpen()) {
            ToastMagic::error(translate('this_payout_can_no_longer_be_cancelled'));

            return back();
        }

        // Releasing puts the reserved amount back to available; a cancelled request is not a paid one.
        $this->payouts->releaseReservation($payout, VendorPayoutRequest::STATUS_REJECTED, note: 'cancelled by seller');
        ToastMagic::success(translate('payout_request_cancelled'));

        return back();
    }
}
