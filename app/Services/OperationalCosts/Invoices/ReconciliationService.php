<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostContract;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Models\OperationalCostReconciliation;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
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
 * A marketplace invoice line that is a total for a period with no order on it is compared with what the channel's statements already booked for the same fee and period
 * (`compareWithStatements`), within the same tolerance, instead of being posted as a second set of actual costs. A difference beyond the tolerance waits for a person; when it is
 * accepted (or written off) the difference is booked as an adjustment, so the books say what the invoice says without counting any fee twice.
 *
 * The tolerance is the contract's own (`settings.tolerance_percent`) or the configured default. Running it again changes nothing that a
 * person already decided.
 */
class ReconciliationService
{
    public function __construct(
        private readonly CostLedger $ledger,
        private readonly AuditLogger $audit,
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
     * Compares an invoice line that bills a fee for a whole period with the total the statements booked for it. Nothing is posted: within the tolerance the statements stand; beyond
     * it the line is a VARIANCE for a person. Running it again leaves a row a person already decided alone.
     */
    public function compareWithStatements(OperationalCostInvoice $invoice, OperationalCostInvoiceItem $item, Money $booked): OperationalCostReconciliation
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
            $entry = $this->bookDifference($reconciliation, $note);
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
     * The invoice stands: what it bills beyond (or below) what the statements booked is booked as an adjustment of the same fee, once, so that the books say what the invoice says.
     * A difference of nothing books nothing.
     */
    private function bookDifference(OperationalCostReconciliation $reconciliation, string $note): ?OperationalCostEntry
    {
        $difference = Money::ofNullable($reconciliation->variance_amount);
        $item = OperationalCostInvoiceItem::find($reconciliation->invoice_item_id);
        $invoice = $item === null ? null : OperationalCostInvoice::find($item->invoice_id);
        if ($difference === null || $difference->isZero() || $item === null || $invoice === null) {
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
            identity: 'statement-difference:' . $reconciliation->id,
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
