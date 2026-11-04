<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Checks\CheckResult;
use App\Services\Monitoring\Checks\CheckRunner;
use Illuminate\Console\Command;

/**
 * Probe the things a web request never touches.
 *
 * Request telemetry only knows about paths customers walked down. It cannot tell you the queue
 * worker died an hour ago, the certificate expires on Sunday, or the storage directory went
 * read-only after a deploy — nobody browsed those. This command goes and looks, every five
 * minutes, and records what it found so uptime and time-to-detect are computed from measurements
 * rather than claimed.
 */
class MonitoringCheck extends Command
{
    protected $signature = 'monitoring:check
                            {--only=* : Run only these checks (database, redis, queue, scheduler, storage, ssl, backup, synthetic)}';

    protected $description = 'Run the monitoring health and synthetic checks and record their results';

    public function handle(CheckRunner $runner): int
    {
        if (!config('monitoring.enabled', true)) {
            $this->warn('Monitoring is disabled (MONITORING_ENABLED=false); no checks were run.');

            return self::SUCCESS;
        }

        $results = $runner->run((array) $this->option('only'));

        if ($results === []) {
            $this->warn('No check matched --only=' . implode(',', (array) $this->option('only')) . '. Available: ' . implode(', ', $runner->keys()) . '.');

            return self::FAILURE;
        }

        $this->table(
            ['Check', 'Status', 'ms', 'Detail'],
            array_map(static fn (CheckResult $result) => [
                $result->key,
                $result->status,
                $result->durationMs ?? '—',
                mb_strimwidth((string) $result->detail, 0, 70, '…'),
            ], $results),
        );

        $breaking = array_filter($results, static fn (CheckResult $result) => $result->isBreaking());

        // A non-zero exit is deliberate: it lets an operator wire this into an external uptime
        // watchdog without parsing the table, and makes a failing check visible in the scheduler's
        // own run history rather than only inside the dashboard it is meant to be feeding.
        return $breaking === [] ? self::SUCCESS : self::FAILURE;
    }
}
