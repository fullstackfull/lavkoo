<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appends to a vendor's financial ledger, and reads balances back out of it (Phase 3, Stage B).
 *
 * The balance is never stored as a number somebody updates. It is the sum of the entries, and each
 * entry carries the balance as of itself so a statement can be printed without re-summing history.
 *
 * ## Why the lock
 *
 * `balance_after` is a read-then-write: read the previous balance, add this entry, write the result.
 * Two events landing together would otherwise both read the same previous balance and both write
 * their own — the identical lost-update that cost this project a stock decrement in Phase 2. The
 * previous entry's row is locked for the rest of the transaction, so the second event waits and
 * then reads the balance the first one just wrote.
 *
 * ## Why nothing is ever edited
 *
 * A correction is a new entry. An edited ledger cannot be reconciled against anything, and the one
 * question a ledger exists to answer — "what did this look like at the time?" — stops having an
 * answer the moment a row is rewritten.
 */
class VendorLedger
{
    /**
     * Append one entry and return it. Idempotent on (seller, type, reference): calling it twice for
     * the same order line credits once, which is what makes it safe inside a retried transaction or
     * a webhook delivered more than once.
     *
     * Refuses, and appends nothing, when that event is already on the ledger in another chain than
     * the one resolved for this call (AnEventIsAlreadyOnAnotherChain).
     */
    public function record(
        int|string $sellerId,
        string $entryType,
        float $credit = 0,
        float $debit = 0,
        string $status = VendorLedgerEntry::STATUS_PENDING,
        ?string $referenceType = null,
        int|string|null $referenceId = null,
        ?string $description = null,
        ?string $currency = null,
        string $sellerIs = 'seller',
        ?\DateTimeInterface $availableAt = null,
        ?int $legalEntityId = null,
    ): ?VendorLedgerEntry {
        if (!Schema::hasTable('vendor_ledger_entries') || !$sellerId) {
            return null;
        }

        $credit = round(max(0, $credit), 4);
        $debit = round(max(0, $debit), 4);
        $currency ??= $this->baseCurrency();
        $chain = $this->chain(sellerId: $sellerId, sellerIs: $sellerIs, legalEntityId: $legalEntityId, currency: $currency);

        return DB::transaction(function () use (
            $sellerId, $sellerIs, $entryType, $credit, $debit, $status,
            $referenceType, $referenceId, $description, $currency, $availableAt, $chain
        ) {
            // Idempotency first, and asked of the EVENT rather than of the chain this call resolved for it:
            // if this exact event is already on the ledger, return it unchanged rather than appending a
            // second one. Narrowing this lookup to the chain credited one order line twice, because the
            // chain key is resolved from the store's base currency setting, which moves: two deliveries of
            // one line either side of such a change looked in two chains, the second missed, and an event
            // that had already happened was credited again. The reference cannot tell two events apart
            // either way, since every reference_id a writer passes is the key of one row of one table of
            // this database.
            $existing = $this->eventAlreadyRecorded(
                sellerId: $sellerId,
                entryType: $entryType,
                referenceType: $referenceType,
                referenceId: $referenceId,
                sellerIs: $sellerIs,
            );

            if ($existing) {
                // Same event, another chain: the writer cannot choose between crediting it twice and losing
                // this write, so it refuses and leaves the one entry that is there (AnEventIsAlreadyOnAnotherChain).
                if (!$chain->holds($existing)) {
                    throw new AnEventIsAlreadyOnAnotherChain(entry: $existing, chain: $chain);
                }

                return $existing;
            }

            // Lock the last entry OF THIS CHAIN so a concurrent event cannot read the same balance. Two
            // events in different chains are different money and do not wait for each other; two in the
            // same chain still do, which is the whole reason the lock is here.
            $previous = $chain->narrow(VendorLedgerEntry::query())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $balanceAfter = round(((float) ($previous->balance_after ?? 0)) + $credit - $debit, 4);

            return VendorLedgerEntry::create($chain->entityForWriting() + [
                'seller_id' => $sellerId,
                'seller_is' => $sellerIs,
                'entry_type' => $entryType,
                'credit' => $credit,
                'debit' => $debit,
                'balance_after' => $balanceAfter,
                'status' => $status,
                'available_at' => $availableAt,
                'currency' => $currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        }, attempts: 3);
    }

    /**
     * The four buckets the phase specification asks to be kept apart, rather than one number.
     *
     * @return array{pending: float, available: float, reserved: float, paid: float, balance: float}
     */
    public function balances(int|string $sellerId, string $sellerIs = 'seller', ?LedgerChain $chain = null): array
    {
        $empty = ['pending' => 0.0, 'available' => 0.0, 'reserved' => 0.0, 'paid' => 0.0, 'balance' => 0.0];

        if (!Schema::hasTable('vendor_ledger_entries')) {
            return $empty;
        }

        $rows = $this->entriesOf(sellerId: $sellerId, sellerIs: $sellerIs, chain: $chain)
            ->selectRaw('status, SUM(credit) as credits, SUM(debit) as debits')
            ->groupBy('status')
            ->get();

        $balances = $empty;
        foreach ($rows as $row) {
            $net = round((float) $row->credits - (float) $row->debits, 4);
            if (array_key_exists($row->status, $balances)) {
                $balances[$row->status] = $net;
            }
            $balances['balance'] = round($balances['balance'] + $net, 4);
        }

        return $balances;
    }

    /**
     * The amount a seller can actually request a payout for, right now.
     *
     * It is the running balance minus anything still `pending`. That one formula handles every case
     * the `available` bucket gets wrong on its own:
     *
     *   - a `reserved` payout hold is a debit, so it already lowers the balance;
     *   - a `paid` payout is a debit, so it already lowers the balance;
     *   - but the *earning* credit keeps `available` status forever, so the `available` bucket
     *     overstates once any of it has been reserved or paid.
     *
     * Balance − pending nets the holds and the payouts while excluding money still in the return
     * window. Worked through: earn 400, reserve 300 -> balance 100, pending 0 -> withdrawable 100;
     * then pay it -> balance 100, pending 0 -> still 100 (the reserved-to-paid move changes nothing).
     *
     * Without this, a second payout request would see the full earning again and double-spend it.
     */
    public function withdrawable(int|string $sellerId, string $sellerIs = 'seller', ?LedgerChain $chain = null): float
    {
        $balances = $this->balances(sellerId: $sellerId, sellerIs: $sellerIs, chain: $chain);

        // Also exclude money already claimed by a settlement. SettlementEngine pays earnings through the
        // settlement channel by stamping `settlement_id` on them (and, on markPaid, relabelling them
        // `paid` WITHOUT an offsetting debit), so those credits still sit inside `balance`. Counting them
        // here would let the same earning be withdrawn a second time through a payout request — the two
        // payout channels drawing on one pool. Netting out the settlement-claimed amount keeps them
        // mutually exclusive on the same money.
        return round(
            $balances['balance'] - $balances['pending'] - $this->settlementClaimedNet(sellerId: $sellerId, sellerIs: $sellerIs, chain: $chain),
            4,
        );
    }

    /**
     * The net amount (credits − debits) of this seller's ledger entries that a settlement has claimed
     * (`settlement_id` set). Guarded on the column existing so ledger-only unit tests are unaffected.
     */
    private function settlementClaimedNet(int|string $sellerId, string $sellerIs = 'seller', ?LedgerChain $chain = null): float
    {
        if (!Schema::hasTable('vendor_ledger_entries') || !Schema::hasColumn('vendor_ledger_entries', 'settlement_id')) {
            return 0.0;
        }

        $row = $this->entriesOf(sellerId: $sellerId, sellerIs: $sellerIs, chain: $chain)
            ->whereNotNull('settlement_id')
            ->selectRaw('SUM(credit) as credits, SUM(debit) as debits')
            ->first();

        return round((float) ($row->credits ?? 0) - (float) ($row->debits ?? 0), 4);
    }

    /**
     * Move entries from pending to available once their window has passed.
     *
     * Status is the only field an entry may ever change, and only along this one path: an earning
     * maturing out of the return window is not a correction, it is the event the entry was created
     * anticipating. The amounts are untouched.
     */
    public function releaseMatured(?\DateTimeInterface $asOf = null): int
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return 0;
        }

        $asOf = $asOf ?? now();

        $query = VendorLedgerEntry::query()
            ->where('status', VendorLedgerEntry::STATUS_PENDING)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', $asOf);

        // Only mature an order earning once its order actually completed. Earnings are credited at
        // placement, so a cancelled, failed or returned order still carries its credit; without this
        // gate it would turn into payable money the moment its window passed. An order-linked earning
        // matures only when the order is `delivered`; anything not linked to an order (a manual
        // adjustment) matures on time as before. Guarded on the tables existing so unit tests that
        // exercise the ledger in isolation are unaffected.
        if (Schema::hasTable('order_details') && Schema::hasTable('orders')) {
            $query->where(function ($q) {
                $q->where('reference_type', '!=', 'order_details')
                    ->orWhereIn('reference_id', function ($sub) {
                        $sub->select('order_details.id')
                            ->from('order_details')
                            ->join('orders', 'orders.id', '=', 'order_details.order_id')
                            ->where('orders.order_status', 'delivered');
                    });
            });
        }

        return $query->update(['status' => VendorLedgerEntry::STATUS_AVAILABLE, 'updated_at' => now()]);
    }

