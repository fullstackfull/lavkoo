<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorPayoutRequest;
use App\Models\VendorSettlement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's account, line by line.
 *
 * Sellers have been shown four totals — pending, available, reserved, paid — and never the entries
 * behind them. A total nobody can take apart is a number you either believe or do not, and a seller
 * who cannot reconcile a payout against the orders that produced it has no way to raise a
 * disagreement except to complain about the total.
 *
 * Every line here is traceable in both directions: back to the order that earned it, and forward to
 * the payout or settlement that took it out. That is the whole point — the balance is not a figure
 * the marketplace asserts, it is the sum of things that happened.
 *
 * The running balance is read from the entry rather than recomputed. `balance_after` is what the
 * ledger recorded at the moment it wrote the line; recalculating it from a filtered view would
 * produce a different, prettier number that never existed.
 */
class SellerLedgerStatementService
{
    public function __construct(
        private readonly VendorLedger $ledger,
        private readonly SellerMarketTotals $totals,
    ) {
    }

    /** @return array<int, string> */
    public function entryTypes(): array
    {
        return [
            VendorLedgerEntry::TYPE_ORDER_EARNING,
            VendorLedgerEntry::TYPE_COMMISSION_CHARGE,
            VendorLedgerEntry::TYPE_REFUND,
            VendorLedgerEntry::TYPE_RETURN_ADJUSTMENT,
            VendorLedgerEntry::TYPE_SHIPPING_FEE,
            VendorLedgerEntry::TYPE_PENALTY,
            VendorLedgerEntry::TYPE_BONUS,
            VendorLedgerEntry::TYPE_MANUAL_ADJUSTMENT,
            VendorLedgerEntry::TYPE_PAYOUT,
        ];
    }

    /** @return array<int, string> */
    public function statuses(): array
    {
        return [
            VendorLedgerEntry::STATUS_PENDING,
            VendorLedgerEntry::STATUS_AVAILABLE,
            VendorLedgerEntry::STATUS_RESERVED,
            VendorLedgerEntry::STATUS_PAID,
        ];
    }

    /**
     * One page of the statement, newest first.
     *
     * @param  array<string, mixed>  $filters  entry_type, status, from, to
     */
    public function statement(int|string $sellerId, array $filters = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return $this->query($sellerId, $filters)->orderByDesc('id')->paginate(perPage: $perPage, page: $page);
    }

