<?php

namespace App\Services\Accounting\Odoo;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\OdooPosting;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostReturnDecision;

/**
 * Reads, from the ledger's own records, what one entry takes back.
 *
 * **IT RESOLVES NOTHING BY RESEMBLANCE.** Two entries with the same subject, component and opposite amounts may be a pair or may
 * be two separate corrections, and guessing would link an accountant's reversal to the wrong entry — which is worse than leaving
 * it unlinked, because a wrong link reads as a verified one. So only two things count as a link, and the ledger writes both down
 * itself:
 *
 *  - `calc_inputs['reverses']`, which `CostLedger::reverse()` sets to the id of the entry it cancels;
 *  - a row in `operational_cost_return_decisions` whose `entry_id` is this entry, which is how a return records the entry that
 *    took its units' cost of goods back off the ledger.
 *
 * The cost of that discipline is that an entry somebody wrote by hand to offset another is NOT a link and posts on its own. That
 * is the right way round: it is a separate fact until the ledger says otherwise.
 */
class OdooUndoLinks
{
    /** The components a line's cost of goods is recorded under; a return takes one of these back. */
    private const GOODS_COMPONENTS = [CostComponent::Cogs, CostComponent::SupplierCost];

    public function for(OperationalCostEntry $entry): OdooUndoLink
    {
        $reverses = $entry->calc_inputs['reverses'] ?? null;
        if (is_numeric($reverses) && (int) $reverses > 0) {
            return OdooUndoLink::reversalOf((int) $reverses, $this->queueRowFor((int) $reverses));
        }

        $decision = OperationalCostReturnDecision::where('entry_id', $entry->id)->orderBy('id')->first();
        if ($decision === null) {
            return OdooUndoLink::none();
        }

        $original = $this->goodsEntryOfLine($decision);

        return OdooUndoLink::returnOf(
            $original === null ? null : (int) $original->id,
            $original === null ? null : $this->queueRowFor((int) $original->id),
            (string) $decision->return_event,
        );
    }

    /**
     * The entry that recorded the cost of the goods this return sends back — the sale line's own, not another return's.
     *
     * Every restock entry shares the line's subject and its component, so the restocks have to come out of the candidates or a
     * return would "undo" the return before it. They are excluded by id, from the decisions table: an entry a return decision
     * names IS a restock entry, which is a recorded fact and not a reading of the row.
     */
    private function goodsEntryOfLine(OperationalCostReturnDecision $decision): ?OperationalCostEntry
    {
        $restockEntryIds = OperationalCostReturnDecision::where('target_type', $decision->target_type)
            ->where('target_id', $decision->target_id)
            ->whereNotNull('entry_id')
            ->pluck('entry_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return OperationalCostEntry::where('subject_type', $decision->target_type)
            ->where('subject_id', $decision->target_id)
            ->whereIn('cost_component', array_map(static fn (CostComponent $component): string => $component->value, self::GOODS_COMPONENTS))
            ->where('is_effective', true)
            ->when($restockEntryIds !== [], fn ($query) => $query->whereNotIn('id', $restockEntryIds))
            ->orderBy('id')
            ->first();
    }

    /**
     * The queue row that speaks for one ledger entry, of however many versions of it were queued.
     *
     * A POSTED row wins whatever its version, because the question this answers is "is what that entry says in the customer's
     * books?" and a posted row is the one that put it there. Only when none is posted does the newest row speak — it is the one
     * still trying.
     */
    private function queueRowFor(int $entryId): ?OdooPosting
    {
        $rows = OdooPosting::where('source_type', OdooPostingKey::SOURCE_COST_ENTRY)
            ->where('source_id', $entryId)
            ->orderByDesc('id')
            ->get();

        return $rows->first(static fn (OdooPosting $row): bool => $row->isPosted()) ?? $rows->first();
    }
}