    /**
     * The chain an entry of this seller, selling company and currency belongs to (E-17).
     *
     * Public because a caller that wants one chain's position rather than the seller's whole ledger
     * has to name the same chain the writer used, and this is where a currency label becomes the code
     * the chain is keyed by.
     */
    public function chain(
        int|string $sellerId,
        string $sellerIs = 'seller',
        ?int $legalEntityId = null,
        ?string $currency = null,
    ): LedgerChain {
        $base = $this->currencyCode(label: $this->baseCurrency());
        $code = $this->currencyCode(label: $currency);
        $code = $code !== '' ? $code : $base;

        return LedgerChain::of(
            sellerId: $sellerId,
            currency: $code,
            currencyIsTheBaseOne: $code === $base,
            sellerIs: $sellerIs,
            legalEntityId: $legalEntityId,
        );
    }

    /**
     * The entry this event is already on, in whatever chain, or null where the ledger has never seen it.
     *
     * The event is the seller, the entry type and the reference, which is the tuple that makes recording
     * one order line twice credit it once. Public because a writer reversing an event has to label its
     * reversal with the currency of the entry it reverses, or the two land in different chains and a line
     * refunded in full leaves money payable (RefundReversalService).
     */
    public function eventAlreadyRecorded(
        int|string $sellerId,
        string $entryType,
        ?string $referenceType,
        int|string|null $referenceId,
        string $sellerIs = 'seller',
    ): ?VendorLedgerEntry {
        return VendorLedgerEntry::query()
            ->forSeller($sellerId, $sellerIs)
            ->where('entry_type', $entryType)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->first();
    }

