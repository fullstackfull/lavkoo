<?php

namespace App\Services\Accounting\Odoo;

use App\Enums\OperationalCosts\CostState;
use App\Models\OdooPosting;
use App\Models\OdooReconciliation;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Three figures for one period and one fact, each read from its own place — and the differences left showing.
 *
 * | leg | where its figure comes from |
 * |---|---|
 * | **source** | the channel's or provider's OWN document: `operational_cost_invoice_items` through approved invoices. **Null where the channel publishes nothing at all** — noon has no settlement, payout or fee endpoint anywhere in its reference |
 * | **ledger** | `operational_cost_entries`, settled and effective. **Read, never recomputed:** `OperationalCosts` is the only engine for costs, FIFO, commissions and profitability |
 * | **Odoo** | the debits of the journal lines of the entries this module posted. **Null when Odoo could not be read**, which is not the same as Odoo holding nothing |
 *
 * **Nothing here closes a difference.** A row that resolved one by itself would be agreeing with whichever figure this code
 * happened to trust, and the whole reason for three books is that no one of them is automatically right.
 *
 * It also counts what has NOT been posted, because a difference caused by an empty outbox is a different problem from a
 * difference between two figures, and a report that merged them would send somebody looking in the wrong place.
 */
class OdooThreeWayReconciliation
{
    public function __construct(private readonly OdooClient $client)
    {
    }

    /**
     * Builds (or rebuilds) one row.
     *
     * @param string $factKind one of our cost components, which is what both the ledger and the invoice line are keyed by
     */
    public function build(
        SalesChannelAccount $connection,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $factKind,
        string $currency,
        ?string $channel = null,
        int $sellerId = 0,
    ): OdooReconciliation {
        $companyId = (int) $connection->setting('company_id', 0);
        $posted = $this->postedRows($from, $to, $factKind, $channel, $sellerId);

        $identity = [
            'seller_id' => $sellerId,
            // Not nullable, for the reason the migration gives: a unique index does not constrain NULLs, so "every company" and
            // "every channel" are a zero and an empty string that the index can actually compare.
            'odoo_company_id' => $companyId,
            'sales_channel' => (string) ($channel ?? ''),
            'currency' => $currency,
            'fact_kind' => $factKind,
        ];
        $figures = [
            'source_amount' => $this->sourceAmount($from, $to, $factKind, $currency),
            'source_basis' => $this->sourceBasis($from, $to, $factKind, $currency),
            'ledger_amount' => $this->ledgerAmount($from, $to, $factKind, $currency, $channel, $sellerId),
            'entries_counted' => count($posted),
            'entries_not_posted' => $this->notPostedCount($from, $to, $factKind, $channel, $sellerId),
        ];

        // Matched with `whereDate` and not with `updateOrCreate`: the two period columns are date casts, so what is stored
        // carries a time part, and a plain `Y-m-d` in a WHERE would never match it -- the second run would insert and collide
        // with the table's own unique key instead of replacing the row. Rebuilding a period must replace it, or the report shows
        // the same period several times over with different figures.
        $row = OdooReconciliation::where($identity)
            ->whereDate('period_start', $from->toDateString())
            ->whereDate('period_end', $to->toDateString())
            ->first();

        $row === null
            ? $row = OdooReconciliation::create($identity + $figures + ['period_start' => $from->toDateString(), 'period_end' => $to->toDateString()])
            : $row->forceFill($figures)->save();

        // The Odoo leg is a read of somebody else's server, and it is allowed to fail. When it does the figure stays NULL and
        // `odoo_read_at` stays as it was, so the screen can say "not read" rather than imply "nothing there".
        $odoo = $this->odooAmount($connection, $posted);
        if ($odoo !== null) {
            $row->forceFill(['odoo_amount' => $odoo, 'odoo_read_at' => now()])->save();
        }

        return $row->fresh();
    }

