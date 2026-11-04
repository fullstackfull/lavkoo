<?php

namespace App\Services\Marketplace;

use App\Models\VendorBankChangeLog;
use App\Services\Platform\Policy;
use App\Models\VendorLedgerEntry;
use App\Models\VendorPayoutRequest;
use App\Services\Marketplace\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller-initiated payouts over the ledger, with the security controls the phase asks for
 * (Phase 3, Stage B).
 *
 * The existing `withdraw_requests` flow runs over `seller_wallets` and validates nothing against a
 * real available balance. This runs over the ledger:
 *
 *   - A payout can only ask for what the ledger reports as **available**. Money still inside the
 *     return window (`pending`), or already reserved for another payout, is not requestable.
 *   - Requesting **reserves** the amount as a ledger debit with status `reserved`, so the same money
 *     cannot be requested twice, and a settlement cannot pay it out from under a live request.
 *   - Rejecting or failing a request **releases** the reservation — a credit that puts the money
 *     back — so nothing is lost when a payout does not go through.
 *   - Paying converts the reservation into a `paid` debit.
 *
 * ## The bank-change cooling period
 *
 * The control the specification names: changing a seller's bank details is logged and opens a
 * cooling window during which payouts are refused. It is the standard defence against an account
 * takeover that immediately redirects the money — the delay gives the real seller time to notice a
 * payout they did not make. `requestPayout()` refuses while the window is open.
 */
class PayoutService
{
    public function __construct(private readonly VendorLedger $ledger)
    {
    }

    /**
     * Create a payout request, reserving the amount against the seller's available balance.
     *
     * @return array{ok: bool, reason?: string, request?: VendorPayoutRequest}
     */
    public function requestPayout(int|string $sellerId, float $amount, string $method = 'bank_transfer', array $methodDetails = [], string $sellerIs = 'seller', ?string $payoutCurrency = null): array
    {
        if (!Schema::hasTable('vendor_payout_requests')) {
            return ['ok' => false, 'reason' => 'payouts_unavailable'];
        }

        $amount = round($amount, 4);
        if ($amount <= 0) {
            return ['ok' => false, 'reason' => 'amount_must_be_positive'];
        }

        if ($this->isInCoolingPeriod($sellerId)) {
            return ['ok' => false, 'reason' => 'bank_details_recently_changed_payouts_are_temporarily_paused'];
        }

        // KYC gate (Phase 3, Stage A). Off unless an admin turns on `require_kyc_for_payout`, so this
        // is a no-op for every current install; when on, an unverified seller cannot withdraw. Only
        // real sellers are subject to it — admin/in-house withdrawals are not KYC-verified entities.
        if ($sellerIs === 'seller'
            && !app(SellerVerificationService::class)->isPayoutEligible($sellerId)) {
            return ['ok' => false, 'reason' => 'kyc_verification_required'];
        }

        return DB::transaction(function () use ($sellerId, $sellerIs, $amount, $method, $methodDetails, $payoutCurrency) {
            // Withdrawable, not the raw available bucket: a payout already in flight is held as a
            // reserved debit, and withdrawable() nets those holds out. Read inside the transaction;
            // the reserve written below is what stops two concurrent requests both passing here.
            $withdrawable = $this->ledger->withdrawable($sellerId, $sellerIs);

            if ($amount > $withdrawable) {
                return ['ok' => false, 'reason' => 'amount_exceeds_available_balance'];
            }

            // Reserve it: a debit that moves the money out of "available" into "reserved". Recording
            // it through the ledger keeps the running balance correct and the audit trail intact.
            $reserve = $this->ledger->record(
                sellerId: $sellerId,
                entryType: VendorLedgerEntry::TYPE_PAYOUT,
                debit: $amount,
                status: VendorLedgerEntry::STATUS_RESERVED,
                referenceType: 'payout_reserve',
                referenceId: 'pending-' . uniqid(),
                description: 'Reserved for payout request',
                sellerIs: $sellerIs,
            );

            // Multi-currency: the reserve stays in the ledger base currency (the unit of account); we
            // additionally snapshot how much to pay in the seller's chosen currency and the rate used,
            // so the actual bank transfer amount is fixed at request time and the ledger math is untouched.
            $baseCurrency = $reserve?->currency;
            $conversion = null;
            if ($payoutCurrency && $baseCurrency && strtoupper($payoutCurrency) !== strtoupper($baseCurrency)) {
                $conversion = app(ExchangeRateService::class)->convert($amount, $baseCurrency, $payoutCurrency);
            }

            $request = VendorPayoutRequest::create([
                'reference' => $this->nextReference(),
                'seller_id' => $sellerId,
                'seller_is' => $sellerIs,
                'amount' => $amount,
                'currency' => $baseCurrency,
                'payout_currency' => $conversion['to'] ?? null,
                'payout_amount' => $conversion['amount'] ?? null,
                'exchange_rate' => $conversion['rate'] ?? null,
                'status' => VendorPayoutRequest::STATUS_REQUESTED,
                'method' => $method,
                'method_details' => $methodDetails,
                'reserve_entry_id' => $reserve?->id,
            ]);

            // A seller-initiated request had no trace of its own: the audit log only ever saw an
            // admin's later approve/pay. Recorded here rather than in the controllers so the vendor
            // panel and the seller app both produce it.
            app(\App\Services\AuditLogger::class)->record(
                action: 'payout.requested',
                subject: $request,
                context: ['reference' => $request->reference, 'amount' => $amount, 'method' => $method],
            );

            try {
                app(\App\Services\Analytics\Analytics::class)->payoutRequested(
                    sellerId: (int) $sellerId,
                    reference: (string) $request->reference,
                    amount: $amount,
                    method: $method,
                );
            } catch (\Throwable) {
                // A payout that cannot be described is still a payout.
            }

            return ['ok' => true, 'request' => $request];
        }, attempts: 3);
    }

