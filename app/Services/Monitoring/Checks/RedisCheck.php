<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\Redis;

/**
 * Redis, probed with PING — and honest about the difference between "not installed here" and
 * "installed and not answering".
 *
 * A store running on file cache and a database queue has no Redis to be down; reporting that as a
 * failure would train the operator to ignore this row, which is how a real outage gets missed.
 */
class RedisCheck implements Check
{
    public function __construct(
        private readonly Environment $environment,
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function key(): string
    {
        return 'redis';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        if (!$this->environment->has('redis_ext') && !$this->environment->has('predis')) {
            return CheckResult::notSupported(
                $this->key(),
                'Neither the phpredis extension nor the predis package is installed, so this server cannot speak to Redis at all.',
                ['extension' => false],
            );
        }

        $used = $this->consumers();
        $started = hrtime(true);

        try {
            $connection = Redis::connection();
            $pong = $connection->ping();
        } catch (\Throwable $exception) {
            // A configured Redis that will not answer is an outage; an unconfigured one is a choice.
            return $used === []
                ? CheckResult::notConfigured(
                    $this->key(),
                    'Nothing in this application uses Redis (cache, session and queue all point elsewhere), and the server did not answer a PING. Set CACHE_STORE=redis or QUEUE_CONNECTION=redis to put it in the path.',
                    ['error' => Metric::describeFailure($exception)],
                )
                : CheckResult::failing(
                    $this->key(),
                    Metric::describeFailure($exception),
                    context: ['used_by' => $used],
                );
        }

        $elapsed = (int) round((hrtime(true) - $started) / 1e6);
        $context = ['used_by' => $used, 'reply' => is_bool($pong) ? ($pong ? 'PONG' : 'false') : (string) $pong];

        $warning = $this->settings->threshold('redis_latency_warning_ms');
        $critical = $this->settings->threshold('redis_latency_critical_ms');

        if ($critical !== null && $elapsed >= $critical) {
            return CheckResult::failing($this->key(), "PING took {$elapsed} ms.", $elapsed, $context);
        }

        if ($warning !== null && $elapsed >= $warning) {
            return CheckResult::degraded($this->key(), "PING took {$elapsed} ms.", $elapsed, $context);
        }

        if ($used === []) {
            return CheckResult::ok(
                $this->key(),
                "PING in {$elapsed} ms, but nothing in this application is pointed at Redis yet.",
                $elapsed,
                $context,
            );
        }

        return CheckResult::ok($this->key(), "PING in {$elapsed} ms.", $elapsed, $context);
    }

    /** Which subsystems actually route through Redis — the difference between idle and unused. */
    private function consumers(): array
    {
        $consumers = [];

        if (config('cache.default') === 'redis') {
            $consumers[] = 'cache';
        }
        if (config('session.driver') === 'redis') {
            $consumers[] = 'session';
        }
        if (config('queue.default') === 'redis') {
            $consumers[] = 'queue';
        }
        if (config('broadcasting.default') === 'redis') {
            $consumers[] = 'broadcasting';
        }

        return $consumers;
    }
}
