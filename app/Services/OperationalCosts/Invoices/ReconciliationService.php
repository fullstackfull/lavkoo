<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Models\OperationalCostReconciliation;
use App\Models\SalesChannelAccount;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Compares what an invoice charged with what we expected, line by line.
 *
 *  - A line whose service had an ESTIMATE is compared with it: within the tolerance it is MATCHED and its cost is reconciled; beyond it the line
 *    is a VARIANCE that waits for a person, who accepts the charge, disputes it, or writes the difference off.
 *  - A line with no estimate to compare (a charge we did not expect) is UNMATCHED INVOICE.
 *  - An estimate in the invoice's period that no line charged is UNMATCHED ESTIMATE, so a shipment the provider forgot to bill, or billed under
 *    another number, is seen.
 *
 * A marketplace invoice line that is a total for a period with no order on it is set against what the channel's statements booked for the same fee and period
 * (`settleAgainstStatements`), within the same tolerance, instead of being posted as a second set of actual costs, and it does not matter which came first:
 *  - the statements first: the line is compared at approval and posts nothing;
 *  - the invoice first: the line is posted in full (the statements had booked nothing), and when the statements arrive (the importer, the catch-up after the cost module was
 *    switched on, a statement file) every approved line their days fall in is set against them: the full posting is taken back with an equal and opposite entry and the line is
 *    compared like any other, so the fee is counted once;
 *  - a difference beyond the tolerance waits for a person; when it is accepted (or written off) the difference is booked as an adjustment, so the books say what the invoice says
 *    without counting any fee twice, and it follows the statements if more of them arrive afterwards (the earlier adjustment is reversed and the new difference booked).
 *
 * The tolerance is the contract's own (`settings.tolerance_percent`) or the configured default. Running it again changes nothing that a
 * person already decided.
 */
class ReconciliationService
{
    public function __construct(
        private readonly CostLedger $ledger,
        private readonly AuditLogger $audit,
        private readonly ChannelStatementActuals $statements,
    ) {
    }

    /** @return Collection<int, OperationalCostReconciliation> */
    public function reconcile(OperationalCostInvoice $invoice): Collection
    {
        $rows = collect();
        foreach ($invoice->items()->whereNotNull('entry_id')->get() as $item) {
            $rows->push($this->reconcileItem($invoice, $item));
        }

        return $rows;
    }

    /**
     * Sets an invoice line that bills a fee for a whole period against what the channel's statements booked for it. Returns false when the statements booked nothing (the line
     * is then posted as an actual cost like any invoice line, and is set against the statements again when they arrive), true when the line is not to be posted in full:
     * within the tolerance the statements stand; beyond it the line is a VARIANCE for a person. A line already posted in full is taken back with an equal and opposite entry.
     * Running it again leaves a row a person already decided alone, except that an accepted difference follows the statements.
     */
    public function settleAgainstStatements(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item): bool
    {
        $booked = $this->statements->totalFor($invoice, $item);
        if ($booked === null) {
            return false;
        }
        $existing = OperationalCostReconciliation::where('invoice_item_id', $item->id)->first();
        if ($existing !== null && $existing->basis !== OperationalCostReconciliation::BASIS_STATEMENTS && in_array($existing->status, [OperationalCostReconciliation::RESOLVED, OperationalCostReconciliation::DISPUTED], true)) {
            return true;
        }

        $this->takeBackWhatWasPostedInFull($item);
        $this->followTheStatements($this->compareWithStatements($invoice, $item, $booked), $invoice, $item, $booked);

        return true;
    }