    /**
     * Open a dual-control approval for a payout through the reusable engine, when the amount is large
     * enough to warrant it (spec item 83: maker-checker on large payouts).
     *
     * This is the payout workflow *using* the general engine rather than growing its own second copy
     * of approval logic. It is opt-in: with no threshold configured it opens nothing and the payout
     * follows the ordinary review path, so existing behaviour is unchanged. Above the threshold it
     * opens an ApprovalRequest requiring two approvers, and the admin actions it from the approvals
     * inbox — the maker who requested the payout cannot be one of them.
     */
    public function openApprovalIfLarge(VendorPayoutRequest $request, ?float $threshold = null, int $requiredApprovals = 2): ?\App\Models\ApprovalRequest
    {
        // The marketplace's own line, not the caller's. Two call sites read a settings key that no
        // screen ever wrote, so it was 0 on every install and dual control was off everywhere.
        $threshold ??= $this->dualControlAmount();

        if ($threshold <= 0 || $request->amount < $threshold) {
            return null;
        }

        return app(\App\Services\ApprovalEngine::class)->open(
            workflow: 'payout',
            subject: $request,
            amount: $request->amount,
            requiredApprovals: $requiredApprovals,
            requestedBy: $request->seller_id,
            requestedByType: 'seller',
            payload: ['payout_reference' => $request->reference, 'seller_id' => $request->seller_id],
            note: 'Payout ' . $request->reference . ' exceeds the dual-control threshold',
            currency: $request->currency,
        );
    }

    /** Admin moves a request forward without paying: requested -> under_review -> approved. */
    public function review(VendorPayoutRequest $request, bool $approve, int|string|null $reviewer = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($request, $approve, $reviewer, $note) {
            // Lock the row and re-read status under the lock: two admins acting on the same request
            // must not both win. The caller's in-memory status is stale by the time we get here.
            $locked = VendorPayoutRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || !in_array($locked->status, [VendorPayoutRequest::STATUS_REQUESTED, VendorPayoutRequest::STATUS_UNDER_REVIEW], true)) {
                return false;
            }

            $locked->forceFill([
                'status' => $approve ? VendorPayoutRequest::STATUS_APPROVED : VendorPayoutRequest::STATUS_UNDER_REVIEW,
                'reviewed_by' => $reviewer,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            app(\App\Services\AuditLogger::class)->record(
                action: $approve ? 'payout.approved' : 'payout.under_review',
                subject: $locked,
                context: ['reference' => $locked->reference, 'amount' => $locked->amount, 'seller_id' => $locked->seller_id],
            );

            return true;
        });
    }

