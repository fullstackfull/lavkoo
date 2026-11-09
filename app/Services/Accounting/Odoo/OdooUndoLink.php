<?php

namespace App\Services\Accounting\Odoo;

use App\Models\OdooPosting;

/**
 * What one ledger entry UNDOES, and whether that lets it be posted yet.
 *
 * Two of our facts take back an earlier one, and the accounting policy is the same for both: **a figure already in the books is
 * corrected by a new entry, never by editing or deleting the old one** (docs/sales-channels/odoo-accounting.md §4). So neither of
 * these is an edit, a delete or a second version of anything — each is its own journal entry, and what makes it readable to an
 * accountant is that it SAYS which entry it takes back.
 *
 *  - **A reversal.** `CostLedger::reverse()` writes an equal and opposite entry and records `calc_inputs['reverses']`: the id of
 *    the entry it cancels. A contested charge that did not stand gets one; so does a correction.
 *  - **A return to stock.** Units come back on the shelf, re-enter the layers at the cost they left at, and that part of the
 *    line's cost of goods comes back off the ledger. `operational_cost_return_decisions` records which entry did it, which line
 *    it was for, and against which return event — so this link is a recorded fact rather than a resemblance between two rows.
 *
 * ## The one rule that needs a guard rather than a comment
 *
 * **An undo must not reach the books before the entry it undoes.** Posting the credit alone leaves the expense account short by
 * the amount, and a trial balance that is wrong in that direction looks exactly like a cost nobody ever recorded. So where the
 * original is queued for Odoo and not yet in it, this refuses — by name, and in a way that resolves itself the moment the
 * original lands.
 *
 * What it does NOT do is hold an undo hostage to an entry Odoo was never going to receive. A shop that connects its accountant
 * today has months of settled costs that were never queued; a return against one of those is a real movement of goods whose own
 * entry is the only record there will be of it, and refusing it for ever would understate nothing and lose everything. So "no
 * queue row at all" posts, and says in the entry's own narration that the original was never queued.
 */
final readonly class OdooUndoLink
{
    /** This entry takes nothing back. */
    public const NONE = 'none';

    /** `calc_inputs['reverses']`: the ledger cancelled an entry with an equal and opposite one. */
    public const REVERSAL = 'reversal';

    /** A return decision's own ledger entry: units back on the shelf, their cost of goods off the ledger. */
    public const RETURN_TO_STOCK = 'return_to_stock';

    /**
     * @param string $kind one of the three above
     * @param int|null $originalEntryId the ledger entry this one takes back, where the ledger records which
     * @param OdooPosting|null $originalPosting that entry's queue row, where it has one
     * @param string|null $returnEvent the return event this undo belongs to, for a return
     */
    private function __construct(
        public string $kind,
        public ?int $originalEntryId = null,
        public ?OdooPosting $originalPosting = null,
        public ?string $returnEvent = null,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function reversalOf(int $originalEntryId, ?OdooPosting $originalPosting): self
    {
        return new self(self::REVERSAL, $originalEntryId, $originalPosting);
    }

    public static function returnOf(?int $originalEntryId, ?OdooPosting $originalPosting, string $returnEvent): self
    {
        return new self(self::RETURN_TO_STOCK, $originalEntryId, $originalPosting, $returnEvent);
    }

    public function isUndo(): bool
    {
        return $this->kind !== self::NONE;
    }

    /**
     * What stops this being posted now, or null when nothing does.
     *
     * The three nulls are as deliberate as the two refusals:
     *
     *  - not an undo, or the ledger records no original → there is nothing to be out of order with;
     *  - the original has no queue row → it was never in scope for Odoo (see the class note), so this entry stands alone;
     *  - the original is posted → the pair is in order, and this entry can name it.
     */
    public function refusal(): ?string
    {
        if (!$this->isUndo() || $this->originalEntryId === null || $this->originalPosting === null) {
            return null;
        }
        if ($this->originalPosting->isPosted()) {
            return null;
        }

        // The original will never be in the books -- not postable, or a person closed it as absent -- so there is nothing for
        // this entry to take back. Posting it would credit an expense account that was never debited. Terminal: no amount of
        // waiting changes a row that is already at rest.
        if ($this->originalPosting->state === OdooPosting::DEAD) {
            return 'odoo_original_entry_will_never_be_posted';
        }

        // Everything else -- queued, fenced, failing, awaiting a person -- is a WAIT rather than a failure, and it costs the row
        // no attempt: it is not this entry that is wrong. The state travels in the code so a person reading the row can see
        // which row to go and look at.
        return 'odoo_original_entry_is_not_posted_yet:' . (string) $this->originalPosting->state;
    }

    /** The original's entry id AT ODOO, for the one field that makes the pair visible in Odoo's own interface. */
    public function odooMoveId(): ?int
    {
        return $this->originalPosting?->isPosted() === true ? (int) $this->originalPosting->odoo_move_id : null;
    }

    /**
     * The line of the entry's note that says what it takes back — including when it could not be told.
     *
     * An unresolved link is written down rather than left out. An accountant who sees "the entry this takes back was not
     * identified" knows to go looking; one who sees nothing assumes there was nothing to say.
     */
    public function describe(): ?string
    {
        if (!$this->isUndo()) {
            return null;
        }

        $what = $this->kind === self::RETURN_TO_STOCK
            ? 'returns units to stock' . ($this->returnEvent === null ? '' : ' (return ' . $this->returnEvent . ')')
            : 'reverses';
        if ($this->originalEntryId === null) {
            return 'This entry ' . $what . ', and the ledger entry it takes back was not identified.';
        }

        $original = 'PharmacyKSA ledger entry #' . $this->originalEntryId;
        if ($this->originalPosting === null) {
            return 'This entry ' . $what . ' ' . $original . ', which was never queued for Odoo.';
        }
        if ($this->originalPosting->isPosted()) {
            return 'This entry ' . $what . ' ' . $original . ', posted here as ' . (string) $this->originalPosting->odoo_ref
                . ' (entry id ' . (int) $this->originalPosting->odoo_move_id . ').';
        }

        // Reachable only if a caller posts despite `refusal()`; said plainly rather than left to look like a clean pair.
        return 'This entry ' . $what . ' ' . $original . ', whose own entry is not in Odoo.';
    }

    /** A short label for the line name in Odoo, so a credit is recognisable as a reversal at a glance. */
    public function lineLabel(): ?string
    {
        return match ($this->kind) {
            self::REVERSAL => 'reversal of #' . (int) $this->originalEntryId,
            self::RETURN_TO_STOCK => 'return to stock',
            default => null,
        };
    }
}
