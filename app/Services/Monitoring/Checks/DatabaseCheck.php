<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;

/**
 * Can the application still talk to its database, and how long does the simplest possible
 * statement take?
 *
 * Deliberately `select 1` and not a real query: this measures the round trip — connect, send,
 * parse, return — with no table, no index and no cache in the way, so a rise in it points at the
 * connection or the server rather than at anything the shop did.
 */
class DatabaseCheck implements Check
{
    public function __construct(private readonly MonitoringSettings $settings)
    {
    }

    public function key(): string
    {
        return 'database';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        $started = hrtime(true);

        try {
            DB::connection()->select('select 1');
        } catch (\Throwable $exception) {
            return CheckResult::failing(
                $this->key(),
                Metric::describeFailure($exception),
                context: ['connection' => config('database.default')],
            );
        }

        $elapsed = (int) round((hrtime(true) - $started) / 1e6);
        $context = ['connection' => config('database.default'), 'statement' => 'select 1'];

        $warning = $this->settings->threshold('db_latency_warning_ms');
        $critical = $this->settings->threshold('db_latency_critical_ms');

        if ($critical !== null && $elapsed >= $critical) {
            return CheckResult::failing($this->key(), "select 1 took {$elapsed} ms.", $elapsed, $context);
        }

        if ($warning !== null && $elapsed >= $warning) {
            return CheckResult::degraded($this->key(), "select 1 took {$elapsed} ms.", $elapsed, $context);
        }

        return CheckResult::ok($this->key(), "select 1 in {$elapsed} ms.", $elapsed, $context);
    }
}
