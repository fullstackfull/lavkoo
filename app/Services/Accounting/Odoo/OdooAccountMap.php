<?php

namespace App\Services\Accounting\Odoo;

use App\Models\OdooAccountMapping;

/**
 * Which account in the customer's chart of accounts one of our facts belongs to — looked up, never guessed.
 *
 * **An unmapped fact is a refusal, not a default.** There is no fallback account, no "suspense" and no first-row-wins: posting a
 * marketplace commission to an account nobody chose is worse than not posting it, because it is wrong in a way that looks right
 * on a report until somebody reconciles the quarter.
 *
 * The lookup narrows: a row naming both the channel and the currency beats one naming only the channel, which beats the general
 * row. That order is computed from the row rather than left to the database, because otherwise which account an entry posts to
 * would depend on insertion order.
 */
class OdooAccountMap
{
    /**
     * The mapping for one fact, or null when the customer has not mapped it.
     *
     * @param int $sellerId 0 for the platform, as everywhere else in this module
     */
    public function for(int $companyId, string $factKind, string $factCode, string $channel, string $currency, int $sellerId = 0): ?OdooAccountMapping
    {
        $candidates = OdooAccountMapping::query()
            ->where('seller_id', $sellerId)
            ->where('odoo_company_id', $companyId)
            ->where('fact_kind', $factKind)
            ->where('fact_code', $factCode)
            ->whereIn('sales_channel', [$channel, OdooAccountMapping::ANY])
            ->whereIn('currency', [$currency, OdooAccountMapping::ANY])
            ->get()
            // Only an approved mapping is ever used: a row somebody is still drafting must not start posting into their books.
            ->filter(static fn (OdooAccountMapping $row): bool => $row->isApproved())
            ->sortByDesc(static fn (OdooAccountMapping $row): int => $row->specificity())
            ->values();

        return $candidates->first();
    }

    /**
     * EVERY account an approved mapping sends one kind of fact to — the set a figure already posted could be sitting on.
     *
     * Deliberately not `for()`. That one answers "where should this entry go", so the narrowest mapping wins and the answer is
     * one account. This answers "where could the entries we already posted have landed", which over a whole period is every
     * account any matching mapping named: a channel that was given its own account last month has its entries on that account
     * and the earlier ones on the general one, and a reconciliation that read only the winner would silently leave half the
     * period out of the Odoo column.
     *
     * A null channel or currency means "do not narrow by it at all" — every channel, every currency — which is not the same as
     * the empty string, the value that means "the mapping that applies to all of them".
     *
     * @return list<int>
     */
    public function accountsFor(int $companyId, string $factKind, string $factCode, ?string $channel = null, ?string $currency = null, int $sellerId = 0): array
    {
        return OdooAccountMapping::query()
            ->where('seller_id', $sellerId)
            ->where('odoo_company_id', $companyId)
            ->where('fact_kind', $factKind)
            ->where('fact_code', $factCode)
            ->when($channel !== null, fn ($query) => $query->whereIn('sales_channel', [$channel, OdooAccountMapping::ANY]))
            ->when($currency !== null, fn ($query) => $query->whereIn('currency', [$currency, OdooAccountMapping::ANY]))
            ->get()
            ->filter(static fn (OdooAccountMapping $row): bool => $row->isApproved())
            ->map(static fn (OdooAccountMapping $row): int => (int) $row->odoo_account_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The expense (or income) account for a cost component, and the account that balances it.
     *
     * Both or neither: an entry with one side mapped is not half-postable, it is unpostable, and saying which side is missing is
     * what lets a person fix it in one visit rather than two.
     *
     * @return array{component: OdooAccountMapping|null, counterpart: OdooAccountMapping|null, missing: list<string>}
     */
    public function forCostComponent(int $companyId, string $component, string $channel, string $currency, int $sellerId = 0): array
    {
        $expense = $this->for($companyId, OdooAccountMapping::KIND_COST_COMPONENT, $component, $channel, $currency, $sellerId);
        // The counterpart may be mapped per component or once for everything; the component's own row wins where it exists.
        $counterpart = $this->for($companyId, OdooAccountMapping::KIND_COUNTERPART, $component, $channel, $currency, $sellerId)
            ?? $this->for($companyId, OdooAccountMapping::KIND_COUNTERPART, OdooAccountMapping::CODE_DEFAULT, $channel, $currency, $sellerId);

        $missing = [];
        if ($expense === null) {
            $missing[] = 'odoo_account_not_mapped:' . OdooAccountMapping::KIND_COST_COMPONENT . ':' . $component;
        }
        if ($counterpart === null) {
            $missing[] = 'odoo_account_not_mapped:' . OdooAccountMapping::KIND_COUNTERPART . ':' . $component;
        }

        return ['component' => $expense, 'counterpart' => $counterpart, 'missing' => $missing];
    }
}
