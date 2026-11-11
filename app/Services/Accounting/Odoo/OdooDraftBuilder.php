<?php

namespace App\Services\Accounting\Odoo;

use App\Enums\OperationalCosts\CostState;
use App\Models\OdooAccountMapping;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Support\Money;

/**
 * Turns ONE settled cost from our own ledger into ONE draft journal entry for the customer's Odoo.
 *
 * **IT COMPUTES NOTHING.** `OperationalCosts` is the only engine for costs, FIFO, commissions and profitability; this reads an
 * approved figure and expresses it in the customer's chart of accounts. No amount is derived, allocated or re-rounded here
 * beyond the two decimal places a monetary field holds, and that rounding happens in one place (`OdooMoney`).
 *
 * ## What it refuses, and why each refusal is a refusal rather than a default
 *
 *  - **An estimate.** The ledger distinguishes an estimate from the charge that arrived (`CostState`), and posting an estimate as
 *    a real expense is an accounting policy somebody has to approve, not a technical choice. Until there is one, only `actual`
 *    and `reconciled` are posted and the estimate is shown for analysis.
 *  - **A superseded row.** When the real charge arrives it supersedes the estimate; posting both would book the same service
 *    twice.
 *  - **A cost with no amount.** `missing` means nobody could determine it. That is not a zero, and a zero in a ledger is a claim.
 *  - **An unmapped component.** There is no fallback account and no suspense account: an entry posted to an account nobody chose
 *    is wrong in a way that looks right until somebody reconciles the quarter.
 *  - **A foreign-currency amount this instance cannot record, or whose currency its own list does not offer.** Posting it in the
 *    company currency and dropping the original would misstate the books silently, and Odoo's id for a currency is read from the
 *    instance rather than derived from the code.
 *  - **An entry that does not balance.** Odoo refuses it anyway; a named refusal beats a fault nobody can read.
 *  - **An undo whose original is not in the books yet.** A reversal or a return takes back a figure, and posting the credit
 *    before the debit leaves the expense account short by the amount -- which on a trial balance is indistinguishable from a cost
 *    nobody ever recorded. It waits, and it stops waiting by itself the moment the original lands. An original that was never
 *    queued for Odoo at all is NOT this case: see `OdooUndoLink`.
 *
 * ## A figure already in the books is never edited here
 *
 * The policy is the owner's and it is absolute: a posted figure is corrected by a reversing or adjusting entry, never by
 * changing or deleting a final one, and a return is its own entry linked to the sale's. So this builder has exactly one shape of
 * output -- a new draft entry -- for a cost, for its reversal and for a return alike. The difference between them is which
 * column the figure sits in and which entry the new one NAMES, both of which are data; none of them is a write to an entry that
 * already exists. `OdooOperations::deliberatelyNotRegistered()` keeps `write` and `unlink` off the allow-list so that stays true
 * however this file changes.
 *
 * ## And the field names are the instance's, not ours
 *
 * Odoo's developer reference has no page for `account.move` or `account.move.line`, so every field written here is one
 * `OdooPreflight` found on the customer's own instance. A required field the instance does not have is a named refusal; an
 * optional one it does not have is simply not written.
 */
class OdooDraftBuilder
{
    public function __construct(
        private readonly OdooAccountMap $map,
        private readonly OdooUndoLinks $undoLinks,
    ) {
    }

    /** The cost states that may be posted at all. An estimate is not among them, and that is a policy decision, not a bug. */
    private const POSTABLE_STATES = [CostState::Actual, CostState::Reconciled];

