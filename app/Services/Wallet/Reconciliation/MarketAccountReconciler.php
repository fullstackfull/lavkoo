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
 * same read-only probe, so the reconcile command appends them to that reconciler's own and one report covers both. It nets a ledger the way
 * that branch's `LedgerSpec` does — a wallet credit and the bonus of an add-fund both add to the balance, a point has no bonus — so the two
 * halves of one report cannot disagree about the same rows. Nothing here is duplicated from it.
 *
 * While `gcc.storefronts.market_accounts.enabled` is off it returns nothing, so the report says exactly what it says today. It only reads,
 * through the probe, and only counts and sums: no name, no contact detail, no row.
 */
final class MarketAccountReconciler
{
    /**
     * How many decimals a sum is printed to. Far beyond any currency's scale — three, for the dinar and the rial — and far short of where a
     * float's own representation begins to show: 12.30 printed to twenty decimals reads 12.30000000000000071054, which is not the number in
     * the database. The verdict itself is decided on the floats against the tolerance, which is ten thousand times the last decimal printed
     * here, so nothing a PASS or a FAIL turns on is rounded away.
     */
    private const DECIMALS = 10;

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

        $resolver = StorefrontResolver::fromConfig();
        $markets = $resolver->configuredCountries();
        $checks = [];

        foreach ($markets as $market) {
            $currencyCode = $resolver->currencyOf($market);
            $held = $probe->sum($accounts, $kind->amountColumn(), static fn (Builder $query) => $query->where('market', $market));
            $moved = $this->netOfLedger(probe: $probe, kind: $kind, where: static fn (Builder $query) => $query->where(MarketBalanceKind::MARKET_COLUMN, $market));
            $difference = $held - $moved;
            $equal = abs($difference) <= $tolerance;
            $inAnotherCurrency = $this->accountsInAnotherCurrency(probe: $probe, accounts: $accounts, market: $market, currencyCode: $currencyCode);

            $checks[] = new CompatibilityCheck(
                $section,
                "market {$market}: the accounts hold what their ledger moved",
                $equal && $inAnotherCurrency === 0 ? CompatibilityCheck::PASS : CompatibilityCheck::FAIL,
                $this->verdict(market: $market, currencyCode: $currencyCode, held: $held, moved: $moved, difference: $difference, equal: $equal, inAnotherCurrency: $inAnotherCurrency),
                [
                    'held' => $this->plain($held),
                    'moved' => $this->plain($moved),
                    'difference' => $this->plain($difference),
                    'accounts_in_another_currency' => $inAnotherCurrency,
                ],
            );
        }

        $unattributed = $probe->count($ledger, static fn (Builder $query) => $query->whereNull(MarketBalanceKind::MARKET_COLUMN));
        $checks[] = new CompatibilityCheck(
            $section,
            'ledger rows that name no market',
            CompatibilityCheck::INFO,
            sprintf('%d rows, net %s: written before the markets, or by a writer that has not been switched over', $unattributed, $this->plain(
                $this->netOfLedger(probe: $probe, kind: $kind, where: static fn (Builder $query) => $query->whereNull(MarketBalanceKind::MARKET_COLUMN)),
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

    /**
     * What the ledger moved: everything that adds to the balance less everything debited. For a wallet that is the credit AND the bonus of
     * an add-fund, which the platform's own writer adds to the balance without adding it to the credit, so a net of credit alone would be
     * short by every bonus ever given and would fail a market that is perfectly consistent.
     */
    private function netOfLedger(ReadOnlyProbe $probe, MarketBalanceKind $kind, Closure $where): float
    {
        $ledger = $kind->ledgerTable();
        $added = 0.0;

        foreach ($kind->ledgerCreditColumns() as $column) {
            $added += $probe->sum($ledger, $column, $where);
        }

        return $added - $probe->sum($ledger, 'debit', $where);
    }

    /**
     * How many of a market's accounts are kept in a currency the market does not trade in. Summing those into one number would add dinars to
     * dollars and call the total reconciled, so the market fails while one is there. The service refuses to write to such an account
     * (`MarketBalanceRefusal::CurrencyMismatch`) but leaves it in place, which is why the report has to see it.
     */
    private function accountsInAnotherCurrency(ReadOnlyProbe $probe, string $accounts, string $market, string $currencyCode): int
    {
        return $probe->count(
            $accounts,
            static fn (Builder $query) => $query->where('market', $market)->whereRaw('upper(currency_code) <> ?', [strtoupper($currencyCode)]),
        );
    }

    /** What the per-market check says: the currency first, because a sum of two currencies is not an amount to compare at all. */
    private function verdict(string $market, string $currencyCode, float $held, float $moved, float $difference, bool $equal, int $inAnotherCurrency): string
    {
        if ($inAnotherCurrency > 0) {
            return sprintf(
                '%d of the accounts of market %s are not kept in %s, so what they hold is not one amount: %s across the currencies, %s moved',
                $inAnotherCurrency,
                $market,
                $currencyCode,
                $this->plain($held),
                $this->plain($moved),
            );
        }

        return $equal
            ? sprintf('%s held, %s moved', $this->plain($held), $this->plain($moved))
            : sprintf('%s held but %s moved: a difference of %s', $this->plain($held), $this->plain($moved), $this->plain($difference));
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

    /** A sum as a plain decimal string, so a report never shows exponent notation, a float's representation noise or a negative zero. */
    private function plain(float $amount): string
    {
        $text = rtrim(rtrim(number_format($amount, self::DECIMALS, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
