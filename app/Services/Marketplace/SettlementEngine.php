<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draws a settlement period across a vendor's ledger (Phase 3, Stage B).
 *
 * It sums ledger entries. It does **not** re-derive anything from orders — the ledger already holds
 * every financial event with its own running balance, and re-deriving would create a second source
 * of truth that can disagree with the first. That is the whole reason the ledger was built before
 * this.
 *
 * ## The claim is what makes it safe
 *
 * Calculating a settlement *claims* its entries by stamping `settlement_id` on them. An entry can
 * belong to exactly one settlement, so the same earning can never be paid twice — and that is
 * enforced by the data rather than by remembering to check. Only unclaimed, `available` entries are
 * eligible: money still inside the return window is not money a seller can be paid.
 *
 * ## Approval freezes it
 *
 * After approval the totals stop moving. A correction is a new ledger entry that the *next*
 * settlement picks up, never an edit to an approved one, because a restated settlement cannot be
 * reconciled against the payout already made from it. `cancel()` therefore releases the claim and
 * refuses to touch anything approved or paid.
 */
class SettlementEngine
{
    /**
     * Ledger entries a settlement must never draw on: the payout channel's own bookkeeping.
     *
     * A payout reservation is a `reserved` debit (already invisible to the `available` filter), but a
     * *released* reservation writes a compensating `available` credit (reference_type `payout_release`).
     * That credit is real money on the running balance — the seller can request another payout for it,
     * or have the underlying earning settled — but it is NOT a second earning. Left in the settleable
     * set it double-counts: the earning settles once on its own, and the release credit settles again,
     * so a seller who merely requests and cancels a payout inflates their next settlement by the
     * cancelled amount (unbounded if repeated). The earning the release restores is always still on the
     * ledger as its own `available` credit, so excluding the payout machinery never strands money — it
     * only stops the settlement counting a reversal as new income.
     */
    private const NON_SETTLEABLE_REFERENCE_TYPES = ['payout_reserve', 'payout_release'];

    /**
     * Build a settlement for one vendor from their unclaimed, available ledger entries.
     *
     * Returns null when there is nothing to settle, which is a normal outcome on a schedule and not
     * an error — a vendor with no sales this week simply has no settlement.
     */
    public function calculate(
        int|string $sellerId,
        string $sellerIs = 'seller',
        ?\DateTimeInterface $periodStart = null,
        ?\DateTimeInterface $periodEnd = null,
    ): ?VendorSettlement {
        if (!Schema::hasTable('vendor_settlements') || !Schema::hasTable('vendor_ledger_entries')) {
            return null;
        }

        return DB::transaction(function () use ($sellerId, $sellerIs, $periodStart, $periodEnd) {
            $query = VendorLedgerEntry::query()
                ->forSeller($sellerId, $sellerIs)
                ->whereNull('settlement_id')
                ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
                ->where(fn ($q) => $this->excludePayoutMachinery($q))
                ->when($periodStart, fn ($q) => $q->where('created_at', '>=', $periodStart))
                ->when($periodEnd, fn ($q) => $q->where('created_at', '<=', $periodEnd));

            // Locked for the transaction so a second run cannot claim the same rows.
            $entries = (clone $query)->lockForUpdate()->orderBy('id')->get();

            if ($entries->isEmpty()) {
                return null;
            }

            $credits = round((float) $entries->sum('credit'), 4);
            $debits = round((float) $entries->sum('debit'), 4);
            $net = round($credits - $debits, 4);

            // What the ledger said before this period's earliest claimed entry. Taken from the
            // entry itself rather than re-summed, which is why balance_after is stored.
            $first = $entries->first();
            $opening = round(((float) $first->balance_after) - ((float) $first->credit) + ((float) $first->debit), 4);

            $settlement = VendorSettlement::create([
                'reference' => $this->nextReference(),
                'seller_id' => $sellerId,
                'seller_is' => $sellerIs,
                'period_start' => $periodStart ?? $entries->min('created_at'),
                'period_end' => $periodEnd ?? $entries->max('created_at'),
                'opening_balance' => $opening,
                'total_credits' => $credits,
                'total_debits' => $debits,
                'net_amount' => $net,
                'closing_balance' => round((float) $entries->last()->balance_after, 4),
                'entry_count' => $entries->count(),
                'currency' => $first->currency,
                'status' => VendorSettlement::STATUS_CALCULATED,
                'calculated_at' => now(),
            ]);

            // Claim them. Stamping the id is what stops a second settlement taking the same money.
            VendorLedgerEntry::whereIn('id', $entries->pluck('id'))
                ->update(['settlement_id' => $settlement->id, 'updated_at' => now()]);

            return $settlement->fresh();
        }, attempts: 3);
    }

