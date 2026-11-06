<?php

namespace App\Services\OperationalCosts;

use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of the cost ledger.
 *
 * What it guarantees, whoever calls it and however often:
 *  - the same computation or document is recorded once (unique `dedupe_key`; a retry, a re-sync or a webhook delivered twice
 *    maps to the row that already exists);
 *  - one service of one subject is one cost: a better-known cost of the slot (actual over estimate, estimate over missing)
 *    supersedes the weaker one, and a weaker one never displaces a better one, so an estimate and the real charge are never both counted;
 *  - an approved or reconciled entry is never rewritten: a conflicting newer figure is stored beside it, outside the totals, as a
 *    disputed entry for a person to resolve;
 *  - nothing is ever deleted: a mistake is reversed with a new entry.
 */
class CostLedger
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function record(CostDraft $draft): RecordOutcome
    {
        $existing = OperationalCostEntry::where('dedupe_key', $draft->dedupeKey())->first();
        if ($existing !== null) {
            return new RecordOutcome(RecordOutcome::DUPLICATE, $existing);
        }

        try {
            return DB::transaction(fn (): RecordOutcome => $this->write($draft));
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker recorded this very computation first.
            return new RecordOutcome(RecordOutcome::DUPLICATE, OperationalCostEntry::where('dedupe_key', $draft->dedupeKey())->firstOrFail());
        }
    }

    public function approve(OperationalCostEntry $entry, ?int $adminId, ?string $note = null): OperationalCostEntry
    {
        if ($entry->state === CostState::Missing || $entry->state === CostState::Estimated) {
            throw new InvalidArgumentException('Only an actual cost can be approved: an estimate or a missing cost has nothing to approve.');
        }
        if ($entry->approved_at === null) {
            $entry->forceFill(['approved_at' => now(), 'approved_by' => $adminId, 'note' => $note ?? $entry->note])->save();
            $this->audit->record(action: 'operational_cost.entry_approved', subject: $entry, context: ['entry_id' => $entry->id]);
        }

        return $entry;
    }

    public function markReconciled(OperationalCostEntry $entry, ?int $adminId): OperationalCostEntry
    {
        if ($entry->state === CostState::Reconciled) {
            return $entry;
        }
        if ($entry->state !== CostState::Actual) {
            throw new InvalidArgumentException('Only an actual cost can be reconciled with an invoice.');
        }
        $entry->forceFill(['state' => CostState::Reconciled, 'approved_at' => $entry->approved_at ?? now(), 'approved_by' => $entry->approved_by ?? $adminId])->save();
        $this->audit->record(action: 'operational_cost.entry_reconciled', subject: $entry, context: ['entry_id' => $entry->id]);

        return $entry;
    }

    /** The party charged something we contest: the entry leaves every total until the dispute is resolved. */
    public function dispute(OperationalCostEntry $entry, string $reason): OperationalCostEntry
    {
        if ($entry->state === CostState::Missing) {
            throw new InvalidArgumentException('A missing cost cannot be disputed.');
        }
        $entry->forceFill(['state' => CostState::Disputed, 'note' => mb_substr($reason, 0, 500)])->save();
        $this->audit->record(action: 'operational_cost.entry_disputed', subject: $entry, context: ['entry_id' => $entry->id, 'reason' => $reason]);

        return $entry;
    }

    /**
     * A dispute is settled: the charge stands (it becomes actual again) or it does not (it leaves the totals; a reversal is recorded
     * in its place).
     */
    public function resolveDispute(OperationalCostEntry $entry, bool $chargeStands, ?int $adminId, string $note): OperationalCostEntry
    {
        if ($entry->state !== CostState::Disputed) {
            throw new InvalidArgumentException('Only a disputed cost can be resolved.');
        }
        if ($chargeStands) {
            $entry->forceFill(['state' => CostState::Actual, 'note' => mb_substr($note, 0, 500)])->save();
        } else {
            $this->reverse($entry, $adminId, $note);
        }
        $this->audit->record(action: 'operational_cost.dispute_resolved', subject: $entry, context: ['entry_id' => $entry->id, 'charge_stands' => $chargeStands]);

        return $entry;
    }

    /** Cancels an entry's effect with an equal and opposite one. Safe to call twice: the reversal is one row. */
    public function reverse(OperationalCostEntry $entry, ?int $adminId, string $reason): ?OperationalCostEntry
    {
        if ($entry->amount === null) {
            return null;
        }

        $reversal = new CostDraft(
            subjectType: $entry->subject_type,
            subjectId: $entry->subject_id,
            component: $entry->component() ?? throw new InvalidArgumentException('Unknown cost component.'),
            state: CostState::Actual,
            amount: Money::of($entry->amount)->negated(),
            currency: $entry->currency,
            periodDate: now(),
            source: 'manual',
            identity: 'reversal-of-' . $entry->id,
            serviceRef: 'reversal:' . $entry->id,
            sellerId: (int) $entry->seller_id,
            salesChannel: $entry->sales_channel,
            accountId: $entry->account_id,
            orderId: $entry->order_id,
            externalOrderMappingId: $entry->external_order_mapping_id,
            providerFulfillmentId: $entry->provider_fulfillment_id,
            provider: $entry->provider,
            inventoryLocationId: $entry->inventory_location_id,
            productId: $entry->product_id,
            variantKey: (string) $entry->variant_key,
            sku: $entry->sku,
            externalReference: $entry->external_reference,
            note: mb_substr($reason, 0, 500),
            occurredAt: now(),
        );

        $outcome = $this->record($reversal);
        if ($outcome->wroteSomething()) {
            $this->audit->record(action: 'operational_cost.entry_reversed', subject: $entry, context: ['entry_id' => $entry->id, 'reversal_id' => $outcome->entry->id, 'by' => $adminId, 'reason' => $reason]);
        }

        return $outcome->entry;
    }

    private function write(CostDraft $draft): RecordOutcome
    {
        $current = OperationalCostEntry::where('slot_key', $draft->slotKey())->where('is_effective', true)->lockForUpdate()->get();
        $incomingRank = $draft->state->rank();
        $superseded = [];
        $effective = true;
        $conflict = false;

        foreach ($current as $held) {
            $heldRank = $held->state->rank();
            $heldIsFinal = $held->approved_at !== null || $held->state === CostState::Reconciled;

            if ($heldRank > $incomingRank) {
                // Something better is already known (the real charge exists, a weaker figure arrived late): keep what we have.
                return new RecordOutcome(RecordOutcome::IGNORED, $held);
            }
            if ($heldIsFinal && $draft->state !== CostState::Missing) {
                // A figure that was approved is not displaced by a different one. The newer one is kept for a person to look at.
                $effective = false;
                $conflict = true;
                continue;
            }
            if ($heldRank === $incomingRank && $draft->state === CostState::Missing) {
                return new RecordOutcome(RecordOutcome::IGNORED, $held);
            }
            $superseded[] = $held;
        }

        $entry = OperationalCostEntry::create([
            'seller_id' => $draft->sellerId,
            'dedupe_key' => $draft->dedupeKey(),
            'slot_key' => $draft->slotKey(),
            'subject_type' => $draft->subjectType,
            'subject_id' => $draft->subjectId,
            'sales_channel' => $draft->salesChannel,
            'account_id' => $draft->accountId,
            'order_id' => $draft->orderId,
            'external_order_mapping_id' => $draft->externalOrderMappingId,
            'provider_fulfillment_id' => $draft->providerFulfillmentId,
            'provider' => $draft->provider,
            'inventory_location_id' => $draft->inventoryLocationId,
            'product_id' => $draft->productId,
            'variant_key' => $draft->variantKey,
            'sku' => $draft->sku,
            'period_date' => $draft->periodDate->copy()->setTimezone(date_default_timezone_get())->toDateString(),
            'cost_component' => $draft->component->value,
            'cost_class' => $draft->component->costClass()->value,
            'state' => $conflict ? CostState::Disputed : $draft->state,
            'is_effective' => $effective,
            'amount' => $draft->amount?->toStorage(),
            'currency' => $draft->currency,
            'fx_rate' => $draft->fxRate?->toScale(10),
            'base_amount' => $draft->baseAmount?->toStorage(),
            'base_currency' => $draft->baseCurrency,
            'quantity' => $draft->quantity?->toStorage(),
            'unit_amount' => $draft->unitAmount?->toStorage(),
            'allocation_status' => $draft->allocationStatus,
            'source' => $draft->source,
            'contract_id' => $draft->contractId,
            'contract_version' => $draft->contractVersion,
            'rule_id' => $draft->ruleId,
            'invoice_item_id' => $draft->invoiceItemId,
            'external_reference' => $draft->externalReference,
            'calc_inputs' => $draft->calcInputs === [] ? null : $draft->calcInputs,
            'missing_reason' => $draft->missingReason,
            'note' => $conflict ? 'conflicts_with_approved_entry' : $draft->note,
            'occurred_at' => $draft->occurredAt,
        ]);

        foreach ($superseded as $held) {
            $held->forceFill(['is_effective' => false, 'superseded_by_id' => $entry->id])->save();
        }

        return new RecordOutcome($conflict ? RecordOutcome::CONFLICT : RecordOutcome::CREATED, $entry, array_map(static fn (OperationalCostEntry $held): int => (int) $held->id, $superseded));
    }
}
