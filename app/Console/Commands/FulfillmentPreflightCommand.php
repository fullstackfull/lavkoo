<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use App\Services\Fulfillment\Readiness\PreflightCheck;
use Illuminate\Console\Command;

/**
 * Read-only readiness report: schema compatibility with the restored installation dump, safety (which database,
 * is live dispatch on), and runtime wiring (queue, scheduler heartbeat, credentials). It never writes, sends or
 * migrates. Run it against a restored COPY of production before the first migrate; exit code 1 on any FAIL.
 */
class FulfillmentPreflightCommand extends Command
{
    protected $signature = 'fulfillment:preflight
        {--allow-production-read-only : run even though APP_ENV is production (it only reads schema metadata)}
        {--allow-live : do not fail on live dispatch being enabled}
        {--json : print the report as JSON}';

    protected $description = 'Read-only readiness report for the fulfillment integration (schema, safety, queue, scheduler)';

    public function handle(FulfillmentPreflight $preflight): int
    {
        $checks = $preflight->run(
            allowProduction: (bool) $this->option('allow-production-read-only'),
            allowLive: (bool) $this->option('allow-live'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(static fn (PreflightCheck $check): array => (array) $check, $checks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['group', 'check', 'status', 'detail'], array_map(
                static fn (PreflightCheck $check): array => [$check->group, $check->name, strtoupper($check->status), $check->detail],
                $checks,
            ));
        }

        $failures = count(array_filter($checks, static fn (PreflightCheck $check): bool => $check->failed()));
        $warnings = count(array_filter($checks, static fn (PreflightCheck $check): bool => $check->status === PreflightCheck::WARN));
        $this->line("{$failures} failure(s), {$warnings} warning(s), " . count($checks) . ' checks.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
