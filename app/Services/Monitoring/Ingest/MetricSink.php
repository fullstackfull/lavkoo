<?php

namespace App\Services\Monitoring\Ingest;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Where a measurement goes on the request path.
 *
 * This class exists because of one rule: monitoring may not become the reason the shop is slow.
 * Writing a row per request is out — that is millions of rows a week and a write on the hot path
 * of every checkout. Instead each request only ever INCREMENTS counters inside the current
 * one-minute bucket, and a scheduled drain turns those counters into rows once a minute.
 *
 * Which store holds the counters is decided by what the server actually has, in this order:
 *
 *  - redis    atomic HINCRBY over a pipeline. No locks, no contention, sub-millisecond, and the
 *             right answer whenever Redis is reachable — which it is on most real deployments.
 *  - apcu     shared memory, per web server. Same idea, no network hop, but only visible to the
 *             PHP workers on that machine, so the drain must run on that machine too.
 *  - database a single bounded UPSERT into the minute bucket. One write per request, but into a
 *             tiny table with a unique key rather than an ever-growing log — this is the honest
 *             fallback for a shared host with neither Redis nor APCu.
 *  - none     collection is off; nothing is written and the dashboard says so.
 *
 * The file cache store is deliberately NOT a candidate: a read-modify-write against a shared file
 * on every request serialises the whole site behind a lock, which would make monitoring the
 * outage. If none of the above is available we would rather collect nothing and say so.
 *
 * Every method swallows its own failures. A monitoring backend that is down must look like missing
 * data, never like a broken checkout.
 */
class MetricSink
{
    public const DRIVER_REDIS = 'redis';
    public const DRIVER_APCU = 'apcu';
    public const DRIVER_DATABASE = 'database';
    public const DRIVER_NONE = 'none';

    private const KEY_PREFIX = 'mon:b:';

    private ?string $driver = null;

    /** Counters accumulated during this request, flushed once at the end of it. */
    private array $pending = [];

    /**
     * The store actually in use, decided once per process.
     */
    public function driver(): string
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        if (!config('monitoring.enabled', true)) {
            return $this->driver = self::DRIVER_NONE;
        }

        $configured = (string) config('monitoring.buffer', 'auto');
        if ($configured !== 'auto' && $configured !== 'cache') {
            return $this->driver = $configured;
        }