    /**
     * Fee facts of an account were just posted: every approved invoice line that bills the same fee in a period their days fall in is set against the statements again, so a
     * line approved before the statements arrived does not count the fee a second time.
     *
     * @return int the lines that were set against the statements
     */
    public function settleLinesAffectedBy(SalesChannelAccount $account, CostComponent $component, string $currency, CarbonInterface $from, CarbonInterface $to): int
    {
        $items = OperationalCostInvoiceItem::query()
            ->with('invoice')
            ->where('cost_component', $component->value)->where('currency', $currency)
            ->whereNull('provider_fulfillment_id')->whereNull('external_order_mapping_id')
            ->where('match_status', '!=', OperationalCostInvoiceItem::IGNORED)
            ->whereHas('invoice', static fn ($invoice) => $invoice->where('status', OperationalCostInvoice::STATUS_APPROVED)->where('provider_code', $account->channel)->where('seller_id', $account->seller_id)
                ->where(static fn ($scope) => $scope->whereNull('account_id')->orWhere('account_id', $account->id)))
            ->get();

        $settled = 0;
        foreach ($items as $item) {
            $period = $this->statements->periodOf($item->invoice, $item);
            if ($period !== null && $period[1]->toDateString() >= $from->toDateString() && $period[0]->toDateString() <= $to->toDateString()) {
                $settled += $this->settleAgainstStatements($item->invoice, $item) ? 1 : 0;
            }
        }

        return $settled;
    }

    /** Sets every line of an invoice that a statement can book against the statements again (a person asking for the invoice to be reconciled gets this too). @return int the lines set against them */
    public function settleStatementLines(OperationalCostInvoice $invoice): int
    {
        $settled = 0;
        foreach ($invoice->items()->where('match_status', '!=', OperationalCostInvoiceItem::IGNORED)->get() as $item) {
            $settled += $this->statements->periodOf($invoice, $item) !== null && $this->settleAgainstStatements($invoice, $item) ? 1 : 0;
        }

        return $settled;
    }

    /** A line that was posted in full before the statements arrived: the statements are what is counted now, so its posting is cancelled. A charge a person contests is in no total, so there is nothing to cancel. */
    private function takeBackWhatWasPostedInFull(OperationalCostInvoiceItem $item): void
    {
        $posted = $item->entry_id === null ? null : OperationalCostEntry::find($item->entry_id);
        if ($posted !== null && $posted->state->isSettled()) {
            $this->ledger->reverse($posted, null, 'The channel statements booked this fee: the invoice line is compared with them instead.');
        }
    }

    private function compareWithStatements(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item, Money $booked): OperationalCostReconciliation
    {
        $existing = OperationalCostReconciliation::where('invoice_item_id', $item->id)->first();
        if ($existing !== null && in_array($existing->status, [OperationalCostReconciliation::RESOLVED, OperationalCostReconciliation::DISPUTED], true)) {
            return $existing;
        }

        $tolerance = $this->toleranceFor($invoice);
        $variance = Money::of($item->amount)->minus($booked);
        $percent = $booked->isZero() ? null : $variance->times(100)->dividedBy($booked->abs(), 4);
        $within = $variance->isZero() || ($percent !== null && $percent->abs()->compareTo($tolerance) <= 0);

        $row = OperationalCostReconciliation::updateOrCreate(['invoice_item_id' => $item->id], [
            'invoice_id' => $invoice->id,
            'basis' => OperationalCostReconciliation::BASIS_STATEMENTS,
            'status' => $within ? OperationalCostReconciliation::MATCHED : OperationalCostReconciliation::VARIANCE,
            'estimated_entry_id' => null,
            'actual_entry_id' => null,
            'currency' => $item->currency,
            'estimated_amount' => $booked->toStorage(),
            'actual_amount' => Money::of($item->amount)->toStorage(),
            'variance_amount' => $variance->toStorage(),
            'variance_percent' => $percent?->toScale(4),
            'tolerance_percent' => $tolerance->toScale(4),
        ]);
        $item->forceFill(['match_status' => $within ? OperationalCostInvoiceItem::MATCHED : OperationalCostInvoiceItem::PARTIAL, 'entry_id' => null])->save();

        return $row;
    }

