<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsInspectionReports;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyRunner;
use App\Services\Monitoring\Reconciliation\ProviderStockReconciler;
use App\Services\Monitoring\Reconciliation\StockHoldReconciler;
use Illuminate\Console\Command;

/**
 * Read-only: where the holds on stock, the outbox of stock changes and a provider's own count disagree with what made them (M2-V6).
 *
 * The third step of the reconciliation Discovery-01 report 07 section 3.5 asks for, beside `wallet:reconcile` (balances against their
 * ledgers) and `costs:reconcile-platform` (cost layers against the platform's stock). It writes nothing and changes nothing it finds: a
 * hold its order no longer justifies is reported, never released, because releasing one is a decision about somebody's stock and this is
 * meant to be runnable on a restored copy by someone who may not change it.
 *
 * Exit code 1 while anything is unexplained, so that a backfill of the country marker, or the opening of a market that shares a provider's
 * shelf, cannot start on a copy whose stock does not reconcile.
 */
class MonitoringReconcileStockCommand extends Command
{
    use PrintsInspectionReports;

    protected $signature = 'monitoring:reconcile-stock
        {--connection= : database connection to inspect (default: the application default)}
        {--expect-database= : refuse to run unless the connected database has exactly this name}
        {--allow-production-read-only : run although APP_ENV is production (it only reads)}
        {--json : print the report as JSON}';

    protected $description = 'Read-only proof that every hold on stock and every stock change in the outbox is justified by the order or movement that made it, and that no storefront offers more than its provider has';

    public function handle(ReadOnlyRunner $runner, StockHoldReconciler $holds, ProviderStockReconciler $providerStock): int
    {
        $report = $runner->run(
            new CompatibilityOptions(
                connection: $this->option('connection') ?: null,
                allowProduction: (bool) $this->option('allow-production-read-only'),
                expectDatabase: $this->option('expect-database') ?: null,
                scope: 'Holds on stock against the orders and channel orders that made them, the outbox of stock changes against its own record of what was done, and each provider\'s count against what the storefront offers of the same product. Counts, sums and row ids only: no customer, seller or credential value. It proves nothing about traffic that arrives after the run, and it does not say which side of a difference is right.',
            ),
            static fn (ReadOnlyProbe $probe): array => [
                ...$holds->checks($probe),
                ...$providerStock->checks($probe),
            ],
        );

        $this->option('json')
            ? $this->printReportAsJson($report)
            : $this->printReportAsTable($report, 'A failure blocks a backfill and the opening of a market that shares a provider\'s shelf. Nothing was written to the database, and nothing this found was changed.');

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