    /**
     * What the filtered range adds up to, alongside the account as a whole.
     *
     * The buckets are the whole account and are deliberately not filtered: a seller narrowing to
     * last week still needs to know what they can withdraw today, and a "available" figure that
     * silently meant "available, of last week's entries" would be worse than none.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(int|string $sellerId, array $filters = []): array
    {
        $totals = $this->query($sellerId, $filters)
            ->selectRaw('COUNT(*) as entries, SUM(credit) as credits, SUM(debit) as debits')
            ->first();

        $balances = $this->ledger->balances($sellerId);

        $summary = [
            'currency' => $this->ledger->baseCurrency(),
            'range' => [
                'entries' => (int) ($totals->entries ?? 0),
                'credits' => round((float) ($totals->credits ?? 0), 2),
                'debits' => round((float) ($totals->debits ?? 0), 2),
                'net' => round((float) ($totals->credits ?? 0) - (float) ($totals->debits ?? 0), 2),
            ],
            'buckets' => [
                'pending' => round($balances['pending'], 2),
                'available' => round($balances['available'], 2),
                'reserved' => round($balances['reserved'], 2),
                'paid' => round($balances['paid'], 2),
                'balance' => round($balances['balance'], 2),
            ],
            // The only figure that answers "how much can I ask for right now", and the one that has
            // to net settlements and holds rather than reading a single bucket.
            'withdrawable' => round($this->ledger->withdrawable($sellerId), 2),
        ];

        return $this->keptApartByCurrency(sellerId: $sellerId, filters: $filters, summary: $summary);
    }

    /**
     * The same summary, with every added-up figure withdrawn when the account holds more than one currency (K-04).
     *
     * A seller whose ledger is in one currency — every seller today — is answered the five keys of before, byte for byte. A seller
     * whose ledger holds two gets no single `range`, `buckets` or `withdrawable` figure, because a sum of riyals and dinars is not
     * money; they get the real figures of each currency, and `totals_refused_because` says why the one figure is missing. Silently
     * printing the sum is the one outcome that can mislead an operator into a wrong decision.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function keptApartByCurrency(int|string $sellerId, array $filters, array $summary): array
    {
        $range = $this->rangeByCurrency($sellerId, $filters);
        $money = $this->totals->money($range);

        if ($money['refused_because'] === null) {
            return $summary;
        }

        return [
            'currency' => null,
            'range' => null,
            'buckets' => null,
            'withdrawable' => null,
            'range_by_currency' => $money['by_currency'],
            'buckets_by_currency' => $this->bucketsByCurrency($sellerId),
            'totals_refused_because' => $money['refused_because'],
        ];
    }

    /**
     * What the filtered range adds up to in each currency it holds. An entry that names no currency is read as the store's base
     * currency, as SettlementCurrency reads it, so an old entry does not become a currency of its own.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, float|int>>
     */
    private function rangeByCurrency(int|string $sellerId, array $filters): array
    {
        $rows = $this->query($sellerId, $filters)
            ->selectRaw('currency, COUNT(*) as entries, SUM(credit) as credits, SUM(debit) as debits')
            ->groupBy('currency')
            ->get();

        $byCurrency = [];

        foreach ($rows as $row) {
            $code = $this->currencyOf($row->currency);
            $credits = round((float) $row->credits, 2);
            $debits = round((float) $row->debits, 2);

            $byCurrency[$code] = [
                'entries' => (int) ($byCurrency[$code]['entries'] ?? 0) + (int) $row->entries,
                'credits' => round((float) ($byCurrency[$code]['credits'] ?? 0) + $credits, 2),
                'debits' => round((float) ($byCurrency[$code]['debits'] ?? 0) + $debits, 2),
                'net' => round((float) ($byCurrency[$code]['net'] ?? 0) + $credits - $debits, 2),
            ];
        }

        return $byCurrency;
    }

