<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsInspectionReports;
use App\Console\Commands\Concerns\ReadsExplainedUserIds;
use App\Enums\Wallet\MarketBalanceKind;
use App\Models\User;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyRunner;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Wallet\CustomerMarketBalances;
use App\Services\Wallet\Reconciliation\BalanceReconciler;
use App\Services\Wallet\Reconciliation\LedgerSpec;
use App\Services\Wallet\Reconciliation\ReconciliationOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Opens each customer's account in one market and puts their existing balance in it, once (E-07).
 *
 * The platform keeps one wallet balance and one loyalty balance per customer, on the `users` row, and that row stays the authority after
 * this command has run: nothing here changes it, and nothing here reads an account back into it. What it does is make the market accounts
 * say the same thing, so that a market can later be switched over to read from its own account without a customer's money appearing or
 * disappearing at the moment of the switch. Expand only, in the sense the engineering rules use: the old shape is untouched and the new one
 * is filled beside it.
 *
 * It refuses to write unless three things hold, and says which one failed:
 *
 *   - the keys are on and the account tables are there, because the one writer of these accounts answers `Dormant` otherwise and would
 *     write nothing while reporting success;
 *   - the balances reconcile against their ledgers with nothing unexplained, which is the same proof `wallet:reconcile` prints and is run
 *     here through the same reconciler, on a session locked read-only. A copy that does not reconcile is a copy where nobody knows which
 *     side is right, and filling accounts from it would carry the difference into the new shape;
 *   - `--write` was asked for. Without it nothing is written and the report says what would have been.
 *
 * It is safe to run again: a customer who already has an account in the market is left alone, whatever that account holds, so a second run
 * cannot double anybody's money. That is also why it credits rather than sets — the one writer of these accounts is
 * `CustomerMarketBalances`, which locks the account row it writes, and no direct SQL touches the balances here.
 *
 * The report holds counts and sums only, never a name, an e-mail or a row, like every other inspection command.
 */
class WalletBackfillMarketAccountsCommand extends Command
{
    use PrintsInspectionReports;
    use ReadsExplainedUserIds;

    private const LEDGERS = ['wallet', 'loyalty', 'both'];

    protected $signature = 'wallet:backfill-market-accounts
        {--ledger=both : wallet, loyalty or both}
        {--market= : the market whose accounts are filled (default: the default storefront)}
        {--write : open and fill the accounts (without it nothing is written)}
        {--chunk=500 : how many customers are read at a time}
        {--tolerance=0.000001 : passed to the reconciliation: the largest difference that is called float residue}
        {--explained= : passed to the reconciliation: path of a file with one user id per line, whose difference an operator has explained}
        {--connection= : database connection to work on (default: the application default)}
        {--expect-database= : refuse to run unless the connected database has exactly this name}
        {--allow-production : run although APP_ENV is production}
        {--json : print the report as JSON}';

    protected $description = 'Fill one market\'s customer accounts from the balances the users rows hold, once, and only on a copy that reconciles';