    /**
     * A person accepted (or wrote off) the difference between the invoice and the statements: the invoice stands, so the difference is the invoice less what the statements
     * booked NOW. When more statements arrive after that decision the adjustment already booked is reversed and the new difference is booked in its place.
     */
    private function followTheStatements(OperationalCostReconciliation $row, OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item, Money $booked): void
    {
        if ($row->basis !== OperationalCostReconciliation::BASIS_STATEMENTS || $row->status !== OperationalCostReconciliation::RESOLVED || !in_array($row->resolution, ['accept', 'write_off'], true)) {
            return;
        }
        $difference = Money::of($item->amount)->minus($booked);
        if ($difference->equals(Money::ofNullable($row->variance_amount) ?? Money::zero())) {
            return;
        }

        $previous = $row->actual_entry_id === null ? null : OperationalCostEntry::find($row->actual_entry_id);
        if ($previous !== null && $previous->state->isSettled()) {
            $this->ledger->reverse($previous, null, 'The channel statements changed: the difference to the invoice is booked again.');
        }
        $entry = $this->bookDifference($row, $invoice, $item, $difference, (string) $row->note);
        if ($entry !== null) {
            $this->ledger->markReconciled($entry, null);
        }
        $row->forceFill([
            'estimated_amount' => $booked->toStorage(),
            'variance_amount' => $difference->toStorage(),
            'variance_percent' => $booked->isZero() ? null : $difference->times(100)->dividedBy($booked->abs(), 4)->toScale(4),
            'actual_entry_id' => $entry?->id,
        ])->save();
    }

    /**
     * Compares a purchase invoice line linked to a purchase order line with what the order says its goods cost (the order's unit cost for the quantity billed). The layer of those
     * goods is the order's and keeps the order's cost: this only says how far the invoice is from it, within the same tolerance, for a person to accept, dispute or write off. A cost
     * in another currency than the invoice's is not subtracted from it: a person compares them.
     */
    public function compareWithPurchaseOrder(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item, ?Money $expected, ?string $expectedCurrency): OperationalCostReconciliation
    {
        $existing = OperationalCostReconciliation::where('invoice_item_id', $item->id)->first();
        if ($existing !== null && in_array($existing->status, [OperationalCostReconciliation::RESOLVED, OperationalCostReconciliation::DISPUTED], true)) {
            return $existing;
        }

        $comparable = $expected !== null && $expectedCurrency === $item->currency;
        $tolerance = $this->toleranceFor($invoice);
        $variance = $comparable ? Money::of($item->amount)->minus($expected) : null;
        $percent = $variance === null || $expected->isZero() ? null : $variance->times(100)->dividedBy($expected->abs(), 4);
        $within = $variance !== null && ($variance->isZero() || ($percent !== null && $percent->abs()->compareTo($tolerance) <= 0));

        $row = OperationalCostReconciliation::updateOrCreate(['invoice_item_id' => $item->id], [
            'invoice_id' => $invoice->id,
            'basis' => OperationalCostReconciliation::BASIS_PURCHASE_ORDER,
            'status' => $within ? OperationalCostReconciliation::MATCHED : OperationalCostReconciliation::VARIANCE,
            'estimated_entry_id' => null,
            'actual_entry_id' => null,
            'currency' => $item->currency,
            'estimated_amount' => $comparable ? $expected->toStorage() : null,
            'actual_amount' => Money::of($item->amount)->toStorage(),
            'variance_amount' => $variance?->toStorage(),
            'variance_percent' => $percent?->toScale(4),
            'tolerance_percent' => $tolerance->toScale(4),
        ]);
        $item->forceFill(['match_status' => $within ? OperationalCostInvoiceItem::MATCHED : OperationalCostInvoiceItem::PARTIAL, 'entry_id' => null])->save();

        return $row;
    }

    /** How many lines of an invoice were compared with the statements instead of being posted. */
    public function comparedWithStatements(OperationalCostInvoice $invoice): int
    {
        return OperationalCostReconciliation::where('invoice_id', $invoice->id)->where('basis', OperationalCostReconciliation::BASIS_STATEMENTS)->count();
    }

