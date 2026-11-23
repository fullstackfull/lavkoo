<?php

namespace App\Services\Marketplace;

use App\Models\OrderItemCommission;
use App\Models\VendorLedgerEntry;
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
    public function __construct(private readonly VendorLedger $ledger)
    {
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

                $earning = $this->earningBeingReversed(snapshot: $snapshot, orderDetailsId: $orderDetailsId);
                $currency = $earning === null ? $snapshot->currency : $earning->currency;
                $company = $this->companyOfTheEarning(earning: $earning);

                // The seller gives back the earning on the returned portion (ex-tax, capped at base)...
                $this->ledger->record(
                    sellerId: $snapshot->seller_id,
                    entryType: VendorLedgerEntry::TYPE_REFUND,
                    debit: $refundDebit,
                    status: VendorLedgerEntry::STATUS_AVAILABLE,
                    referenceType: 'refund_order_details',
                    referenceId: $refundReference ?? $orderDetailsId,
                    description: 'Refund on order line #' . $orderDetailsId,
                    currency: $currency,
                    legalEntityId: $company,
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
                        currency: $currency,
                        legalEntityId: $company,
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
     * The currency a reversal is labelled with: the one the entry it reverses carries.
     *
     * A reversal has to run in the same ledger chain as the credit it gives back, or the earning and the refund
     * of one order line are two kinds of money (E-17.2): the line is settled as payable in one chain while its
     * refund waits in another that can never be collected, so a line refunded IN FULL leaves the marketplace
     * owing the seller for it. The order's commission snapshot is not that currency. It is stamped when the
     * order is placed and falls back to `session('currency_code')`, the currency the customer was BROWSING in
     * (OrderManager::recordOrderItemCommission), so it can name a currency the earning never did; and where the
     * earning carries no label at all, the reversal must carry none either to stay in the base-currency chain
     * with it. The snapshot is the fallback for a line whose earning is not on the ledger, which is where it was
     * the only answer available.
     */
    /** The earning this reversal gives back, or null when the ledger holds none for the line. */
    private function earningBeingReversed(OrderItemCommission $snapshot, int|string $orderDetailsId): ?VendorLedgerEntry
    {
        return $this->ledger->eventAlreadyRecorded(
            sellerId: $snapshot->seller_id,
            entryType: VendorLedgerEntry::TYPE_ORDER_EARNING,
            referenceType: 'order_details',
            referenceId: $orderDetailsId,
            sellerIs: $snapshot->seller_is,
        );
    }

    /**
     * The company a reversal belongs to: the one that holds the EARNING it reverses, never whatever the registry answers today.
     *
     * A refund leaves the company that took the money. Resolving the company afresh would send it out of a different one the day
     * the registry is edited, which is money leaving a company that never received it. Where the earning names no company -- every
     * entry before the backfill reaches it, and every entry while the column is dormant -- the reversal names none either, so it
     * stays in the same chain the earning is in.
     */
    private function companyOfTheEarning(?VendorLedgerEntry $earning): ?int
    {
        return $earning === null ? null : $this->ledger->companyOfEntry(entryId: $earning->id);
    }
}
