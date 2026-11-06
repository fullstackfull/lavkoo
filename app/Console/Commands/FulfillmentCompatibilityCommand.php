<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsInspectionReports;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityValidator;
use Illuminate\Console\Command;

/**
 * Read-only compatibility validation of a restored database: schema, order/checkout/product/payment/stock data shape and
 * the migrations `php artisan migrate` would run. The session is locked read-only, only aggregates are reported, and the
 * JSON report is safe to send to whoever is reviewing the staging result (it holds counts and names, no customer data).
 * Exit code 1 on any failure, or when the run was refused.
 */
class FulfillmentCompatibilityCommand extends Command
{
    use PrintsInspectionReports;

    protected $signature = 'fulfillment:compatibility
        {--connection= : database connection to inspect (default: the application default)}
        {--expect-database= : refuse to run unless the connected database has exactly this name}
        {--sample=2000 : how many of the newest orders, order lines and variant products to examine (100 to 50000)}
        {--allow-production-read-only : run although APP_ENV is production (it only reads)}
        {--json : print the report as JSON}';

    protected $description = 'Read-only compatibility validation of a restored database against the fulfillment integration';

    public function handle(CompatibilityValidator $validator): int
    {
        $report = $validator->validate(new CompatibilityOptions(
            sample: (int) $this->option('sample'),
            connection: $this->option('connection') ?: null,
            allowProduction: (bool) $this->option('allow-production-read-only'),
            expectDatabase: $this->option('expect-database') ?: null,
        ));

        $this->option('json')
            ? $this->printReportAsJson($report)
            : $this->printReportAsTable($report, 'This shows the structure and the aggregate shape of the data only. It does not prove that checkout, routing or dispatch work: run the scenarios in docs/integrations/staging-validation.md on the restored copy.');

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
