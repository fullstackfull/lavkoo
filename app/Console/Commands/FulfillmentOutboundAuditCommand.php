<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PrintsInspectionReports;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyRunner;
use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use App\Services\Fulfillment\Readiness\Staging\EnvironmentAudit;
use App\Services\Fulfillment\Readiness\Staging\OutboundAudit;
use Illuminate\Console\Command;

/**
 * Read-only: is this copy of the store safe to start? Counts the rows of the restored database that can still mail, text,
 * push to, charge or ship to a real person or a third party, and checks the environment that decides whether this host can.
 * It reports counts and names only (no credential, no customer value), so the JSON is safe to send to a reviewer. Run it
 * after the sanitization plan and before any web, queue or cron process starts; exit code 1 while anything is still armed.
 */
class FulfillmentOutboundAuditCommand extends Command
{
    use PrintsInspectionReports;

    protected $signature = 'fulfillment:outbound-audit
        {--connection= : database connection to inspect (default: the application default)}
        {--expect-database= : refuse to run unless the connected database has exactly this name}
        {--production-host= : the production host name; the audit counts stored text that contains it, and fails if APP_URL does}
        {--allow-channel=* : a channel you knowingly leave armed, e.g. fulfillment_providers during the authorised read-only live window}
        {--allow-live : do not fail on live fulfillment switches}
        {--allow-production-read-only : run although APP_ENV is production (it only reads)}
        {--json : print the report as JSON}';

    protected $description = 'Read-only audit of every outbound channel a restored database still arms (mail, SMS, push, payments, couriers, webhooks)';

    public function handle(ReadOnlyRunner $runner, FulfillmentPreflight $preflight, EnvironmentAudit $environment, OutboundAudit $outbound): int
    {
        $host = $this->productionHost();
        if ($host === false) {
            $this->error('--production-host must be a plain host name such as shop.example.com.');

            return self::FAILURE;
        }

        $report = $runner->run(
            new CompatibilityOptions(connection: $this->option('connection') ?: null, allowProduction: (bool) $this->option('allow-production-read-only'), expectDatabase: $this->option('expect-database') ?: null),
            fn (ReadOnlyProbe $probe): array => [
                ...array_map(CompatibilityCheck::fromPreflight(...), $preflight->safety(allowProduction: (bool) $this->option('allow-production-read-only'), allowLive: (bool) $this->option('allow-live'))),
                ...$environment->checks($host),
                ...$outbound->checks($probe, (array) $this->option('allow-channel'), $host),
            ],
        );

        $this->option('json')
            ? $this->printReportAsJson($report)
            : $this->printReportAsTable($report, 'Only counts and names are shown. A passing audit means the database and environment hold nothing that can reach a person or a third party; it is not a substitute for a default-deny firewall.');

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /** @return string|false|null the host, null when none was given, false when it is not a plain host name */
    private function productionHost(): string|false|null
    {
        $host = (string) $this->option('production-host');

        return match (true) {
            $host === '' => null,
            preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/', $host) === 1 => $host,
            default => false,
        };
    }
}
