<?php

namespace App\Services\Marketplace;

use App\Models\VendorLedgerEntry;
use App\Models\VendorSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 *
 * ## One currency (E-17.1)
 *
 * With `gcc.settlements.one_currency` on, a settlement is drawn in one currency. Entries of another
 * currency are never added into it: they stay unclaimed for a settlement of their own, which
 * `calculateEachCurrency()` draws in the same run. With the switch off, which is how the platform
 * ships, the engine does not look at the currency of an entry and draws exactly what it drew before
 * the switch existed (config/gcc.php says why that is the default). SettlementCurrency is the one
 * place that says which currency an entry is settled in.
 *
 * An entry that names no currency (NULL or empty) is read as the store's base currency and settled
 * with the entries that name it. That is what the writers show: every entry is written by
 * `VendorLedger::record()`, which stamps the currency it is given or else the base currency, and no
 * caller gives it anything but the base currency (the order, payout and legacy-withdraw writers give
 * none; the refund writer gives the currency of the order's commission snapshot, which is the base
 * currency as it was read when the order was placed). So an entry carries no currency only when the
 * base currency could not be read as the entry was written, and its amount is in it all the same.
 * The base currency is read when the settlement is drawn, so an installation that changed its base
 * currency after such an entry was written has to label that entry first. When the base currency
 * cannot be read either, the entries that name none are settled on their own, in a settlement that
 * names none: they are neither stranded nor added to a named currency.
 *
 * The opening and the closing balance come from the running balance of the ledger, which is one
 * chain for a seller across currencies (a chain for each currency is E-17.2, and is not built). They
 * are a balance in the settlement's currency only while every entry of that chain up to the
 * settlement's last one is in that currency, and then they are exactly what they were before. Once
 * the chain has mixed currencies they would be a sum of two monies, so the settlement records
 * neither (NULL) rather than a figure nobody could pay or reconcile; its totals are sums of its own
 * entries and are unaffected. `vendor_settlements` has no migration in this repository, so whether
 * its two balance columns accept NULL is asked of the table, and drawing in one currency is refused,
 * before anything is claimed, on a table that would have to be given a number.
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

    private const BALANCES = ['opening_balance', 'closing_balance'];

    private ?bool $balancesAcceptNull = null;

    /** @var list<ABalanceCannotBeLeftUnknown> */
    private array $sellersWhoseBalanceCannotBeLeftUnknown = [];

    public function __construct(private readonly SettlementCurrency $currency)
    {
    }

    /**
     * Build a settlement for one vendor from their unclaimed, available ledger entries.
     *
     * Returns null when there is nothing to settle, which is a normal outcome on a schedule and not
     * an error — a vendor with no sales this week simply has no settlement.
     *
     * While a settlement is drawn in one currency this draws one: the currency of the earliest entry
     * waiting. Entries of another currency stay unclaimed, so a caller that means "settle this
     * vendor" asks `calculateEachCurrency()`.
     */
    public function calculate(
        int|string $sellerId,
        string $sellerIs = 'seller',
        ?\DateTimeInterface $periodStart = null,
        ?\DateTimeInterface $periodEnd = null,
    ): ?VendorSettlement {
        return $this->draw(sellerId: $sellerId, sellerIs: $sellerIs, periodStart: $periodStart, periodEnd: $periodEnd, atMost: 1)[0] ?? null;
    }

    /**
     * Build every settlement one vendor's unclaimed, available ledger entries make: one for each
     * currency they are in, or the single one `calculate()` builds while currencies are not kept
     * apart. Empty when there is nothing to settle.
     *
     * @return list<VendorSettlement>
     */
    public function calculateEachCurrency(
        int|string $sellerId,
        string $sellerIs = 'seller',
        ?\DateTimeInterface $periodStart = null,
        ?\DateTimeInterface $periodEnd = null,
    ): array {
        return $this->draw(sellerId: $sellerId, sellerIs: $sellerIs, periodStart: $periodStart, periodEnd: $periodEnd, atMost: PHP_INT_MAX);
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

        $sellers = $this->settleable()
            ->select('seller_id', 'seller_is')
            ->distinct()
            ->get();

        $settlements = [];
        $this->sellersWhoseBalanceCannotBeLeftUnknown = [];
        foreach ($sellers as $seller) {
            try {
                array_push($settlements, ...$this->calculateEachCurrency(sellerId: $seller->seller_id, sellerIs: $seller->seller_is, periodStart: $periodStart, periodEnd: $periodEnd));
            } catch (ABalanceCannotBeLeftUnknown $refusal) {
                // This seller's ledger mixes currencies and the table will not hold an unknown balance. Nothing of theirs was
                // claimed; the run goes on and the caller reports them, so one seller does not leave the rest unsettled.
                $this->sellersWhoseBalanceCannotBeLeftUnknown[] = $refusal;
            }
        }

        return $settlements;
    }

    /**
     * The sellers the last run over every seller could not settle, because their ledger mixes currencies and this
     * installation's table will not hold an unknown balance.
     *
     * @return list<ABalanceCannotBeLeftUnknown>
     */
    public function sellersWhoseBalanceCannotBeLeftUnknown(): array
    {
        return $this->sellersWhoseBalanceCannotBeLeftUnknown;
    }

    /**
     * What a run would draw, without drawing it: a line for each settlement, in no particular order.
     * It claims nothing, so it is what a dry run and the operator's screen report from.
     *
     * @return list<array{seller_id: int, seller_is: string, currency: string, legal_entity_id: int|null, entries: int, credits: float, debits: float, net: float}>
     */
    public function waiting(int|string|null $sellerId = null, string $sellerIs = 'seller'): array
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return [];
        }

        $byCompany = $this->companyIsPartOfTheKey() ? [LedgerChain::ENTITY_COLUMN] : [];

        return $this->settleable()
            ->when($sellerId, fn ($q) => $q->forSeller($sellerId, $sellerIs))
            ->selectRaw(implode(', ', array_merge(
                ['seller_id', 'seller_is', 'currency'],
                $byCompany,
                ['COUNT(*) as entries', 'SUM(credit) as credits', 'SUM(debit) as debits'],
            )))
            ->groupBy(...array_merge(['seller_id', 'seller_is', 'currency'], $byCompany))
            ->get()
            ->toBase()
            ->groupBy(fn (VendorLedgerEntry $labelled): string => $labelled->seller_id . '/' . $labelled->seller_is . '/' . $this->settlementKeyOf(entry: $labelled))
            ->map(fn (Collection $ofOneSettlement): array => $this->lineOf(ofOneSettlement: $ofOneSettlement))
            ->values()
            ->all();
    }

    /**
     * Whether `vendor_settlements` can record that an opening or a closing balance is not known,
     * which a settlement of a ledger that mixes currencies needs (see the class docblock).
     */
    public function canLeaveABalanceUnknown(): bool
    {
        return $this->balancesAcceptNull ??= collect(Schema::getColumns('vendor_settlements'))
            ->whereIn('name', self::BALANCES)
            ->every(fn (array $column): bool => (bool) $column['nullable']);
    }

    /**
     * Draw the settlements one vendor's waiting entries make, the first `$atMost` of them, and
     * claim their entries.
     *
     * @return list<VendorSettlement>
     */
    private function draw(int|string $sellerId, string $sellerIs, ?\DateTimeInterface $periodStart, ?\DateTimeInterface $periodEnd, int $atMost): array
    {
        if (!Schema::hasTable('vendor_settlements') || !Schema::hasTable('vendor_ledger_entries')) {
            return [];
        }

        return DB::transaction(function () use ($sellerId, $sellerIs, $periodStart, $periodEnd, $atMost) {
            // Locked for the transaction so a second run cannot claim the same rows.
            $waiting = $this->settleable()
                ->forSeller($sellerId, $sellerIs)
                ->when($periodStart, fn ($q) => $q->where('created_at', '>=', $periodStart))
                ->when($periodEnd, fn ($q) => $q->where('created_at', '<=', $periodEnd))
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            return $this->entriesOfEachSettlement(waiting: $waiting)
                ->take($atMost)
                ->map(fn (Collection $entries): VendorSettlement => $this->settle(entries: $entries, sellerId: $sellerId, sellerIs: $sellerIs, periodStart: $periodStart, periodEnd: $periodEnd))
                ->all();
        }, attempts: 3);
    }

    /**
     * The entries waiting, split into the settlements they make: one for each currency, in the order
     * each currency first appears on the ledger. While currencies are not kept apart every entry
     * answers the same currency, so there is one settlement and it takes them all.
     *
     * @return Collection<int, Collection<int, VendorLedgerEntry>>
     */
    private function entriesOfEachSettlement(Collection $waiting): Collection
    {
        return $waiting->toBase()
            ->groupBy(fn (VendorLedgerEntry $entry): string => $this->settlementKeyOf(entry: $entry))
            ->values();
    }

    /** Record the settlement these entries make, and claim them for it. */
    private function settle(Collection $entries, int|string $sellerId, string $sellerIs, ?\DateTimeInterface $periodStart, ?\DateTimeInterface $periodEnd): VendorSettlement
    {
        $credits = round((float) $entries->sum('credit'), 4);
        $debits = round((float) $entries->sum('debit'), 4);
        $net = round($credits - $debits, 4);
        $balances = $this->balancesAround(entries: $entries, sellerId: $sellerId, sellerIs: $sellerIs);

        $settlement = VendorSettlement::create($this->companyRecordedFor(entries: $entries) + [
            'reference' => $this->nextReference(),
            'seller_id' => $sellerId,
            'seller_is' => $sellerIs,
            'period_start' => $periodStart ?? $entries->min('created_at'),
            'period_end' => $periodEnd ?? $entries->max('created_at'),
            'opening_balance' => $balances['opening'],
            'total_credits' => $credits,
            'total_debits' => $debits,
            'net_amount' => $net,
            'closing_balance' => $balances['closing'],
            'entry_count' => $entries->count(),
            'currency' => $this->currencyRecordedFor(entries: $entries),
            'status' => VendorSettlement::STATUS_CALCULATED,
            'calculated_at' => now(),
        ]);

        // Claim them. Stamping the id is what stops a second settlement taking the same money.
        VendorLedgerEntry::whereIn('id', $entries->pluck('id'))
            ->update(['settlement_id' => $settlement->id, 'updated_at' => now()]);

        return $settlement->fresh();
    }

    /**
     * What the ledger said before the earliest claimed entry and after the latest one. Taken from
     * the entries themselves rather than re-summed, which is why balance_after is stored.
     *
     * In a settlement drawn in one currency neither is known once the seller's chain has mixed
     * currencies (see the class docblock).
     *
     * @return array{opening: float|null, closing: float|null}
     */
    private function balancesAround(Collection $entries, int|string $sellerId, string $sellerIs): array
    {
        $first = $entries->first();
        $last = $entries->last();

        if ($this->currency->isOnePerSettlement() && !$this->ledgerIsOneChainUpTo(entry: $last, sellerId: $sellerId, sellerIs: $sellerIs)) {
            if (!$this->canLeaveABalanceUnknown()) {
                throw new ABalanceCannotBeLeftUnknown(sellerId: $sellerId, sellerIs: $sellerIs);
            }

            return ['opening' => null, 'closing' => null];
        }

        return [
            'opening' => round(((float) $first->balance_after) - ((float) $first->credit) + ((float) $first->debit), 4),
            'closing' => round((float) $last->balance_after, 4),
        ];
    }

    /**
     * Whether everything on this seller's ledger up to this entry belongs to ONE chain, which is what
     * makes `balance_after` an opening and a closing balance of the settlement rather than a number
     * running over money of several kinds.
     *
     * History decides this, not the switch. Entries written before chains were told apart carry a
     * `balance_after` that ran over every company and currency the seller had, so a ledger that mixed
     * them once cannot report balances for a settlement drawn out of it however it is keyed now.
     */
    private function ledgerIsOneChainUpTo(VendorLedgerEntry $entry, int|string $sellerId, string $sellerIs): bool
    {
        return VendorLedgerEntry::query()
            ->forSeller($sellerId, $sellerIs)
            ->where('id', '<=', $entry->id)
            ->get(array_merge(['currency'], $this->companyIsPartOfTheKey() ? [LedgerChain::ENTITY_COLUMN] : []))
            ->map(fn (VendorLedgerEntry $earlier): string => $this->settlementKeyOf(entry: $earlier))
            ->unique()
            ->count() === 1;
    }

    /**
     * What tells one settlement of a seller from another: the currency, and the selling company where
     * the ledger records one. Two entries with the same key belong in the same settlement.
     */
    private function settlementKeyOf(VendorLedgerEntry $entry): string
    {
        $key = $this->currency->of(label: $entry->currency);

        return $this->companyIsPartOfTheKey()
            ? $key . '/' . (string) $entry->getAttribute(LedgerChain::ENTITY_COLUMN)
            : $key;
    }

    /**
     * The selling company a settlement records, where `vendor_settlements` has a column for it. That
     * table has no migration in this repository either, so an installation whose settlements cannot
     * name a company still draws them; it just does not say whose they are.
     *
     * @param Collection<int, VendorLedgerEntry> $entries
     */
    private function companyRecordedFor(Collection $entries): array
    {
        if (!$this->companyIsPartOfTheKey() || !Schema::hasColumn('vendor_settlements', LedgerChain::ENTITY_COLUMN)) {
            return [];
        }

        return [LedgerChain::ENTITY_COLUMN => $this->companyOf(entry: $entries->first())];
    }

    /** The selling company of an entry, or null where this installation's ledger does not record one. */
    private function companyOf(VendorLedgerEntry $entry): ?int
    {
        $company = LedgerChain::entityIsRecorded() ? $entry->getAttribute(LedgerChain::ENTITY_COLUMN) : null;

        return $company === null ? null : (int) $company;
    }

    /** Whether the selling company tells one settlement from another: only once chains are kept apart and the ledger has a column to record it in. */
    private function companyIsPartOfTheKey(): bool
    {
        return LedgerChain::areKeptApart() && LedgerChain::entityIsRecorded();
    }

    /** While currencies are not kept apart a settlement takes the label of its first entry, whatever the others carry. */
    private function currencyRecordedFor(Collection $entries): ?string
    {
        return $this->currency->isOnePerSettlement()
            ? $this->currency->labelOf(labels: $entries->pluck('currency'))
            : $entries->first()->currency;
    }

    /**
     * One line of `waiting()`: the entries of one settlement, which the database grouped by the
     * label each carries, added up.
     *
     * @return array{seller_id: int, seller_is: string, currency: string, entries: int, credits: float, debits: float, net: float}
     */
    private function lineOf(Collection $ofOneSettlement): array
    {
        $credits = round((float) $ofOneSettlement->sum('credits'), 4);
        $debits = round((float) $ofOneSettlement->sum('debits'), 4);

        return [
            'seller_id' => $ofOneSettlement->first()->seller_id,
            'seller_is' => $ofOneSettlement->first()->seller_is,
            'currency' => $this->currency->of(label: $ofOneSettlement->first()->currency),
            'legal_entity_id' => $this->companyOf(entry: $ofOneSettlement->first()),
            'entries' => (int) $ofOneSettlement->sum('entries'),
            'credits' => $credits,
            'debits' => $debits,
            'net' => round($credits - $debits, 4),
        ];
    }

    /** The entries a settlement may draw on: nobody has claimed them, the seller can be paid them, and they are not payout bookkeeping. */
    private function settleable(): Builder
    {
        return VendorLedgerEntry::query()
            ->whereNull('settlement_id')
            ->where('status', VendorLedgerEntry::STATUS_AVAILABLE)
            ->where(fn ($q) => $this->excludePayoutMachinery($q));
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