    /**
     * Mark an approved payout paid: the reservation becomes a real paid debit.
     *
     * Only from approved — paying what nobody approved is exactly the maker-checker control.
     */
    public function markPaid(VendorPayoutRequest $request, ?string $paymentReference = null): bool
    {
        return DB::transaction(function () use ($request, $paymentReference) {
            // Lock + re-assert status under the lock so markPaid and releaseReservation can never both
            // process the same APPROVED payout — that race would pay the seller AND release the funds
            // back to withdrawable, letting the same money be drawn twice.
            $locked = VendorPayoutRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || $locked->status !== VendorPayoutRequest::STATUS_APPROVED) {
                return false;
            }

            // Enforce dual control: if a large-payout approval was opened for this request, it must be
            // fully approved (all required approvers) before any money leaves. Without this the 2-approver
            // gate would be advisory — payable through the ordinary single-review path.
            if (!$this->dualControlSatisfied($locked)) {
                return false;
            }

            if ($locked->reserve_entry_id) {
                VendorLedgerEntry::where('id', $locked->reserve_entry_id)
                    ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);
            }

            $locked->forceFill([
                'status' => VendorPayoutRequest::STATUS_PAID,
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
                'payout_entry_id' => $locked->reserve_entry_id,
            ])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            app(\App\Services\AuditLogger::class)->record(
                action: 'payout.paid',
                subject: $locked,
                context: ['reference' => $locked->reference, 'amount' => $locked->amount, 'payment_reference' => $paymentReference],
            );

            return true;
        });
    }

    /**
     * A transfer the bank sent back.
     *
     * `STATUS_FAILED` existed on the model and the payout screen coloured its badge, and nothing in
     * the application ever set it — only bulk jobs, automation actions and webhook deliveries used
     * that constant. So a bounced transfer had no status to move to: the payout sat marked PAID with
     * money the seller never received, and the only trace was a bank statement nobody reconciled
     * against it.
     *
     * The reservation goes back to available, because a failed transfer means the money never left.
     * The seller can request again, or an admin can re-issue it with `reissue()`.
     */
    public function markFailed(VendorPayoutRequest $request, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($request, $reason) {
            $locked = VendorPayoutRequest::whereKey($request->getKey())->lockForUpdate()->first();

            // Payable states and paid ones both. A bank bounce arrives days after the payment was
            // recorded, which is exactly the case the old status set had no answer for.
            if (!$locked || !in_array($locked->status, [
                VendorPayoutRequest::STATUS_APPROVED,
                VendorPayoutRequest::STATUS_PROCESSING,
                VendorPayoutRequest::STATUS_PAID,
            ], true)) {
                return false;
            }

            if ($locked->reserve_entry_id) {
                // A credit, not a status flip on the debit: the reservation is a debit entry, and
                // relabelling it would leave the ledger without a line saying the money came back.
                // Same shape as releaseReservation, for the same reason — the history is not
                // rewritten, it is added to.
                VendorLedgerEntry::where('id', $locked->reserve_entry_id)
                    ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);

                $this->ledger->record(
                    sellerId: $locked->seller_id,
                    entryType: VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT,
                    credit: $locked->amount,
                    status: VendorLedgerEntry::STATUS_AVAILABLE,
                    referenceType: 'payout_failed',
                    referenceId: $locked->id,
                    description: 'Transfer failed for payout ' . $locked->reference,
                    sellerIs: $locked->seller_is,
                );
            }

            $locked->forceFill([
                'status' => VendorPayoutRequest::STATUS_FAILED,
                'paid_at' => null,
                'payout_entry_id' => null,
                'review_note' => trim(($locked->review_note ? $locked->review_note . "\n" : '') . ($reason ?: 'Transfer failed')),
            ])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            app(\App\Services\AuditLogger::class)->record(
                action: 'payout.failed',
                subject: $locked,
                before: ['status' => $request->getOriginal('status')],
                after: ['status' => VendorPayoutRequest::STATUS_FAILED],
                context: ['reference' => $locked->reference, 'amount' => $locked->amount, 'reason' => $reason],
            );

            return true;
        });
    }

    /**
     * Send a failed payout again.
     *
     * A fresh request rather than a resurrection of the old one: the reservation was released when
     * it failed, so the money has to be reserved again, and a payout row whose history says
     * requested → paid → failed → paid is a row nobody can audit. The new request names the one it
     * replaces.
     *
     * @return array{ok: bool, reason?: string, request?: VendorPayoutRequest}
     */
    public function reissue(VendorPayoutRequest $failed): array
    {
        if ($failed->status !== VendorPayoutRequest::STATUS_FAILED) {
            return ['ok' => false, 'reason' => 'only_a_failed_payout_can_be_sent_again'];
        }

        $result = $this->requestPayout(
            sellerId: $failed->seller_id,
            amount: (float) $failed->amount,
            method: $failed->method ?: 'bank_transfer',
            sellerIs: $failed->seller_is ?: 'seller',
            payoutCurrency: $failed->currency,
        );

        if ($result['ok'] ?? false) {
            $result['request']->forceFill([
                'review_note' => 'Re-issued after ' . $failed->reference . ' failed',
            ])->save();

            app(\App\Services\AuditLogger::class)->record(
                action: 'payout.reissued',
                subject: $result['request'],
                context: ['replaces' => $failed->reference, 'amount' => $failed->amount],
            );
        }

        return $result;
    }

    /**
     * Reject or fail a request, releasing the reservation back to available.
     *
     * The release is a credit rather than a deletion, so the ledger still shows that a payout was
     * requested and did not go through — the history is not rewritten.
     */
    public function releaseReservation(VendorPayoutRequest $request, string $toStatus = VendorPayoutRequest::STATUS_REJECTED, ?string $note = null): bool
    {
        return DB::transaction(function () use ($request, $toStatus, $note) {
            // Lock + re-assert isOpen() under the lock (see markPaid): a payout being paid concurrently
            // must not also be released, or the reserved money would be handed back after payment.
            $locked = VendorPayoutRequest::whereKey($request->getKey())->lockForUpdate()->first();
            if (!$locked || !$locked->isOpen()) {
                return false;
            }

            if ($locked->reserve_entry_id) {
                // Void the reservation so it no longer counts against "reserved"...
                VendorLedgerEntry::where('id', $locked->reserve_entry_id)
                    ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);

                // ...and put the money back as available.
                $this->ledger->record(
                    sellerId: $locked->seller_id,
                    entryType: VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT,
                    credit: $locked->amount,
                    status: VendorLedgerEntry::STATUS_AVAILABLE,
                    referenceType: 'payout_release',
                    referenceId: $locked->id,
                    description: 'Released reservation for ' . $toStatus . ' payout ' . $locked->reference,
                    sellerIs: $locked->seller_is,
                );
            }

            $locked->forceFill([
                'status' => $toStatus,
                'review_note' => $note ?? $locked->review_note,
            ])->save();
            $request->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Log a bank-detail change and open the cooling window.
     *
     * Called from wherever seller bank details are updated. The before/after snapshot is what a
     * fraud review reads to see exactly what the account was redirected from and to.
     */
    public function recordBankChange(int|string $sellerId, ?array $previous, ?array $current, int|string|null $changedBy = null, ?string $changedByType = null, ?string $ip = null): ?VendorBankChangeLog
    {
        if (!Schema::hasTable('vendor_bank_change_logs')) {
            return null;
        }

        $log = VendorBankChangeLog::create([
            'seller_id' => $sellerId,
            'previous' => $previous,
            'current' => $current,
            'cooling_until' => now()->addHours($this->bankChangeFreezeHours()),
            'changed_by' => $changedBy,
            'changed_by_type' => $changedByType,
            'ip_address' => $ip,
        ]);

        app(\App\Services\AuditLogger::class)->record(
            action: 'seller.bank_details_changed',
            subject: ['type' => 'seller', 'id' => $sellerId],
            before: $previous,
            after: $current,
            context: ['cooling_until' => $log->cooling_until?->toDateTimeString()],
        );

        return $log;
    }

    /**
     * Has the large-payout dual-control gate been satisfied for this request?
     *
     * True when no approval was opened (small payout / feature off — the ordinary single review path
     * governs), or when the opened approval reached its required approver count. False only while a
     * dual-control approval is still pending or was rejected — blocking payment until it clears.
     */
    public function dualControlSatisfied(VendorPayoutRequest $request): bool
    {
        if (!Schema::hasTable('approval_requests')) {
            return true;
        }

        $approval = \App\Models\ApprovalRequest::where([
            'workflow' => 'payout',
            'subject_type' => VendorPayoutRequest::class,
            'subject_id' => $request->id,
        ])->latest('id')->first();

        if (!$approval) {
            return true;
        }

        return $approval->status === \App\Models\ApprovalRequest::STATUS_APPROVED;
    }

    /** Is this seller inside a bank-change cooling window right now? */
    public function isInCoolingPeriod(int|string $sellerId): bool
    {
        if (!Schema::hasTable('vendor_bank_change_logs')) {
            return false;
        }

        return VendorBankChangeLog::where('seller_id', $sellerId)
            ->where('cooling_until', '>', now())
            ->exists();
    }

    private function nextReference(): string
    {
        do {
            $reference = 'PO-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (VendorPayoutRequest::where('reference', $reference)->exists());

        return $reference;
    }
    /** Above this amount a payout needs a second approver. Zero switches dual control off. */
    public function dualControlAmount(): float
    {
        return app(Policy::class)->float('payout_dual_control_amount');
    }

    /**
     * How long payouts are frozen after a seller changes where the money goes.
     *
     * The platform's anti-account-takeover hold, and the number a risk team retunes the week after
     * an incident — so it is a setting rather than the class constant it used to be.
     */
    public function bankChangeFreezeHours(): int
    {
        return app(Policy::class)->int('payout_bank_change_freeze_hours');
    }

}
