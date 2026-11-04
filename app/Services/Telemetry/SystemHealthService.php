<?php

namespace App\Services\Telemetry;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One snapshot of everything the Monitoring page shows: machine, runtime,
 * queue, scheduler, traffic and commerce pulse. Each probe is independent
 * and failure-isolated, so a dead cache store cannot blank the whole page.
 */
class SystemHealthService
{
    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'server' => $this->probe(fn () => $this->server()),
            'services' => $this->probe(fn () => $this->services()),
            'queue' => $this->probe(fn () => $this->queue()),
            'traffic' => $this->probe(fn () => $this->traffic()),
            'commerce' => $this->probe(fn () => $this->commerce()),
            'api' => $this->probe(fn () => $this->api()),
            'top_paths' => $this->probe(fn () => $this->topPathsNow()),
        ];
    }

    private function probe(callable $section): array
    {
        try {
            return $section();
        } catch (\Throwable $e) {
            return ['error' => class_basename($e)];
        }
    }

    private function server(): array
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
        $memory = null;
        if (is_readable('/proc/meminfo')) {
            $info = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $info, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $info, $available);
            if ($total && $available) {
                $memory = [
                    'total_mb' => (int) round($total[1] / 1024),
                    'used_mb' => (int) round(($total[1] - $available[1]) / 1024),
                    'used_pct' => (int) round(100 * ($total[1] - $available[1]) / max(1, $total[1])),
                ];
            }
        }
        $diskTotal = @disk_total_space(base_path());
        $diskFree = @disk_free_space(base_path());

        return [
            'load' => $load ? array_map(fn ($v) => round($v, 2), $load) : null,
            'cpu_count' => (int) (is_readable('/proc/cpuinfo') ? max(1, substr_count(file_get_contents('/proc/cpuinfo'), 'processor')) : 1),
            'memory' => $memory,
            'disk' => $diskTotal ? [
                'total_gb' => round($diskTotal / 1e9, 1),
                'free_gb' => round($diskFree / 1e9, 1),
                'used_pct' => (int) round(100 * ($diskTotal - $diskFree) / $diskTotal),
            ] : null,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ];
    }

    private function services(): array
    {
        $dbStart = microtime(true);
        DB::select('select 1');
        $dbMs = (microtime(true) - $dbStart) * 1000;

        $cacheStart = microtime(true);
        Cache::put('health_probe', 1, 5);
        $cacheOk = Cache::get('health_probe') === 1;
        $cacheMs = (microtime(true) - $cacheStart) * 1000;

        $heartbeat = DB::table('business_settings')->where('type', 'scheduler_last_run_at')->value('value');
        $heartbeatAge = $heartbeat ? Carbon::parse($heartbeat)->diffInMinutes(now()) : null;

        return [
            'db_ms' => round($dbMs, 1),
            'cache_ok' => $cacheOk,
            'cache_ms' => round($cacheMs, 1),
            'scheduler_last_run' => $heartbeat,
            // The scheduler pings every five minutes; past ten it is down and
            // settlements, reminders and telemetry rollups silently stop.
            'scheduler_ok' => $heartbeatAge !== null && $heartbeatAge <= 10,
            'storage_writable' => is_writable(storage_path()),
        ];
    }

    private function queue(): array
    {
        return [
            'pending' => (int) DB::table('jobs')->count(),
            'failed_total' => (int) DB::table('failed_jobs')->count(),
            'failed_24h' => (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
        ];
    }

    private function traffic(): array
    {
        $lastMinute = DB::table('telemetry_requests')->where('created_at', '>=', now()->subMinute());
        $lastHour = DB::table('telemetry_requests')->where('created_at', '>=', now()->subHour());

        return [
            'requests_per_min' => (int) (clone $lastMinute)->count(),
            'active_visitors' => (int) DB::table('visit_sessions')->where('last_activity_at', '>=', now()->subMinutes(5))->count(),
            'visitors_today' => (int) DB::table('visit_sessions')->where('started_at', '>=', now()->startOfDay())->distinct()->count('visitor_id'),
            // Null, not zero. An hour with no requests has no average, and "0 ms" reads as a shop
            // serving every page instantly rather than as a shop nobody visited.
            'avg_ms_hour' => ($average = (clone $lastHour)->avg('duration_ms')) === null
                ? null
                : (int) round((float) $average),
            'errors_hour' => (int) (clone $lastHour)->where('status', '>=', 500)->count(),
            'hits_hour' => (int) (clone $lastHour)->count(),
        ];
    }

    private function commerce(): array
    {
        $today = now()->startOfDay();
        return [
            'orders_today' => (int) DB::table('orders')->where('created_at', '>=', $today)->count(),
            'revenue_today' => (float) DB::table('orders')->where('created_at', '>=', $today)->sum('order_amount'),
            'pending_orders' => (int) DB::table('orders')->where('order_status', 'pending')->count(),
            'carts_active' => (int) DB::table('carts')->where('updated_at', '>=', now()->subDay())->count(),
        ];
    }

    private function api(): array
    {
        $hour = DB::table('telemetry_requests')->where('channel', 'api')->where('created_at', '>=', now()->subHour());
        $hits = (int) (clone $hour)->count();
        $errors = (int) (clone $hour)->where('status', '>=', 500)->count();
        return [
            'hits_hour' => $hits,
            'errors_hour' => $errors,
            'error_rate_pct' => $hits ? round(100 * $errors / $hits, 1) : 0.0,
            'avg_ms' => (int) round((clone $hour)->avg('duration_ms') ?? 0),
            'auth_failures_hour' => (int) (clone $hour)->whereIn('status', [401, 403])->count(),
        ];
    }

    /**
     * The busiest routes right now.
     *
     * Grouped by ROUTE PATTERN, not by raw URI. Every product page and every order is its own
     * path, so grouping on the address meant a genuinely hot or slow route never rose into a
     * twelve-row list — it arrived as a thousand rows of one hit each, and the list showed
     * whichever thousand happened to sort first.
     */
    private function topPathsNow(): array
    {
        $paths = app(\App\Services\Analytics\Support\PathNormalizer::class);

        return DB::table('telemetry_requests')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->selectRaw('path, channel, COUNT(*) hits, AVG(duration_ms) avg_ms, SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) errors')
            ->groupBy('path', 'channel')
            ->get()
            ->groupBy(fn ($row) => $paths->normalise((string) $row->path) . '|' . $row->channel)
            ->map(function ($group, $key) {
                $hits = (int) $group->sum('hits');

                return (object) [
                    'path' => explode('|', (string) $key)[0],
                    'channel' => $group->first()->channel,
                    'hits' => $hits,
                    // Weighted, because averaging a set of averages gives the quiet URL the same
                    // say as the busy one.
                    'avg_ms' => $hits > 0
                        ? $group->sum(fn ($row) => (float) $row->avg_ms * (int) $row->hits) / $hits
                        : 0,
                    'errors' => (int) $group->sum('errors'),
                ];
            })
            ->sortByDesc('hits')
            ->take(12)
            ->values()
            ->map(fn ($row) => [
                'path' => $row->path,
                'channel' => $row->channel,
                'hits' => (int) $row->hits,
                'avg_ms' => (int) round((float) $row->avg_ms),
                'errors' => (int) $row->errors,
            ])->all();
    }
}