    /**
     * The whole account's buckets in each currency it holds, so a seller who is denied one balance is not denied every balance.
     *
     * @return list<array<string, mixed>>
     */
    private function bucketsByCurrency(int|string $sellerId): array
    {
        $rows = VendorLedgerEntry::query()
            ->forSeller($sellerId)
            ->selectRaw('currency, status, SUM(credit) as credits, SUM(debit) as debits')
            ->groupBy('currency', 'status')
            ->get();

        $empty = ['pending' => 0.0, 'available' => 0.0, 'reserved' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
        $buckets = [];

        foreach ($rows as $row) {
            $code = $this->currencyOf($row->currency);
            $buckets[$code] ??= $empty;
            $net = round((float) $row->credits - (float) $row->debits, 4);

            if (array_key_exists($row->status, $buckets[$code])) {
                $buckets[$code][$row->status] = round($buckets[$code][$row->status] + $net, 4);
            }

            $buckets[$code]['balance'] = round($buckets[$code]['balance'] + $net, 4);
        }

        ksort($buckets);

        return array_map(
            static fn (string $code, array $figures): array => ['currency_code' => $code] + array_map(static fn (float $figure): float => round($figure, 2), $figures),
            array_keys($buckets),
            array_values($buckets),
        );
    }

    /** The currency an entry is in: the one it names, or the store's base currency when it names none. */
    private function currencyOf(mixed $named): string
    {
        $code = is_string($named) ? trim($named) : '';

        return $code !== '' ? $code : (string) $this->ledger->baseCurrency();
    }

    /**
     * Turn a page of entries into rows a seller can follow in both directions.
     *
     * @param  Collection<int, VendorLedgerEntry>|array<int, VendorLedgerEntry>  $entries
     * @return array<int, array<string, mixed>>
     */
    public function rows(iterable $entries): array
    {
        $entries = collect($entries);

        $orderIds = $this->orderIdsFor($entries);
        $payouts = $this->payoutsFor($entries);
        $settlements = $this->settlementsFor($entries);

        return $entries->map(fn (VendorLedgerEntry $entry) => [
            'id' => $entry->id,
            'entry_type' => $entry->entry_type,
            'credit' => round((float) $entry->credit, 2),
            'debit' => round((float) $entry->debit, 2),
            // Read, never recomputed: this is what the balance was when the line was written.
            'balance_after' => round((float) $entry->balance_after, 2),
            'status' => $entry->status,
            'available_at' => $entry->available_at,
            'description' => $entry->description,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            // Backwards: the order that earned it.
            'order_id' => $entry->reference_type === 'order_details'
                ? ($orderIds[(int) $entry->reference_id] ?? null)
                : null,
            // Forwards: the payout or settlement that took it out.
            'payout_reference' => $payouts[$entry->id] ?? null,
            'settlement_reference' => $entry->settlement_id ? ($settlements[(int) $entry->settlement_id] ?? null) : null,
            'created_at' => $entry->created_at,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(int|string $sellerId, array $filters)
    {
        return VendorLedgerEntry::query()
            ->forSeller($sellerId)
            ->when(
                in_array($filters['entry_type'] ?? null, $this->entryTypes(), true),
                fn ($query) => $query->where('entry_type', $filters['entry_type']),
            )
            ->when(
                in_array($filters['status'] ?? null, $this->statuses(), true),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(!empty($filters['from']), fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when(!empty($filters['to']), fn ($query) => $query->whereDate('created_at', '<=', $filters['to']));
    }

    /**
     * Order ids for the order-line references on this page, in one query.
     *
     * @param  Collection<int, VendorLedgerEntry>  $entries
     * @return array<int, int>
     */
    private function orderIdsFor(Collection $entries): array
    {
        if (!Schema::hasTable('order_details')) {
            return [];
        }

        $lineIds = $entries
            ->where('reference_type', 'order_details')
            ->pluck('reference_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if ($lineIds === []) {
            return [];
        }

        return DB::table('order_details')->whereIn('id', $lineIds)->pluck('order_id', 'id')
            ->map(fn ($orderId) => (int) $orderId)->all();
    }

    /**
     * The payout each entry was reserved or paid into, keyed by entry id.
     *
     * @param  Collection<int, VendorLedgerEntry>  $entries
     * @return array<int, string>
     */
    private function payoutsFor(Collection $entries): array
    {
        if (!Schema::hasTable('vendor_payout_requests')) {
            return [];
        }

        $entryIds = $entries->pluck('id')->all();

        if ($entryIds === []) {
            return [];
        }

        $references = [];

        VendorPayoutRequest::whereIn('reserve_entry_id', $entryIds)
            ->orWhereIn('payout_entry_id', $entryIds)
            ->get(['reference', 'reserve_entry_id', 'payout_entry_id'])
            ->each(function (VendorPayoutRequest $payout) use (&$references) {
                foreach ([$payout->reserve_entry_id, $payout->payout_entry_id] as $entryId) {
                    if ($entryId) {
                        $references[(int) $entryId] = $payout->reference;
                    }
                }
            });

        return $references;
    }

    /**
     * @param  Collection<int, VendorLedgerEntry>  $entries
     * @return array<int, string>
     */
    private function settlementsFor(Collection $entries): array
    {
        if (!Schema::hasTable('vendor_settlements')) {
            return [];
        }

        $ids = $entries->pluck('settlement_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        if ($ids === []) {
            return [];
        }

        return VendorSettlement::whereIn('id', $ids)->pluck('reference', 'id')->all();
    }
}
