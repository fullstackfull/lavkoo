<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Platform\PolicyRegistry;
use App\Services\Platform\Policy;
use App\Models\BusinessSetting;
use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use App\Services\Marketplace\SettlementEngine;
use App\Services\Marketplace\VendorLedger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's view of the financial core (Phase 3, Stage B).
 *
 * Everything below this was reachable only through the service layer and a console command. A
 * financial core the operator cannot see or act on is worth much less than one they can, so this is
 * the surface: what is waiting to be settled, what each settlement contains, and the two actions
 * that move money — approve, then pay.
 *
 * The controller holds no financial logic of its own. Every calculation, claim and state change goes
 * through SettlementEngine, so the rules the tests cover are the same rules the buttons trigger —
 * there is no second implementation behind the UI to drift from the first.
 */
class SettlementController extends BaseController
{
    public function __construct(
        private readonly SettlementEngine $engine,
        private readonly VendorLedger $ledger,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $status = $request?->get('status');

        $settlements = VendorSettlement::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request?->get('seller_id'), fn ($q) => $q->where('seller_id', $request->get('seller_id')))
            ->latest('id')
            ->paginate(20)
            ->appends($request?->all() ?? []);

        return view('admin-views.marketplace.settlements.index', [
            'settlements' => $settlements,
            'status' => $status,
            'counts' => $this->statusCounts(),
            'waiting' => $this->waitingToSettle(),
            'makerChecker' => (bool) getWebConfig(name: 'settlement_maker_checker'),
            // The marketplace's payment terms, on the page that owns settlement. They sat as a
            // constant, a settings key nothing wrote and two values with no home at all, so
            // changing what the platform promises its sellers about when they are paid was a
            // deploy.
            'terms' => app(Policy::class)->all('finance'),
            'termFields' => PolicyRegistry::GROUPS['finance']['policies'],
        ]);
    }

    /**
     * Switch the approver≠payer separation-of-duties control on or off. Off by default so a
     * single-admin shop can approve and pay; on, the two actions must be performed by different admins.
     */
    public function toggleMakerChecker(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('status');
        BusinessSetting::updateOrCreate(['type' => 'settlement_maker_checker'], ['value' => $enabled ? '1' : '0']);
        clearWebConfigCacheKeys();

        ToastMagic::success($enabled ? translate('separation_of_duties_enabled') : translate('separation_of_duties_disabled'));

        return back();
    }

    /** The payment-terms block on this page, saved through the policy service so it is audited. */
    public function updateTerms(Request $request, Policy $policy): RedirectResponse
    {
        $policy->save($request->validate($policy->rules('finance')));

        ToastMagic::success(translate('the_payment_terms_were_saved'));

        return back();
    }

    public function show(int $id): View|RedirectResponse
    {
        $settlement = VendorSettlement::find($id);

        if (!$settlement) {
            ToastMagic::error(translate('settlement_not_found'));

            return redirect()->route('admin.marketplace.settlements.index');
        }

        return view('admin-views.marketplace.settlements.show', [
            'settlement' => $settlement,
            'entries' => $settlement->entries()->orderBy('id')->get(),
            'balances' => $this->ledger->balances($settlement->seller_id, $settlement->seller_is),
        ]);
    }

    /** Calculate settlements for everyone with money waiting. Never pays anybody. */
    public function calculate(Request $request): RedirectResponse
    {
        $settlements = $request->get('seller_id')
            ? array_filter([$this->engine->calculate($request->get('seller_id'))])
            : $this->engine->calculateForAll();

        if (!$settlements) {
            ToastMagic::error(translate('nothing_is_waiting_to_be_settled'));

            return back();
        }

        ToastMagic::success(count($settlements) . ' ' . translate('settlement(s)_calculated_and_awaiting_approval'));

        return back();
    }

    public function approve(int $id): RedirectResponse
    {
        $settlement = VendorSettlement::findOrFail($id);

        // The engine refuses anything already locked, so approving twice cannot re-stamp who did it.
        if (!$this->engine->approve($settlement, approvedBy: auth('admin')->id())) {
            ToastMagic::error(translate('this_settlement_can_no_longer_be_approved'));

            return back();
        }

        ToastMagic::success(translate('settlement_approved'));

        return back();
    }

    /**
     * Mark a settlement paid. The engine refuses anything not approved — paying what nobody approved
     * is exactly the control the maker-checker split exists to enforce.
     */
    public function markPaid(Request $request, int $id): RedirectResponse
    {
        $settlement = VendorSettlement::findOrFail($id);
        $adminId = auth('admin')->id();

        // Separation of duties (opt-in via the settlement_maker_checker setting, off by default so a
        // single-admin shop is unaffected): the admin who approved a settlement may not also mark it
        // paid — the maker and the checker must be different people.
        if (getWebConfig(name: 'settlement_maker_checker')
            && $settlement->approved_by !== null
            && (int) $settlement->approved_by === (int) $adminId) {
            ToastMagic::error(translate('the_admin_who_approved_a_settlement_cannot_also_mark_it_paid'));

            return back();
        }

        if (!$this->engine->markPaid($settlement, payoutReference: $request->get('payout_reference'), paidBy: $adminId)) {
            ToastMagic::error(translate('only_an_approved_settlement_can_be_marked_paid'));

            return back();
        }

        ToastMagic::success(translate('settlement_marked_paid'));

        return back();
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $settlement = VendorSettlement::findOrFail($id);

        if (!$this->engine->cancel($settlement, note: $request->get('note'))) {
            ToastMagic::error(translate('an_approved_or_paid_settlement_cannot_be_cancelled'));

            return back();
        }

        ToastMagic::success(translate('settlement_cancelled_and_its_entries_released'));

        return back();
    }

    /** The vendor's ledger, which is where every figure above comes from. */
    public function ledger(Request $request, int $sellerId): View
    {
        return view('admin-views.marketplace.settlements.ledger', [
            'sellerId' => $sellerId,
            'balances' => $this->ledger->balances($sellerId),
            'entries' => VendorLedgerEntry::query()
                ->forSeller($sellerId)
                ->when($request->get('entry_type'), fn ($q) => $q->where('entry_type', $request->get('entry_type')))
                ->when($request->get('status'), fn ($q) => $q->where('status', $request->get('status')))
                ->latest('id')
                ->paginate(30)
                ->appends($request->all()),
        ]);
    }

    /** @return array<string, int> */
    private function statusCounts(): array
    {
        if (!Schema::hasTable('vendor_settlements')) {
            return [];
        }

        return VendorSettlement::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status')->toArray();
    }

    /**
     * What is eligible right now but not yet drawn into a settlement — the number that tells the
     * operator whether pressing Calculate will do anything.
     *
     * @return array{vendors: int, net: float}
     */
    private function waitingToSettle(): array
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return ['vendors' => 0, 'net' => 0.0];
        }

        $rows = VendorLedgerEntry::query()
            ->whereNull('settlement_id')
            ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
            ->selectRaw('seller_id, SUM(credit) - SUM(debit) as net')
            ->groupBy('seller_id')
            ->get();

        return ['vendors' => $rows->count(), 'net' => round((float) $rows->sum('net'), 2)];
    }
}