    /**
     * What the channel or provider itself said, from its own document.
     *
     * Only approved invoices count: an invoice nobody has approved is a claim somebody is still checking, and putting it in the
     * source column would make it look confirmed.
     */
    private function sourceAmount(CarbonImmutable $from, CarbonImmutable $to, string $factKind, string $currency): ?string
    {
        $total = DB::table('operational_cost_invoice_items')
            ->join('operational_cost_invoices', 'operational_cost_invoices.id', '=', 'operational_cost_invoice_items.invoice_id')
            ->whereNotNull('operational_cost_invoices.approved_at')
            ->where('operational_cost_invoice_items.cost_component', $factKind)
            ->where('operational_cost_invoice_items.currency', $currency)
            ->where(fn ($query) => $query
                ->whereBetween('operational_cost_invoice_items.service_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('operational_cost_invoice_items.period_start', [$from->toDateString(), $to->toDateString()]))
            ->sum('operational_cost_invoice_items.amount');

        // No line at all is NOT a zero: it means the channel said nothing about this fact for this period.
        return $this->hasAnySourceLine($from, $to, $factKind, $currency) ? number_format((float) $total, 6, '.', '') : null;
    }

    private function hasAnySourceLine(CarbonImmutable $from, CarbonImmutable $to, string $factKind, string $currency): bool
    {
        return DB::table('operational_cost_invoice_items')
            ->join('operational_cost_invoices', 'operational_cost_invoices.id', '=', 'operational_cost_invoice_items.invoice_id')
            ->whereNotNull('operational_cost_invoices.approved_at')
            ->where('operational_cost_invoice_items.cost_component', $factKind)
            ->where('operational_cost_invoice_items.currency', $currency)
            ->where(fn ($query) => $query
                ->whereBetween('operational_cost_invoice_items.service_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('operational_cost_invoice_items.period_start', [$from->toDateString(), $to->toDateString()]))
            ->exists();
    }

    /** Which document the source figure came from, or null where the channel publishes none. */
    private function sourceBasis(CarbonImmutable $from, CarbonImmutable $to, string $factKind, string $currency): ?string
    {
        return $this->hasAnySourceLine($from, $to, $factKind, $currency) ? 'approved_invoice_items' : null;
    }

    /** Our own books. The base-currency figure where the ledger has one, because that is the currency the books are kept in. */
    private function ledgerAmount(CarbonImmutable $from, CarbonImmutable $to, string $factKind, string $currency, ?string $channel, int $sellerId): ?string
    {
        $query = $this->ledgerQuery($from, $to, $factKind, $channel, $sellerId)->where('currency', $currency);
        if (!$query->clone()->exists()) {
            return null;
        }

        return number_format((float) $query->sum(DB::raw('COALESCE(base_amount, amount)')), 6, '.', '');
    }

    private function ledgerQuery(CarbonImmutable $from, CarbonImmutable $to, string $factKind, ?string $channel, int $sellerId): \Illuminate\Database\Eloquent\Builder
    {
        return OperationalCostEntry::query()
            ->where('seller_id', $sellerId)
            ->where('cost_component', $factKind)
            ->whereBetween('period_date', [$from->toDateString(), $to->toDateString()])
            ->where('is_effective', true)
            // Only what is settled, for the same reason the builder posts only what is settled: an estimate in a reconciliation
            // column would make a figure nobody has confirmed look like one of three agreeing books.
            ->whereIn('state', [CostState::Actual->value, CostState::Reconciled->value])
            ->when($channel !== null, fn ($query) => $query->where('sales_channel', $channel));
    }

    /**
     * The rows this module posted for the window, with the Odoo ids Odoo itself reported.
     *
     * @return list<int>
     */
    private function postedRows(CarbonImmutable $from, CarbonImmutable $to, string $factKind, ?string $channel, int $sellerId): array
    {
        $sourceIds = $this->ledgerQuery($from, $to, $factKind, $channel, $sellerId)->pluck('id')->all();
        if ($sourceIds === []) {
            return [];
        }

        return OdooPosting::where('source_type', OdooPostingKey::SOURCE_COST_ENTRY)
            ->whereIn('source_id', $sourceIds)
            ->where('state', OdooPosting::POSTED)
            ->whereNotNull('odoo_move_id')
            ->pluck('odoo_move_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** How many of the window's facts are queued and NOT in Odoo. A difference this explains is not a difference in a figure. */
    private function notPostedCount(CarbonImmutable $from, CarbonImmutable $to, string $factKind, ?string $channel, int $sellerId): int
    {
        $sourceIds = $this->ledgerQuery($from, $to, $factKind, $channel, $sellerId)->pluck('id')->all();
        if ($sourceIds === []) {
            return 0;
        }

        return OdooPosting::where('source_type', OdooPostingKey::SOURCE_COST_ENTRY)
            ->whereIn('source_id', $sourceIds)
            ->where('state', '!=', OdooPosting::POSTED)
            ->count();
    }

    /**
     * What Odoo holds for those entries: the debits of their journal lines.
     *
     * Null when Odoo could not be read, or when it answered something unreadable. `debit` and `credit` are the two non-negative
     * columns of a line, so the figure is the debit side — the same side the builder puts a cost on.
     *
     * @param list<int> $moveIds
     */
    private function odooAmount(SalesChannelAccount $connection, array $moveIds): ?string
    {
        if ($moveIds === []) {
            // Nothing was posted, so there is nothing of ours in Odoo to add up. That IS a figure: zero of our entries.
            return '0.000000';
        }

        $answer = $this->client->searchRead(
            $connection,
            'account.move.line',
            [['move_id', 'in', array_values($moveIds)]],
            ['debit', 'credit', 'move_id'],
            limit: 2000,
        );
        if (!$answer->ok()) {
            return null;
        }

        $total = 0.0;
        foreach ($answer->records() as $line) {
            $total += (float) ($line['debit'] ?? 0);
        }

        return number_format($total, 6, '.', '');
    }
}
