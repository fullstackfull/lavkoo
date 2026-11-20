<?php

namespace App\Services\Marketplace;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
use App\Services\Storefront\OrderLegalEntitySnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Unwinds the marketplace's side of a refund (Phase 3, Stage B).
 *
 * What the refund path did before this, in full:
 *
 * ```php
 * $this->vendorWalletRepo->updateWhere(
 *     params: ['seller_id' => $order['seller_id']],
 *     data: ['total_earning' => $sellerWallet['total_earning'] - $refund['amount']]
 * );
 * ```
 *
 * It subtracts the refund from a mutable total and says nothing about the commission. So **the
 * marketplace keeps its cut of a sale it gave back** — the seller loses the full amount while the
 * commission charged on it stays charged. On any marketplace that is a real defect, and on one with
 * high return rates it is a systematic overcharge of every seller.
 *
 * This reverses both sides, proportionally, as new ledger entries:
 *
 *   - a `refund` **debit** for the amount returned to the customer, and
 *   - a `commission_charge` **credit** giving back the commission on the refunded portion.
 *
 * Nothing is edited. The original earning and commission entries stay exactly as they were, because
 * the question a ledger exists to answer — what did this look like at the time — must survive a
 * refund. The snapshot's `reversed_amount` is the one field that moves, and only upward, so a
 * partial refund followed by another can never reverse more commission than was charged.
 */
class RefundReversalService
{
    public function __construct(
        private readonly VendorLedger $ledger,
        private readonly OrderLegalEntitySnapshot $orderCompany,
    ) {
    }

    /**
     * Reverse a refund of `$refundedAmount` against one order line.
     *
     * Wrapped whole: refund accounting must never be the reason a refund fails to reach a customer.
     * The worst outcome of a failure here is a reversal that reconciliation surfaces, which is far
     * better than a customer who was not refunded.
     *
     * @return array{refund_debit: float, commission_credit: float}|null
     */
    public function reverseForOrderLine(int|string $orderDetailsId, float $refundedAmount, int|string|null $refundReference = null): ?array
    {
        if (!Schema::hasTable('order_item_commissions') || !Schema::hasTable('vendor_ledger_entries')) {
            return null;
        }

        $refundedAmount = round(max(0, $refundedAmount), 4);
        if ($refundedAmount <= 0) {
            return null;
        }

        try {
            return DB::transaction(function () use ($orderDetailsId, $refundedAmount, $refundReference) {
                $snapshot = OrderItemCommission::where('order_details_id', $orderDetailsId)->lockForUpdate()->first();

                if (!$snapshot || $snapshot->seller_is !== 'seller' || !$snapshot->seller_id) {
                    return null;
                }

                // Reverse the seller's side proportionally to what is being returned, measured against
                // the **ex-tax commissionable base** the seller was actually credited — NOT the
                // tax-inclusive amount the customer is refunded. Debiting the customer refund (which
                // carries tax the seller never earned) pushed the seller's balance below what they were
                // paid: a full-line refund of base+tax against a base credit left the seller short by
                // exactly the tax on every return.
                //
                // One `$share` drives both sides, so the net movement is always -(base - commission)·share
                // = -seller_net·share, and it is capped by how much of the line is still un-reversed so
                // repeated partial refunds can never reverse more than the whole line.
                $base = (float) $snapshot->commissionable_amount;
                $commission = (float) $snapshot->commission_amount;
                $alreadyReversed = (float) $snapshot->reversed_amount;

                $remainingShare = $commission > 0
                    ? max(0.0, 1.0 - min(1.0, $alreadyReversed / $commission))
                    : ($base > 0 ? 1.0 : 0.0);
                $requestedShare = $base > 0 ? min(1.0, $refundedAmount / $base) : 0.0;
                $share = min($requestedShare, $remainingShare);

                $refundDebit = round($base * $share, 4);
                $commissionCredit = round($commission * $share, 4);

                if ($refundDebit <= 0 && $commissionCredit <= 0) {
                    return ['refund_debit' => 0.0, 'commission_credit' => 0.0];
                }

                // Whose money comes back: the company of the EARNING being reversed, not whatever the
                // registry answers today. A reversal that landed in another company would take that
                // company's balance negative by the amount and leave the earning's over-credited by
                // it, so a company is never resolved afresh here: the earning's own entry is read,
                // and an earning that names none gives back a reversal that names none too. Only when
                // this line has no earning on the ledger at all is the order's frozen stamp read.
                $legalEntityId = $this->companyOfTheEarningOf(snapshot: $snapshot, orderDetailsId: $orderDetailsId);

                // The seller gives back the earning on the returned portion (ex-tax, capped at base)...
                $this->ledger->record(
                    sellerId: $snapshot->seller_id,
                    entryType: VendorLedgerEntry::TYPE_REFUND,
                    debit: $refundDebit,
                    status: VendorLedgerEntry::STATUS_AVAILABLE,
                    referenceType: 'refund_order_details',
                    referenceId: $refundReference ?? $orderDetailsId,
                    description: 'Refund on order line #' . $orderDetailsId,
                    currency: $snapshot->currency,
                    legalEntityId: $legalEntityId,
                );

                // ...and the marketplace gives back its cut of it.
                if ($commissionCredit > 0) {
                    $this->ledger->record(
                        sellerId: $snapshot->seller_id,
                        entryType: VendorLedgerEntry::TYPE_RETURN_ADJUSTMENT,
                        credit: $commissionCredit,
                        status: VendorLedgerEntry::STATUS_AVAILABLE,
                        referenceType: 'refund_order_details',
                        referenceId: $refundReference ?? $orderDetailsId,
                        description: 'Commission reversed on refund of line #' . $orderDetailsId,
                        currency: $snapshot->currency,
                        legalEntityId: $legalEntityId,
                    );

                    $snapshot->forceFill([
                        'reversed_amount' => round($alreadyReversed + $commissionCredit, 4),
                    ])->save();
                }

                return ['refund_debit' => $refundDebit, 'commission_credit' => $commissionCredit];
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Log::warning('Refund reversal failed for order line ' . $orderDetailsId . ': ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * The company a reversal of this line belongs to (GCC-13, D1-03): the company of the earning it reverses.
     *
     * Read off the earning entry itself, so an earning that names no company is reversed by entries that name none either. The order's own frozen
     * stamp is read only when the line has no earning on the ledger, which is the one case where there is no earning to agree with.
     */
    private function companyOfTheEarningOf(OrderItemCommission $snapshot, int|string $orderDetailsId): ?int
    {
        if (!$this->ledger->recordsCompanies()) {
            return null;
        }

        $earning = $this->ledger->entryOfEvent(
            sellerId: $snapshot->seller_id,
            entryType: VendorLedgerEntry::TYPE_ORDER_EARNING,
            referenceType: 'order_details',
            referenceId: $orderDetailsId,
            sellerIs: $snapshot->seller_is,
        );

        return $earning === null
            ? $this->orderCompany->companyOf(orderId: $snapshot->order_id)
            : $earning->legal_entity_id;
    }
}