    /**
     * The entries a reader is asking about: one chain's, when it named one, or the seller's whole
     * ledger, which is what every reader asked for before chains were told apart and still is for a
     * screen that shows a seller their whole position.
     */
    private function entriesOf(int|string $sellerId, string $sellerIs, ?LedgerChain $chain): Builder
    {
        return $chain === null
            ? VendorLedgerEntry::query()->forSeller($sellerId, $sellerIs)
            : $chain->narrow(VendorLedgerEntry::query());
    }

    /**
     * A currency label as the chain key spells it: upper case and trimmed, empty when the label names nothing.
     *
     * Public because this is the one spelling of a currency code on the seller ledger, and SettlementCurrency asks for it rather than keeping a second one of its own: a
     * settlement that normalised `sar ` differently from the chain would be drawn across two chains and report a balance belonging to only one of them.
     */
    public function currencyCode(?string $label): string
    {
        return mb_strtoupper(trim((string) $label));
    }

    /**
     * The store's base currency, which is the one ledger amounts are in.
     *
     * Public because anything reporting a ledger figure has to label it with the same code the
     * entry was stamped with; a second reading of the setting elsewhere is how the label and the
     * amount drift apart.
     */
    public function baseCurrency(): ?string
    {
        try {
            // The store's base/default currency, NOT the viewer's display currency. Ledger amounts are
            // stored in base currency (nothing is converted at add-to-cart), so stamping the entry with
            // session('currency_code') mislabelled every entry for a customer browsing in another
            // currency — and SettlementEngine/payout take their currency from the entry, so a payout
            // could carry the wrong code. The amount was always correct; only the label was wrong.
            $id = getWebConfig(name: 'system_default_currency');

            return $id ? \App\Models\Currency::find($id)?->code : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