    public function forCostEntry(OperationalCostEntry $entry, OdooPreflight $preflight, int $sellerId = 0): OdooDraftResult
    {
        if (!$preflight->couldBuildAnEntry()) {
            return OdooDraftResult::refused($preflight->readinessCodes());
        }

        $refusals = $this->whatIsWrongWith($entry);
        if ($refusals !== []) {
            return OdooDraftResult::refused($refusals);
        }

        $channel = (string) ($entry->sales_channel ?? '');
        $currency = (string) $entry->currency;
        $component = (string) $entry->cost_component;
        $companyId = (int) ($preflight->company['id'] ?? 0);
        if ($companyId < 1) {
            return OdooDraftResult::refused(['odoo_company_was_not_read']);
        }

        $accounts = $this->map->forCostComponent($companyId, $component, $channel, $currency, $sellerId);
        if ($accounts['missing'] !== []) {
            return OdooDraftResult::refused($accounts['missing']);
        }
        $expense = $accounts['component'];
        $counterpart = $accounts['counterpart'];

        $currencyId = (int) ($preflight->company['currency_id'] ?? 0);
        if ($currencyId < 1) {
            return OdooDraftResult::refused(['odoo_company_currency_was_not_read']);
        }
        $foreign = $this->foreignCurrencyOf($entry, $preflight);
        if (is_string($foreign)) {
            return OdooDraftResult::refused([$foreign]);
        }

        // The amount the entry is posted AT is the base-currency figure where the ledger has one, because that is the figure the
        // company's books are kept in; the original currency and its rate ride along on the line and in the narration.
        $amount = Money::of((string) ($entry->base_amount ?? $entry->amount));
        $side = OdooMoney::debitAndCredit($amount);
        $counterSide = OdooMoney::counterOf($amount);
        // What this entry takes back, if anything. A negative amount already puts the figure in the other column by itself
        // (`OdooMoney`); what the link adds is WHICH entry it takes back, which is the part an accountant cannot infer.
        $undo = $this->undoLinks->for($entry);
        $name = $this->lineNameFor($entry, $component, $undo);

        $key = OdooPostingKey::forCostEntry($entry);
        $draft = new OdooEntryDraft(
            reference: $key->reference,
            companyId: $companyId,
            journalId: (int) $expense->odoo_journal_id,
            date: $this->dateOf($entry),
            currencyId: $currencyId,
            salesChannel: $channel,
            lines: [
                new OdooEntryLine(
                    accountId: (int) $expense->odoo_account_id,
                    name: $name,
                    debit: $side['debit'],
                    credit: $side['credit'],
                    taxIds: $expense->odoo_tax_id === null ? [] : [(int) $expense->odoo_tax_id],
                    analyticAccountId: $expense->odoo_analytic_account_id === null ? null : (int) $expense->odoo_analytic_account_id,
                    currencyId: $foreign['currency_id'] ?? null,
                    amountCurrency: $foreign['amount'] ?? null,
                ),
                new OdooEntryLine(
                    accountId: (int) $counterpart->odoo_account_id,
                    name: $name,
                    debit: $counterSide['debit'],
                    credit: $counterSide['credit'],
                    currencyId: $foreign['currency_id'] ?? null,
                    amountCurrency: $foreign['amount'] === null ? null : -$foreign['amount'],
                ),
            ],
            narration: $this->narrationFor($entry, $undo),
            reversedEntryId: $undo->odooMoveId(),
        );

        if (!$draft->balances()) {
            return OdooDraftResult::refused(['odoo_entry_does_not_balance']);
        }

        // **THE ORDER OF THE PAIR, CHECKED LAST ON PURPOSE.** An undo whose original is not yet in the books is otherwise a
        // perfectly good entry, so every refusal a person can act on -- an unmapped account, a currency with no map, a company
        // nobody read -- is reported before this one. Reporting this first would send somebody to look at another row while
        // their own mapping was still missing.
        $outOfOrder = $undo->refusal();

        return $outOfOrder === null
            ? OdooDraftResult::ready($draft, $key)
            : OdooDraftResult::refused([$outOfOrder]);
    }

    /** @return list<string> */
    private function whatIsWrongWith(OperationalCostEntry $entry): array
    {
        $refusals = [];
        if ($entry->state === CostState::Estimated) {
            // Named for what it is rather than lumped in with the others: this one is waiting on a policy, not on data.
            $refusals[] = 'cost_is_an_estimate_and_no_approved_policy_posts_estimates';
        } elseif (!in_array($entry->state, self::POSTABLE_STATES, true)) {
            $refusals[] = 'cost_is_not_settled:' . $entry->state->value;
        }
        if ($entry->amount === null && $entry->base_amount === null) {
            $refusals[] = 'cost_has_no_amount';
        }
        if (!$entry->is_effective) {
            $refusals[] = 'cost_was_superseded';
        }
        if (trim((string) $entry->cost_component) === '') {
            $refusals[] = 'cost_has_no_component';
        }

        return $refusals;
    }