    /**
     * Approve a settlement, freezing its totals.
     *
     * Refuses anything already locked, so approving twice cannot re-stamp who approved it.
     */
    public function approve(VendorSettlement $settlement, int|string|null $approvedBy = null): bool
    {
        if ($settlement->isLocked() || $settlement->status === VendorSettlement::STATUS_CANCELLED) {
            return false;
        }

        $settlement->forceFill([
            'status' => VendorSettlement::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ])->save();

        app(\App\Services\AuditLogger::class)->record(
            action: 'settlement.approved',
            subject: $settlement,
            context: ['reference' => $settlement->reference, 'net_amount' => $settlement->net_amount, 'seller_id' => $settlement->seller_id],
        );

        return true;
    }

    /**
     * Mark an approved settlement paid, and move its entries to `paid` on the ledger.
     *
     * Only from approved: paying something nobody approved is exactly the control the maker-checker
     * split exists to enforce.
     */
    public function markPaid(VendorSettlement $settlement, ?string $payoutReference = null, int|string|null $paidBy = null): bool
    {
        if ($settlement->status !== VendorSettlement::STATUS_APPROVED) {
            return false;
        }

        return DB::transaction(function () use ($settlement, $payoutReference, $paidBy) {
            $settlement->forceFill(array_merge([
                'status' => VendorSettlement::STATUS_PAID,
                'paid_at' => now(),
                'payout_reference' => $payoutReference,
            ], Schema::hasColumn('vendor_settlements', 'paid_by') ? ['paid_by' => $paidBy] : []))->save();

            VendorLedgerEntry::where('settlement_id', $settlement->id)
                ->update(['status' => VendorLedgerEntry::STATUS_PAID, 'updated_at' => now()]);

            app(\App\Services\AuditLogger::class)->record(
                action: 'settlement.paid',
                subject: $settlement,
                context: ['reference' => $settlement->reference, 'net_amount' => $settlement->net_amount, 'payout_reference' => $payoutReference],
            );

            return true;
        });
    }

    /**
     * Cancel a settlement that has not been approved, releasing its claim so the entries return to
     * the pool for the next run. Approved and paid settlements are never unwound this way — a
     * correction is a new ledger entry.
     */
    public function cancel(VendorSettlement $settlement, ?string $note = null): bool
    {
        if ($settlement->isLocked()) {
            return false;
        }

        return DB::transaction(function () use ($settlement, $note) {
            VendorLedgerEntry::where('settlement_id', $settlement->id)
                ->update(['settlement_id' => null, 'updated_at' => now()]);

            $settlement->forceFill([
                'status' => VendorSettlement::STATUS_CANCELLED,
                'note' => $note,
            ])->save();

            return true;
        });
    }

    /**
     * Run a settlement for every vendor holding unclaimed available entries.
     *
     * @return array<int, VendorSettlement>
     */
    public function calculateForAll(?\DateTimeInterface $periodStart = null, ?\DateTimeInterface $periodEnd = null): array
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return [];
        }

        $sellers = VendorLedgerEntry::query()
            ->whereNull('settlement_id')
            ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
            ->where(fn ($q) => $this->excludePayoutMachinery($q))
            ->select('seller_id', 'seller_is')
            ->distinct()
            ->get();

        $settlements = [];
        foreach ($sellers as $seller) {
            $settlement = $this->calculate($seller->seller_id, $seller->seller_is, $periodStart, $periodEnd);
            if ($settlement) {
                $settlements[] = $settlement;
            }
        }

        return $settlements;
    }

    /**
     * Constrain a query to entries that are NOT payout bookkeeping.
     *
     * NULL-safe on purpose: `reference_type NOT IN (...)` is UNKNOWN (and so excludes the row) when
     * reference_type IS NULL, which would silently drop legitimate settleable entries that carry no
     * reference (a manual bonus/adjustment). Keeping the NULL branch means only the two payout
     * reference_types are excluded and everything else — reference or not — still settles.
     */
    private function excludePayoutMachinery($query): void
    {
        $query->whereNull('reference_type')
            ->orWhereNotIn('reference_type', self::NON_SETTLEABLE_REFERENCE_TYPES);
    }

    /**
     * A reference that stays unique under concurrency. The random suffix is what makes two runs in
     * the same second safe; the date prefix is what makes it readable in correspondence.
     */
    private function nextReference(): string
    {
        do {
            $reference = 'STL-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while (VendorSettlement::where('reference', $reference)->exists());

        return $reference;
    }
}
