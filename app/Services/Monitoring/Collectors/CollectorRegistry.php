<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;

/**
 * Every collector in one place, and the only thing that knows the full list.
 *
 * Collectors are resolved lazily: the Redis page must not pay for a database round trip, and the
 * once-a-minute gauge sample must not pay for the ten-largest-tables query that only the Database
 * page ever shows. Asking for one collector runs one collector.
 */
class CollectorRegistry
{
    /**
     * Collector key => class. The key is also the prefix its metrics are published under.
     *
     * @var array<string, class-string<Collector>>
     */
    private const COLLECTORS = [
        'cpu' => CpuCollector::class,
        'memory' => MemoryCollector::class,
        'disk' => DiskCollector::class,
        'network' => NetworkCollector::class,
        'php' => PhpRuntimeCollector::class,
        'db' => DatabaseCollector::class,
        'redis' => RedisCollector::class,
        'queue' => QueueCollector::class,
        'scheduler' => SchedulerCollector::class,
        'storage' => StorageCollector::class,
        'hardware' => HardwareCollector::class,
        'energy' => EnergyCollector::class,
        'ssl' => SslCollector::class,
        'webserver' => WebServerCollector::class,
    ];

    /** @var array<string, Collector> */
    private array $resolved = [];

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys(array_filter(
            self::COLLECTORS,
            static fn (string $class) => class_exists($class),
        ));
    }

    public function get(string $key): ?Collector
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $class = self::COLLECTORS[$key] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }

        return $this->resolved[$key] = app($class);
    }

    /**
     * One collector's readings, or an empty set when that collector is not installed.
     *
     * @return array<string, Metric>
     */
    public function collect(string $key): array
    {
        $collector = $this->get($key);
        if ($collector === null) {
            return [];
        }

        try {
            return $collector->collect();
        } catch (\Throwable $exception) {
            // The contract says collectors do not throw; this is the belt to that braces, so one
            // badly-behaved collector cannot blank a page that shows fourteen of them.
            return ['__collector' => Metric::failed($key . ' collector', $exception)];
        }
    }

    /**
     * Everything worth storing as a time series this minute.
     *
     * @return array<string, Metric>
     */
    public function gauges(): array
    {
        $gauges = [];

        foreach ($this->keys() as $key) {
            $collector = $this->get($key);
            if ($collector === null) {
                continue;
            }

            try {
                foreach ($collector->gauges() as $metric => $reading) {
                    if ($reading instanceof Metric && $reading->isOk()) {
                        $gauges[$metric] = $reading;
                    }
                }
            } catch (\Throwable) {
                // A collector that cannot answer this minute contributes nothing to this minute.
                continue;
            }
        }

        return $gauges;
    }
}
