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
 *  - **A foreign-currency amount on an instance that cannot record one.** Posting it in the company currency and dropping the
 *    original would misstate the books silently.
 *  - **An entry that does not balance.** Odoo refuses it anyway; a named refusal beats a fault nobody can read.
 *
 * ## And the field names are the instance's, not ours
 *
 * Odoo's developer reference has no page for `account.move` or `account.move.line`, so every field written here is one
 * `OdooPreflight` found on the customer's own instance. A required field the instance does not have is a named refusal; an
 * optional one it does not have is simply not written.
 */
class OdooDraftBuilder
{
    public function __construct(private readonly OdooAccountMap $map)
    {
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
        $name = $this->lineNameFor($entry, $component);

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
            narration: $this->narrationFor($entry),
        );

        return $draft->balances()
            ? OdooDraftResult::ready($draft, $key)
            : OdooDraftResult::refused(['odoo_entry_does_not_balance']);
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
     * **A DECLARED GAP, and it refuses rather than rounds it away.** When a cost was incurred in one currency and the books are
     * kept in another, the line has to say so -- the original figure, its currency and the rate -- or the entry cannot be checked
     * against the invoice it came from. Carrying it needs Odoo's own `res.currency` id for that currency, and the currency part
     * of the mapping is not built yet (it belongs with the chart-of-accounts screen). So a foreign-currency cost is refused by
     * name here instead of being posted at its converted value with the original silently dropped.
     *
     * The line and the draft already know how to carry `currency_id` and `amount_currency`; what is missing is the id, and an id
     * is not something to invent.
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

        return isset($lineFields['currency_id'], $lineFields['amount_currency'])
            ? 'odoo_currency_map_not_built:' . $own
            // An instance with no field for it could not carry the original figure even with a map, which is a different thing
            // to tell a person.
            : 'odoo_cannot_record_a_foreign_currency_amount';
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
    private function lineNameFor(OperationalCostEntry $entry, string $component): string
    {
        $parts = array_filter([$component, (string) ($entry->sales_channel ?? ''), 'ledger #' . (int) $entry->id]);

        return mb_substr(implode(' · ', $parts), 0, 120);
    }

    /**
     * The note on the entry: where the figure came from, and the rate if one was used.
     *
     * The exchange rate is recorded because a converted figure that does not say what it was converted at cannot be checked.
     */
    private function narrationFor(OperationalCostEntry $entry): ?string
    {
        $lines = ['PharmacyKSA ledger entry #' . (int) $entry->id . ' (' . $entry->state->value . ')'];
        if ($entry->source !== null) {
            $lines[] = 'source: ' . (string) $entry->source;
        }
        if ($entry->fx_rate !== null && trim((string) ($entry->base_currency ?? '')) !== trim((string) $entry->currency)) {
            $lines[] = $entry->currency . ' ' . (string) $entry->amount . ' at ' . (string) $entry->fx_rate . ' → ' . (string) $entry->base_currency . ' ' . (string) $entry->base_amount;
        }

        return implode("\n", $lines);
    }
}
