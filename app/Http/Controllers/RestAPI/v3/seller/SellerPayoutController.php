<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\Platform\Policy;
use App\Models\Currency;
use App\Models\VendorPayoutRequest;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\PayoutService;
use App\Services\Marketplace\SellerVerificationService;
use App\Services\Marketplace\VendorLedger;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Ledger-based payouts over the v3 seller API — the mobile twin of the web
 * Vendor\Marketplace\PayoutController. A request can only ask against the
 * token's own withdrawable balance; the service reserves it atomically.
 */
class SellerPayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService             $payouts,
        private readonly VendorLedger              $ledger,
        private readonly SellerVerificationService $verification,
    )
    {
    }

    #[ApiDoc(
        summary: 'Ledger balances, withdrawable amount and the seller\'s payout requests',
        description: 'Also reports whether a bank-change cooling period is in force and whether KYC currently '
            . 'allows a payout, so a client can explain a refusal before the seller submits one.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'wallet',
    )]
    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $limit = (int) ($request['limit'] ?? 10);
        $offset = (int) ($request['offset'] ?? 1);

        $payoutRequests = VendorPayoutRequest::where(['seller_id' => $sellerId, 'seller_is' => 'seller'])
            ->latest('id')
            ->paginate($limit, ['*'], 'page', $offset);

        // Same rule as the web form: offer a payout currency only on multi-currency stores.
        $payoutCurrencies = collect();
        if (getWebConfig(name: 'currency_model') === 'multi_currency' && Schema::hasTable('currencies')) {
            $payoutCurrencies = Currency::orderBy('code')->pluck('code');
        }

        return response()->json([
            'balances' => $this->ledger->balances($sellerId),
            'payout_currencies' => $payoutCurrencies,
            'withdrawable' => $this->ledger->withdrawable($sellerId),
            'in_cooling_period' => $this->payouts->isInCoolingPeriod($sellerId),
            'payout_eligible' => $this->verification->isPayoutEligible($sellerId),
            'total_size' => $payoutRequests->total(),
            'limit' => $limit,
            'offset' => $offset,
            'requests' => $payoutRequests->items(),
        ], 200);
    }

    #[ApiDoc(
        summary: 'Request a payout against the withdrawable ledger balance',
        description: 'Reserves the amount atomically. Refused with 403 when the amount exceeds the withdrawable '
            . 'balance, KYC is required but unmet, or bank details changed inside the cooling period. Large '
            . 'requests open a dual-control approval when a threshold is configured.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        emits: ['payout_requested'],
        group: 'wallet',
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . max(0.01, app(Policy::class)->float('payout_minimum_amount')),
            'method' => 'nullable|string|max:40',
            'payout_currency' => 'nullable|string|max:10',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        // The seller id comes from the token, never the request — a seller cannot
        // request a payout against another seller's balance.
        $result = $this->payouts->requestPayout(
            sellerId: $request->seller->id,
            amount: (float) $request['amount'],
            method: $request['method'] ?? 'bank_transfer',
            methodDetails: (array) ($request['method_details'] ?? []),
            payoutCurrency: $request['payout_currency'] ?? null,
        );

        if (!$result['ok']) {
            return response()->json(['message' => translate($result['reason'])], 403);
        }

        // Dual control on large payouts (spec item 83): above the marketplace's own threshold the
        // request is routed through the maker-checker approval engine and surfaces in the admin
        // approvals inbox. Opt-in — at zero nothing opens and the ordinary review path applies.
        $this->payouts->openApprovalIfLarge($result['request']);

        return response()->json([
            'status' => true,
            'message' => translate('payout_requested'),
            'request' => $result['request'],
        ], 200);
    }

    /** A seller may withdraw a request they made, as long as it has not been paid. */
    #[ApiDoc(
        summary: 'Cancel a payout request that has not been paid yet',
        description: 'Releases the reservation back to the available balance. Refused with 403 once the request '
            . 'is no longer open, and scoped to the token\'s own requests.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        destructive: true,
        group: 'wallet',
    )]
    public function cancel(Request $request, int $id): JsonResponse
    {
        $payout = VendorPayoutRequest::where(['id' => $id, 'seller_id' => $request->seller->id])->first();

        if (!$payout || !$payout->isOpen()) {
            return response()->json(['message' => translate('this_payout_can_no_longer_be_cancelled')], 403);
        }

        $this->payouts->releaseReservation($payout, VendorPayoutRequest::STATUS_REJECTED, note: 'cancelled by seller');

        return response()->json(['status' => true, 'message' => translate('payout_request_cancelled')], 200);
    }
}
