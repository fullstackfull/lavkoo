<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityOptions;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyRunner;
use App\Services\Fulfillment\Readiness\Staging\SanitizationPlan;
use App\Services\Fulfillment\Readiness\Staging\SanitizationPlanner;
use Illuminate\Console\Command;

/**
 * Prints the SQL that makes a restored copy of production safe to start, tailored to the tables and columns that copy really
 * has. PLAN ONLY: it reads the schema through a read-only session and prints statements; it never runs one, and the output
 * holds no credential and no customer value. A person reviews it and runs it against the staging copy, then runs
 * `fulfillment:outbound-audit` to prove it worked. Redirect the output to a file: `... > sanitize.sql`.
 */
class FulfillmentSanitizationPlanCommand extends Command
{
    protected $signature = 'fulfillment:sanitization-plan
        {--expect-database= : the name of the restored staging database (required: it is written into the guard statement)}
        {--connection= : database connection to inspect (default: the application default)}
        {--allow-production-read-only : run although APP_ENV is production (it only reads the schema)}
        {--json : print the plan as JSON instead of SQL}';

    protected $description = 'Print (never run) the SQL that neutralises a restored database: outbound channels, queued jobs, tokens, personal data';

    public function handle(ReadOnlyRunner $runner, SanitizationPlanner $planner): int
    {
        $expected = (string) $this->option('expect-database');
        if ($expected === '') {
            $this->error('--expect-database is required: the plan carries a guard that stops the script when it is run against any other database.');

            return self::FAILURE;
        }

        $plan = null;
        $report = $runner->run(
            new CompatibilityOptions(connection: $this->option('connection') ?: null, allowProduction: (bool) $this->option('allow-production-read-only'), expectDatabase: $expected),
            function (ReadOnlyProbe $probe) use ($planner, $expected, &$plan): array {
                $plan = $planner->plan($probe, $expected);

                return [];
            },
        );

        if ($report->refused || !$plan instanceof SanitizationPlan) {
            $this->error($report->checks[0]->detail ?? 'The plan could not be written.');

            return self::FAILURE;
        }
        if ($report->hasFailures()) {
            $this->error($report->checks[array_key_last($report->checks)]->detail);

            return self::FAILURE;
        }

        $this->option('json')
            ? $this->line((string) json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            : $this->line($plan->render());

        return self::SUCCESS;
    }
}