    /** Estimates in the invoice's period, from the same company, that no invoice line charged. @return Collection<int, OperationalCostEntry> */
    public function unbilledEstimates(OperationalCostInvoice $invoice): Collection
    {
        $from = CarbonImmutable::parse($invoice->period_start ?? $invoice->invoice_date)->startOfDay();
        $to = CarbonImmutable::parse($invoice->period_end ?? $invoice->invoice_date)->endOfDay();

        return OperationalCostEntry::query()
            ->where('seller_id', $invoice->seller_id)
            ->where('provider', $invoice->provider_code)
            ->where('state', CostState::Estimated->value)
            ->where('is_effective', true)
            ->whereBetween('period_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('period_date')
            ->get();
    }

    /**
     * A person decides what to do with a variance: `accept` (the charge stands and is reconciled), `dispute` (we contest it), or `write_off`
     * (we accept the difference as a loss without contesting).
     */
    public function resolve(OperationalCostReconciliation $reconciliation, string $resolution, ?int $adminId, string $note): OperationalCostReconciliation
    {
        if (!in_array($resolution, ['accept', 'dispute', 'write_off'], true)) {
            throw new InvalidArgumentException('Unknown resolution.');
        }
        if (trim($note) === '') {
            throw new InvalidArgumentException('A resolution needs a note: it is kept in the audit trail.');
        }
        $entry = $reconciliation->actual_entry_id === null ? null : OperationalCostEntry::find($reconciliation->actual_entry_id);
        if ($entry === null && $reconciliation->basis === OperationalCostReconciliation::BASIS_STATEMENTS && $resolution !== 'dispute') {
            $item = OperationalCostInvoiceItem::find($reconciliation->invoice_item_id);
            $invoice = $item === null ? null : OperationalCostInvoice::find($item->invoice_id);
            $entry = $item === null || $invoice === null ? null : $this->bookDifference($reconciliation, $invoice, $item, Money::ofNullable($reconciliation->variance_amount) ?? Money::zero(), $note);
            $reconciliation->actual_entry_id = $entry?->id;
        }

        match ($resolution) {
            'accept', 'write_off' => $entry !== null ? $this->ledger->markReconciled($entry, $adminId) : null,
            'dispute' => $entry !== null ? $this->ledger->dispute($entry, $note) : null,
        };

        $reconciliation->forceFill([
            'status' => $resolution === 'dispute' ? OperationalCostReconciliation::DISPUTED : OperationalCostReconciliation::RESOLVED,
            'resolution' => $resolution,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
            'note' => mb_substr($note, 0, 500),
        ])->save();

        if ($reconciliation->invoice_item_id !== null) {
            OperationalCostInvoiceItem::whereKey($reconciliation->invoice_item_id)->update([
                'match_status' => $resolution === 'dispute' ? OperationalCostInvoiceItem::DISPUTED : OperationalCostInvoiceItem::MATCHED,
            ]);
        }
        $this->audit->record(action: 'operational_cost.reconciliation_resolved', subject: $reconciliation, context: ['resolution' => $resolution, 'by' => $adminId, 'note' => $note]);

        return $reconciliation;
    }

    /**
     * The invoice stands: what it bills beyond (or below) what the statements booked is booked as an adjustment of the same fee, so that the books say what the invoice says.
     * A difference of nothing books nothing; each difference has a slot of its own, so a newer one never meets the entry of an older one.
     */
    private function bookDifference(OperationalCostReconciliation $reconciliation, OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item, Money $difference, string $note): ?OperationalCostEntry
    {
        if ($difference->isZero()) {
            return null;
        }

        return $this->ledger->record(new CostDraft(
            subjectType: 'invoice_item',
            subjectId: (int) $item->id,
            component: CostComponent::tryFrom((string) $item->cost_component) ?? CostComponent::MarketplaceAdjustment,
            state: CostState::Actual,
            amount: $difference,
            currency: $item->currency,
            periodDate: CarbonImmutable::parse($item->service_date ?? $invoice->period_end ?? $invoice->invoice_date),
            source: 'invoice',
            identity: 'statement-difference:' . $reconciliation->id . ':' . $difference->toStorage(),
            serviceRef: 'statement-difference:' . $difference->toStorage(),
            sellerId: (int) $invoice->seller_id,
            salesChannel: $invoice->provider_code,
            accountId: $invoice->account_id,
            provider: $invoice->provider_code,
            invoiceItemId: (int) $item->id,
            externalReference: $invoice->invoice_number,
            note: mb_substr('The difference between the invoice and the channel statements. ' . $note, 0, 500),
            occurredAt: CarbonImmutable::parse($item->service_date ?? $invoice->period_end ?? $invoice->invoice_date),
            allocationStatus: 'unallocated',
        ))->entry;
    }

    private function reconcileItem(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item): OperationalCostReconciliation
    {
        $existing = OperationalCostReconciliation::where('invoice_item_id', $item->id)->first();
        if ($existing !== null && in_array($existing->status, [OperationalCostReconciliation::RESOLVED, OperationalCostReconciliation::DISPUTED], true)) {
            return $existing;
        }

        $actual = OperationalCostEntry::findOrFail($item->entry_id);
        $estimate = OperationalCostEntry::where('superseded_by_id', $actual->id)->where('state', CostState::Estimated->value)->orderByDesc('id')->first();
        $tolerance = $this->toleranceFor($invoice);

        $values = ['invoice_id' => $invoice->id, 'actual_entry_id' => $actual->id, 'currency' => $actual->currency, 'actual_amount' => $actual->amount, 'tolerance_percent' => $tolerance->toScale(4)];

        if ($estimate === null || $estimate->amount === null) {
            $values += ['status' => OperationalCostReconciliation::UNMATCHED_INVOICE, 'estimated_entry_id' => null, 'estimated_amount' => null, 'variance_amount' => null, 'variance_percent' => null];
        } elseif ($estimate->currency !== $actual->currency) {
            // Amounts in two currencies are not subtracted from each other: a person compares them.
            $values += ['status' => OperationalCostReconciliation::VARIANCE, 'estimated_entry_id' => $estimate->id, 'estimated_amount' => $estimate->amount, 'variance_amount' => null, 'variance_percent' => null];
        } else {
            $variance = Money::of($actual->amount)->minus($estimate->amount);
            $percent = Money::of($estimate->amount)->isZero() ? null : $variance->times(100)->dividedBy(Money::of($estimate->amount)->abs(), 4);
            $within = $variance->isZero() || ($percent !== null && $percent->abs()->compareTo($tolerance) <= 0);
            $values += [
                'status' => $within ? OperationalCostReconciliation::MATCHED : OperationalCostReconciliation::VARIANCE,
                'estimated_entry_id' => $estimate->id,
                'estimated_amount' => $estimate->amount,
                'variance_amount' => $variance->toStorage(),
                'variance_percent' => $percent?->toScale(4),
            ];
        }

        $row = OperationalCostReconciliation::updateOrCreate(['invoice_item_id' => $item->id], $values);
        if ($row->status === OperationalCostReconciliation::MATCHED) {
            $this->ledger->markReconciled($actual, null);
            $item->forceFill(['match_status' => OperationalCostInvoiceItem::MATCHED])->save();
        } elseif ($row->status === OperationalCostReconciliation::VARIANCE) {
            $item->forceFill(['match_status' => OperationalCostInvoiceItem::PARTIAL])->save();
        }

        return $row;
    }

    private function toleranceFor(OperationalCostInvoice $invoice): Money
    {
        $contract = OperationalCostContract::query()->inForce()->where('seller_id', $invoice->seller_id)->where('provider_code', $invoice->provider_code)
            ->whereDate('effective_from', '<=', $invoice->period_end ?? $invoice->invoice_date)->orderByDesc('effective_from')->first();

        return Money::of($contract->settings['tolerance_percent'] ?? config('operational_costs.reconciliation.tolerance_percent', 2));
    }
}
