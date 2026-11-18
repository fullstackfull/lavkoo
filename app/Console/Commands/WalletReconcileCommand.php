<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsInspectionReports;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyRunner;
use App\Services\Wallet\Reconciliation\BalanceReconciler;
use App\Services\Wallet\Reconciliation\LedgerSpec;
use App\Services\Wallet\Reconciliation\ReconciliationOptions;
use Illuminate\Console\Command;

/**
 * Proves, on a restored copy and without writing, that the wallet balance and the loyalty points every customer carries equal the ledger rows behind
 * them, and sorts every difference into a class (docs/gcc/WALLET-RECONCILE.md). The session is locked read-only; the report holds counts and sums, never a
 * name, a contact detail or a row. Exit code 1 while any user's difference is unexplained, so that a migration of balances cannot start on a copy that does
 * not reconcile.
 */
class WalletReconcileCommand extends Command
{
    use PrintsInspectionReports;

    private const LEDGERS = ['wallet', 'loyalty', 'both'];

    protected $signature = 'wallet:reconcile
        {--ledger=both : wallet, loyalty or both}
        {--tolerance=0.000001 : the largest difference, in the unit of the balance, that is called float residue and not a difference (a wider value is stated in the report)}
        {--explained= : path of a file with one user id per line (a # starts a comment): users whose difference an operator has explained with evidence}
        {--list-unexplained=0 : list the ids (never the names) of up to this many users whose difference is unexplained}
        {--skip-order-match : do not match the wallet debits of orders to the orders they paid}
        {--match-tolerance=0.02 : the largest gap between a wallet debit and the total of the orders it paid for}
        {--match-window=600 : the largest time, in seconds, between an order and the debit that paid it}
        {--connection= : database connection to inspect (default: the application default)}
        {--expect-database= : refuse to run unless the connected database has exactly this name}
        {--allow-production-read-only : run although APP_ENV is production (it only reads)}
        {--json : print the report as JSON}';

    protected $description = 'Read-only proof that wallet balances and loyalty points equal their ledgers, with every difference classed (A to F)';

    public function handle(ReadOnlyRunner $runner, BalanceReconciler $reconciler): int
    {
        $options = $this->reconciliationOptions();
        if (is_string($options)) {
            $this->error($options);

            return self::FAILURE;
        }

        $report = $runner->run(
            new CompatibilityOptions(
                connection: $this->option('connection') ?: null,
                allowProduction: (bool) $this->option('allow-production-read-only'),
                expectDatabase: $this->option('expect-database') ?: null,
                scope: 'Stored balances against the ledger rows behind them, by class, and wallet debits against the orders they paid. Counts and sums only. The comparison is made up to the highest ledger row that existed when the run began; it proves nothing about traffic that arrives later, and it does not say which side of a difference is right.',
            ),
            static fn (ReadOnlyProbe $probe): array => $reconciler->checks(probe: $probe, options: $options),
        );

        $this->option('json')
            ? $this->printReportAsJson($report)
            : $this->printReportAsTable($report, 'Class F blocks a migration of balances. A, B, C and D are counted so that they can be judged; E needs evidence an operator wrote down. Nothing was written to the database.');

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /** @return ReconciliationOptions|string the options, or what is wrong with the ones the operator gave */
    private function reconciliationOptions(): ReconciliationOptions|string
    {
        $ledger = (string) $this->option('ledger');
        if (!in_array($ledger, self::LEDGERS, true)) {
            return '--ledger must be wallet, loyalty or both.';
        }

        foreach (['tolerance', 'match-tolerance'] as $name) {
            if (preg_match('/^\d{1,12}(\.\d{1,8})?$/', (string) $this->option($name)) !== 1) {
                return "--{$name} must be a plain number that is not negative, such as 0.005.";
            }
        }
        foreach (['list-unexplained', 'match-window'] as $name) {
            if (preg_match('/^\d{1,9}$/', (string) $this->option($name)) !== 1) {
                return "--{$name} must be a whole number that is not negative.";
            }
        }

        $explained = $this->explainedUserIds();
        if (is_string($explained)) {
            return $explained;
        }

        return new ReconciliationOptions(
            ledgers: match ($ledger) {
                'wallet' => [LedgerSpec::wallet()],
                'loyalty' => [LedgerSpec::loyalty()],
                default => [LedgerSpec::wallet(), LedgerSpec::loyalty()],
            },
            tolerance: (string) $this->option('tolerance'),
            explainedUserIds: $explained,
            listUnexplained: (int) $this->option('list-unexplained'),
            matchOrders: !$this->option('skip-order-match'),
            amountTolerance: (string) $this->option('match-tolerance'),
            windowSeconds: (int) $this->option('match-window'),
        );
    }

    /** @return list<int>|string the user ids the evidence file names, or what is wrong with the file */
    private function explainedUserIds(): array|string
    {
        $path = (string) $this->option('explained');
        if ($path === '') {
            return [];
        }
        if (!is_file($path) || !is_readable($path)) {
            return '--explained must name a file that can be read.';
        }

        $ids = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            $line = trim(preg_replace('/\s*#.*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\d{1,18}$/', $line) !== 1) {
                return sprintf('Line %d of the evidence file is not a user id: one whole number per line, a # starts a comment.', $number + 1);
            }
            $ids[(int) $line] = (int) $line;
        }

        return count($ids) > ReconciliationOptions::MAX_EXPLAINED
            ? sprintf('The evidence file names %d users; the most it may name is %d.', count($ids), ReconciliationOptions::MAX_EXPLAINED)
            : array_values($ids);
    }
}