        return $this->driver = match (true) {
            $this->redisUsable() => self::DRIVER_REDIS,
            function_exists('apcu_inc') && (bool) ini_get('apc.enabled') => self::DRIVER_APCU,
            default => self::DRIVER_DATABASE,
        };
    }

    /**
     * Add to a counter inside a bucket. Nothing leaves this process until flush().
     *
     * @param  string  $bucket  the bucket identity, e.g. "req|web|GET|/product/{slug}"
     * @param  string  $field   the counter inside it, e.g. "hits" or "hist.4"
     */
    public function increment(string $bucket, string $field, int|float $by = 1): void
    {
        if ($by == 0 || $this->driver() === self::DRIVER_NONE) {
            return;
        }

        $this->pending[$bucket][$field] = ($this->pending[$bucket][$field] ?? 0) + $by;
    }

    /** Record the smallest/largest value seen in a bucket (durations, memory). */
    public function observeExtremes(string $bucket, string $field, int|float $value): void
    {
        if ($this->driver() === self::DRIVER_NONE) {
            return;
        }

        $minKey = $field . ':min';
        $maxKey = $field . ':max';
        $current = $this->pending[$bucket] ?? [];
        $this->pending[$bucket][$minKey] = isset($current[$minKey]) ? min($current[$minKey], $value) : $value;
        $this->pending[$bucket][$maxKey] = isset($current[$maxKey]) ? max($current[$maxKey], $value) : $value;
    }

    /**
     * Push this request's counters into the shared buffer.
     *
     * Called from terminate(), after the response has been sent — so even the microseconds this
     * costs are spent on time the shopper has already stopped waiting for.
     */
    public function flush(int $minuteTimestamp): void
    {
        if ($this->pending === [] || $this->driver() === self::DRIVER_NONE) {
            $this->pending = [];

            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        try {
            match ($this->driver()) {
                self::DRIVER_REDIS => $this->flushToRedis($pending, $minuteTimestamp),
                self::DRIVER_APCU => $this->flushToApcu($pending, $minuteTimestamp),
                default => $this->flushToDatabase($pending, $minuteTimestamp),
            };
        } catch (\Throwable) {
            // A monitoring backend that is down is missing data, never a failed request.
        }
    }

    /**
     * Take everything buffered for minutes that are already over, and clear it.
     *
     * The current minute is left alone: draining it would double-count when the rest of it
     * arrives. Returns [bucketKey => [field => value]] keyed by minute timestamp.
     *
     * @return array<int, array<string, array<string, float|int>>>
     */
    public function drainCompletedMinutes(int $now, int $keepMinutes = 1): array
    {
        return match ($this->driver()) {
            self::DRIVER_REDIS => $this->drainRedis($now, $keepMinutes),
            self::DRIVER_APCU => $this->drainApcu($now, $keepMinutes),
            default => [],   // the database driver writes straight to the buckets; nothing to drain
        };
    }

    public function isBuffered(): bool
    {
        return in_array($this->driver(), [self::DRIVER_REDIS, self::DRIVER_APCU], true);
    }

    /** A human-readable description of where measurements are going, for the self-health panel. */
    public function describe(): string
    {
        return match ($this->driver()) {
            self::DRIVER_REDIS => 'Redis buffer, drained once a minute',
            self::DRIVER_APCU => 'APCu shared memory, drained once a minute (per web server)',
            self::DRIVER_DATABASE => 'Direct bucket upsert (no Redis or APCu available)',
            default => 'Collection disabled',
        };
    }

    // ---------------------------------------------------------------------------------------
    // Redis
    // ---------------------------------------------------------------------------------------

    private function redisUsable(): bool
    {
        try {
            $connection = Redis::connection();
            $connection->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function flushToRedis(array $pending, int $minute): void
    {
        $connection = Redis::connection();
        $index = self::KEY_PREFIX . 'idx:' . $minute;

        foreach ($pending as $bucket => $fields) {
            $key = self::KEY_PREFIX . $minute . ':' . $bucket;
            foreach ($fields as $field => $value) {
                if (str_ends_with($field, ':min') || str_ends_with($field, ':max')) {
                    // Extremes are not sums; a Lua-free compare-and-set is enough here because a
                    // lost race only costs precision on a min/max, never a count.
                    $this->redisExtreme($connection, $key, $field, $value);
                    continue;
                }
                $connection->hincrbyfloat($key, $field, (float) $value);
            }
            // Varargs, not an array: Laravel's phpredis connection passes an array straight through
            // to sAdd, which stringifies it and records the literal member "Array" — so the drain
            // then looks for a bucket by that name, finds nothing, and silently loses the minute.
            $connection->sadd($index, $bucket);
            // Long enough to survive a late drain, short enough that an abandoned minute expires.
            $connection->expire($key, 900);
        }
        $connection->expire($index, 900);
    }

    private function redisExtreme(mixed $connection, string $key, string $field, int|float $value): void
    {
        $current = $connection->hget($key, $field);
        if ($current === null || $current === false) {
            $connection->hset($key, $field, (string) $value);

            return;
        }
        $isMin = str_ends_with($field, ':min');
        if (($isMin && $value < (float) $current) || (!$isMin && $value > (float) $current)) {
            $connection->hset($key, $field, (string) $value);
        }
    }

    private function drainRedis(int $now, int $keepMinutes): array
    {
        $connection = Redis::connection();
        $drained = [];
        $currentMinute = intdiv($now, 60) * 60;

        // Look back over the retained window rather than scanning keys: SCAN on a live Redis that
        // also serves the cache is exactly the kind of thing monitoring should not do.
        for ($age = $keepMinutes; $age <= 15; $age++) {
            $minute = $currentMinute - ($age * 60);
            $index = self::KEY_PREFIX . 'idx:' . $minute;
            $buckets = $connection->smembers($index);
            if (!$buckets) {
                continue;
            }

            foreach ($buckets as $bucket) {
                $key = self::KEY_PREFIX . $minute . ':' . $bucket;
                $fields = $connection->hgetall($key);
                if ($fields) {
                    $drained[$minute][$bucket] = array_map('floatval', $fields);
                }
                $connection->del($key);
            }
            $connection->del($index);
        }

        return $drained;
    }

    // ---------------------------------------------------------------------------------------
    // APCu
    // ---------------------------------------------------------------------------------------

    private function flushToApcu(array $pending, int $minute): void
    {
        $indexKey = self::KEY_PREFIX . 'idx:' . $minute;
        $index = apcu_fetch($indexKey) ?: [];

        foreach ($pending as $bucket => $fields) {
            foreach ($fields as $field => $value) {
                $key = self::KEY_PREFIX . $minute . ':' . $bucket . ':' . $field;
                if (str_ends_with($field, ':min') || str_ends_with($field, ':max')) {
                    $current = apcu_fetch($key);
                    $isMin = str_ends_with($field, ':min');
                    if ($current === false || ($isMin ? $value < $current : $value > $current)) {
                        apcu_store($key, $value, 900);
                    }
                    continue;
                }
                if (!apcu_exists($key)) {
                    apcu_add($key, 0, 900);
                }
                apcu_inc($key, (int) round($value * 1000));  // integer cents-style, scaled
            }
            $index[$bucket] = true;
        }

        apcu_store($indexKey, $index, 900);
    }

    private function drainApcu(int $now, int $keepMinutes): array
    {
        $drained = [];
        $currentMinute = intdiv($now, 60) * 60;

        for ($age = $keepMinutes; $age <= 15; $age++) {
            $minute = $currentMinute - ($age * 60);
            $indexKey = self::KEY_PREFIX . 'idx:' . $minute;
            $index = apcu_fetch($indexKey);
            if (!is_array($index)) {
                continue;
            }

            foreach (array_keys($index) as $bucket) {
                $prefix = self::KEY_PREFIX . $minute . ':' . $bucket . ':';
                foreach (new \APCUIterator('/^' . preg_quote($prefix, '/') . '/') as $entry) {
                    $field = substr($entry['key'], strlen($prefix));
                    $raw = $entry['value'];
                    $drained[$minute][$bucket][$field] = str_ends_with($field, ':min') || str_ends_with($field, ':max')
                        ? (float) $raw
                        : (float) $raw / 1000;
                    apcu_delete($entry['key']);
                }
            }
            apcu_delete($indexKey);
        }

        return $drained;
    }

    // ---------------------------------------------------------------------------------------
    // Database fallback
    // ---------------------------------------------------------------------------------------

    /**
     * No buffer available: write the request's contribution straight into its minute bucket.
     *
     * One upsert per request against a small unique-keyed table. More than the buffered drivers
     * cost, far less than a row per request, and — crucially — bounded: the table stops growing
     * once the minute is over.
     */
    private function flushToDatabase(array $pending, int $minute): void
    {
        $writer = app(BucketWriter::class);
        $writer->apply([$minute => $pending]);
    }
}
