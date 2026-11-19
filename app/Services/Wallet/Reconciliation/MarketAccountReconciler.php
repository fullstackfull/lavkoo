<?php

namespace App\Services\Wallet\Reconciliation;

use App\Enums\Wallet\MarketBalanceKind;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Wallet\CustomerMarketBalances;
use Closure;
use Illuminate\Database\Query\Builder;

/**
 * The per-market half of the balance reconciliation (E-06, E-07): for each market, what its accounts hold against the net of the ledger
 * rows that name that market, plus how much of each ledger still names no market at all.
 *
 * It does not reconcile the one global balance: `App\Services\Wallet\Reconciliation\BalanceReconciler`, on the branch
 * gcc/p1-wallet-reconcile-command, already does that and classifies every difference. This class returns checks of the same shape from the
 * same read-only probe, so the reconcile command appends them to that reconciler's own and one report covers both. Nothing here is
 * duplicated from it.
 *
 * While `gcc.storefronts.market_accounts.enabled` is off it returns nothing, so the report says exactly what it says today. It only reads,
 * through the probe, and only counts and sums: no name, no contact detail, no row.
 */
final class MarketAccountReconciler
{
    public function __construct(private readonly CustomerMarketBalances $balances)
    {
    }

    /**
     * @param float $tolerance the difference a sum of decimals may show from float representation alone
     * @return list<CompatibilityCheck>
     */
    public function checks(ReadOnlyProbe $probe, float $tolerance): array
    {
        if (!$this->balances->isEnabled()) {
            return [];
        }

        $checks = [];

        foreach (MarketBalanceKind::cases() as $kind) {
            array_push($checks, ...$this->checksOf(probe: $probe, kind: $kind, tolerance: $tolerance));
        }

        return $checks;
    }

    /** @return list<CompatibilityCheck> */
    private function checksOf(ReadOnlyProbe $probe, MarketBalanceKind $kind, float $tolerance): array
    {
        $section = $kind->value . ' accounts by market';
        $accounts = $kind->accountsTable();
        $ledger = $kind->ledgerTable();
        $missing = $this->missingStructure(probe: $probe, kind: $kind);

        if ($missing !== null) {
            return [new CompatibilityCheck($section, 'the accounts can be compared', CompatibilityCheck::INFO, $missing)];
        }

        $markets = StorefrontResolver::fromConfig()->configuredCountries();
        $checks = [];

        foreach ($markets as $market) {
            $held = $probe->sum($accounts, $kind->amountColumn(), static fn (Builder $query) => $query->where('market', $market));
            $moved = $this->netOfLedger($probe, $ledger, static fn (Builder $query) => $query->where(MarketBalanceKind::MARKET_COLUMN, $market));
            $difference = $held - $moved;
            $equal = abs($difference) <= $tolerance;

            $checks[] = new CompatibilityCheck(
                $section,
                "market {$market}: the accounts hold what their ledger moved",
                $equal ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
                $equal
                    ? sprintf('%s held, %s moved', $this->plain($held), $this->plain($moved))
                    : sprintf('%s held but %s moved: a difference of %s', $this->plain($held), $this->plain($moved), $this->plain($difference)),
                ['held' => $this->plain($held), 'moved' => $this->plain($moved), 'difference' => $this->plain($difference)],
            );
        }

        $unattributed = $probe->count($ledger, static fn (Builder $query) => $query->whereNull(MarketBalanceKind::MARKET_COLUMN));
        $checks[] = new CompatibilityCheck(
            $section,
            'ledger rows that name no market',
            CompatibilityCheck::INFO,
            sprintf('%d rows, net %s: written before the markets, or by a writer that has not been switched over', $unattributed, $this->plain(
                $this->netOfLedger($probe, $ledger, static fn (Builder $query) => $query->whereNull(MarketBalanceKind::MARKET_COLUMN)),
            )),
            ['rows' => $unattributed],
        );

        $foreign = $probe->count($ledger, static fn (Builder $query) => $query->whereNotNull(MarketBalanceKind::MARKET_COLUMN)->whereNotIn(MarketBalanceKind::MARKET_COLUMN, $markets));
        $checks[] = new CompatibilityCheck(
            $section,
            'ledger rows that name a market this platform does not serve',
            $foreign === 0 ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            $foreign === 0 ? 'none' : sprintf('%d rows name a market that is not configured, so no account can hold them', $foreign),
            ['rows' => $foreign],
        );

        return $checks;
    }

    /** What the ledger moved: everything credited less everything debited. */
    private function netOfLedger(ReadOnlyProbe $probe, string $ledger, Closure $where): float
    {
        return $probe->sum($ledger, 'credit', $where) - $probe->sum($ledger, 'debit', $where);
    }

    /** Why the comparison cannot be made on this database, or null when it can. */
    private function missingStructure(ReadOnlyProbe $probe, MarketBalanceKind $kind): ?string
    {
        $accounts = $kind->accountsTable();
        $ledger = $kind->ledgerTable();

        if (!$probe->hasTable($accounts)) {
            return "the table {$accounts} is not on this database: the expand migration has not run";
        }

        if (!$probe->hasTable($ledger)) {
            return "the table {$ledger} does not exist";
        }

        $columns = $probe->columns($ledger);

        return array_key_exists(MarketBalanceKind::MARKET_COLUMN, $columns)
            ? null
            : "{$ledger} has no column " . MarketBalanceKind::MARKET_COLUMN . ': the expand migration has not run';
    }

    /** A sum as a plain decimal string, so a report never shows exponent notation. */
    private function plain(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 20, '.', ''), '0'), '.') ?: '0';
    }
}