    /**
     * Is there a figure in another currency that this entry would have to carry?
     *
     * When a cost was incurred in one currency and the books are kept in another, the line has to say so -- the original figure,
     * its currency and the rate -- or the entry cannot be checked against the invoice it came from. Posting only the converted
     * value and dropping the original misstates the books silently, so there are exactly three outcomes and two of them are
     * refusals:
     *
     *  - the instance has no `currency_id`/`amount_currency` on a journal line: it could not carry the original figure even with
     *    an id, which is a different thing to tell a person;
     *  - the instance's currency list does not offer this code, or the list was not usable at all (`OdooPreflight` explains
     *    which, and the connection screen shows it): **refused by name**, because Odoo's id for a currency is read from the
     *    instance and an id is not something to invent;
     *  - it does offer it: the figure rides along, **signed**.
     *
     * The sign is the direction, as everywhere else here: `amount_currency` is positive on a debit line and negative on a
     * credit, which is why the SIGNED ledger amount is passed through rather than its absolute value -- a reversal's original
     * figure is negative and its counterpart line negates it again. Odoo documents no `account.move.line` page, so that
     * convention is **UNVERIFIED** and recorded as such in docs/sales-channels/odoo-accounting.md.
     *
     * @return array{currency_id: int|null, amount: float|null}|string the refusal code when there is one
     */
    private function foreignCurrencyOf(OperationalCostEntry $entry, OdooPreflight $preflight): array|string
    {
        $base = trim((string) ($entry->base_currency ?? ''));
        $own = trim((string) $entry->currency);
        if ($base === '' || $own === '' || $base === $own || $entry->amount === null) {
            return ['currency_id' => null, 'amount' => null];
        }

        $lineFields = $preflight->fields['account.move.line'] ?? [];
        if (!isset($lineFields['currency_id'], $lineFields['amount_currency'])) {
            return 'odoo_cannot_record_a_foreign_currency_amount';
        }

        $currencyId = $preflight->currencyIdFor($own);

        return $currencyId === null
            ? 'odoo_currency_not_in_this_instance:' . $own
            : ['currency_id' => $currencyId, 'amount' => OdooMoney::forMonetaryField(Money::of((string) $entry->amount))];
    }

    /** The date the entry is booked on: the ledger's own period date, which is what every cost report groups by. */
    private function dateOf(OperationalCostEntry $entry): string
    {
        return ($entry->period_date ?? $entry->occurred_at ?? now())->format('Y-m-d');
    }

    /**
     * What the line is called in Odoo.
     *
     * A component, a channel and the ledger row's id — enough for an accountant to find it, and NO buyer detail: a journal entry
     * is not a place to put somebody's name or address, and this integration never has a reason to.
     */
    private function lineNameFor(OperationalCostEntry $entry, string $component, OdooUndoLink $undo): string
    {
        // The undo label goes FIRST, because a credit on an expense account is read as a mistake until something says it is a
        // reversal, and the first words of the line are what a trial balance shows.
        $parts = array_filter([$undo->lineLabel(), $component, (string) ($entry->sales_channel ?? ''), 'ledger #' . (int) $entry->id]);

        return mb_substr(implode(' · ', $parts), 0, 120);
    }

    /**
     * The note on the entry: where the figure came from, and the rate if one was used.
     *
     * The exchange rate is recorded because a converted figure that does not say what it was converted at cannot be checked.
     */
    private function narrationFor(OperationalCostEntry $entry, OdooUndoLink $undo): ?string
    {
        $lines = ['Lavkoo ledger entry #' . (int) $entry->id . ' (' . $entry->state->value . ')'];
        // Which entry this one takes back, INCLUDING when it could not be told: an accountant who reads that the original was
        // not identified knows to look, and one who reads nothing assumes there was nothing to say.
        $undoes = $undo->describe();
        if ($undoes !== null) {
            $lines[] = $undoes;
        }
        if ($entry->source !== null) {
            $lines[] = 'source: ' . (string) $entry->source;
        }
        if ($entry->fx_rate !== null && trim((string) ($entry->base_currency ?? '')) !== trim((string) $entry->currency)) {
            $lines[] = $entry->currency . ' ' . (string) $entry->amount . ' at ' . (string) $entry->fx_rate . ' → ' . (string) $entry->base_currency . ' ' . (string) $entry->base_amount;
        }

        return implode("\n", $lines);
    }
}