    public function handle(ReadOnlyRunner $runner, BalanceReconciler $reconciler, CustomerMarketBalances $balances): int
    {
        $ledger = (string) $this->option('ledger');
        if (!in_array($ledger, self::LEDGERS, true)) {
            $this->error('--ledger must be wallet, loyalty or both.');

            return self::FAILURE;
        }

        $chunk = (string) $this->option('chunk');
        if (preg_match('/^[1-9]\d{0,5}$/', $chunk) !== 1) {
            $this->error('--chunk must be a whole number of at least 1.');

            return self::FAILURE;
        }

        $market = $this->market();
        if ($market === null) {
            $this->error('--market must name one of the configured storefronts, and without it the default storefront is used.');

            return self::FAILURE;
        }

        $explained = $this->explainedUserIds();
        if (is_string($explained)) {
            $this->error($explained);

            return self::FAILURE;
        }

        $kinds = match ($ledger) {
            'wallet' => [MarketBalanceKind::Wallet],
            'loyalty' => [MarketBalanceKind::Loyalty],
            default => [MarketBalanceKind::Wallet, MarketBalanceKind::Loyalty],
        };

        $dormant = $this->dormancy($balances, $kinds);
        if ($dormant !== null) {
            $this->error($dormant);

            return self::FAILURE;
        }

        $report = $runner->run(
            new CompatibilityOptions(
                connection: $this->option('connection') ?: null,
                allowProduction: (bool) $this->option('allow-production'),
                expectDatabase: $this->option('expect-database') ?: null,
                scope: 'The balances against the ledgers behind them, asked before any account is filled: a copy that does not reconcile is not filled.',
            ),
            fn (ReadOnlyProbe $probe): array => $reconciler->checks(
                probe: $probe,
                options: new ReconciliationOptions(
                    ledgers: array_map(
                        static fn (MarketBalanceKind $kind): LedgerSpec => $kind === MarketBalanceKind::Wallet ? LedgerSpec::wallet() : LedgerSpec::loyalty(),
                        $kinds,
                    ),
                    tolerance: (string) $this->option('tolerance'),
                    explainedUserIds: $explained,
                    listUnexplained: 0,
                    matchOrders: false,
                ),
            ),
        );

        $this->option('json')
            ? $this->printReportAsJson($report)
            : $this->printReportAsTable($report, 'Nothing was written while this was measured. A failure above stops the backfill.');

        if ($report->hasFailures()) {
            $this->error('The balances do not reconcile, so no account was opened or filled. Settle the differences first (wallet:reconcile explains the classes).');

            return self::FAILURE;
        }

        $written = (bool) $this->option('write');
        $counts = [];
        foreach ($kinds as $kind) {
            $counts[$kind->value] = $this->fill(balances: $balances, kind: $kind, market: $market, chunk: (int) $chunk, write: $written);
        }

        $this->printCounts($counts, $market, $written);

        return array_sum(array_column($counts, 'refused')) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** The market to fill, or null when the operator named one the configuration does not. */
    private function market(): ?string
    {
        $resolver = StorefrontResolver::fromConfig();
        $market = strtoupper(trim((string) ($this->option('market') ?: $resolver->defaultCountry())));

        return in_array($market, $resolver->configuredCountries(), true) ? $market : null;
    }

    /**
     * Why the accounts cannot be written yet, or null when they can. The one writer refuses a credit while any of these holds, so a run
     * that went ahead would report a filled account for every customer and have written none of them.
     *
     * @param list<MarketBalanceKind> $kinds
     */
    private function dormancy(CustomerMarketBalances $balances, array $kinds): ?string
    {
        if (!StorefrontResolver::fromConfig()->isEnabled()) {
            return 'Storefronts are off (gcc.storefronts.enabled), so there are no markets to hold an account.';
        }
        if (!$balances->isEnabled()) {
            return 'The market accounts are off (gcc.storefronts.market_accounts.enabled), so every write to one is refused as dormant.';
        }
        if (!(bool) config('gcc.storefronts.currency.enabled')) {
            return 'The storefront currencies are off (gcc.storefronts.currency.enabled), so a market has no money of its own to open an account in.';
        }

        foreach ($kinds as $kind) {
            if (!Schema::hasTable($kind->accountsTable())) {
                return "The table {$kind->accountsTable()} is not there yet: run the migrations of the market accounts first.";
            }
        }

        return null;
    }

    /**
     * Fills one ledger's accounts and counts what happened. A customer is read once, in id order, in chunks: the table is walked by the
     * key rather than by an offset, so a row written while the walk is running cannot make it skip or repeat a customer.
     *
     * @return array{customers: int, nothing_to_carry: int, already_open: int, filled: int, amount: float, refused: int, refusals: array<string, int>}
     */
    private function fill(CustomerMarketBalances $balances, MarketBalanceKind $kind, string $market, int $chunk, bool $write): array
    {
        $counts = ['customers' => 0, 'nothing_to_carry' => 0, 'already_open' => 0, 'filled' => 0, 'amount' => 0.0, 'refused' => 0, 'refusals' => []];
        $column = $kind->legacyColumn();

        User::query()
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById($chunk, function ($customers) use ($balances, $kind, $market, $write, $column, &$counts): void {
                foreach ($customers as $customer) {
                    $counts['customers']++;
                    $amount = (float) $customer->{$column};

                    if (!($amount > 0)) {
                        $counts['nothing_to_carry']++;
                        continue;
                    }

                    // Whatever the account holds, it was opened by something that knows more than this command does: a run that credited it
                    // again would add this balance to one that may already carry it. An account emptied to zero is still an account, which
                    // is why this asks whether it is open and not what it holds.
                    if ($balances->hasAccount(kind: $kind, customerId: $customer->id, market: $market)) {
                        $counts['already_open']++;
                        continue;
                    }

                    if (!$write) {
                        $counts['filled']++;
                        $counts['amount'] += $amount;
                        continue;
                    }

                    $change = $balances->credit(kind: $kind, customerId: $customer->id, market: $market, amount: $amount);

                    if (!$change->applied) {
                        $counts['refused']++;
                        $reason = $change->refusal?->value ?? 'unknown';
                        $counts['refusals'][$reason] = ($counts['refusals'][$reason] ?? 0) + 1;
                        continue;
                    }

                    $counts['filled']++;
                    $counts['amount'] += $amount;
                }
            });

        return $counts;
    }

    /** @param array<string, array{customers: int, nothing_to_carry: int, already_open: int, filled: int, amount: float, refused: int, refusals: array<string, int>}> $counts */
    private function printCounts(array $counts, string $market, bool $written): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['market' => $market, 'written' => $written, 'ledgers' => $counts], JSON_PRETTY_PRINT));

            return;
        }

        $this->newLine();
        $this->line($written ? "Filled the {$market} accounts:" : "Nothing was written. In {$market} this would have been:");
        $this->table(
            ['ledger', 'customers', 'nothing to carry', 'already open', 'filled', 'amount', 'refused'],
            array_map(
                static fn (string $ledger, array $row): array => [
                    $ledger,
                    $row['customers'],
                    $row['nothing_to_carry'],
                    $row['already_open'],
                    $row['filled'],
                    number_format($row['amount'], 2, '.', ''),
                    $row['refused'] === 0 ? '0' : $row['refused'] . ' (' . json_encode($row['refusals']) . ')',
                ],
                array_keys($counts),
                $counts,
            ),
        );
        $this->line('The balance on the users row is unchanged and stays the authority until a market is switched over to read its own account.');
    }
}
