<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compresses raw telemetry into telemetry_daily and prunes the raw rows.
 *
 * Raw rows answer "what is happening right now" on the Monitoring page; the
 * rollups answer everything historical on Analytics — visits per day, traffic
 * sources, devices, most-visited products and shops, API load and error rate.
 * Product and shop resolution happens here, off the request path: the web
 * product page is /product/{slug} and the shop page /vendor-shop/{slug}, so
 * the day's distinct slugs are joined against their tables once.
 */
class TelemetryRollup extends Command
{
    /*
    | telemetry_daily is written here and read by no screen today.
    |
    | It is not an orphan by accident: the raw telemetry_requests rows are pruned on a retention
    | window, and this daily summary is what survives it. The old Analytics page that used to read
    | it has been replaced by the analytics_daily rollups, so until something is built on the
    | history, this command's product is retention rather than a report. Said here so the next
    | person does not have to work that out from an empty grep.
    */

    protected $signature = 'telemetry:rollup {--date= : YYYY-MM-DD, defaults to today} {--prune : also prune raw rows past retention}';

    protected $description = 'Aggregate raw request telemetry into daily rollups';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();
        $day = $date->toDateString();

        $base = DB::table('telemetry_requests')->whereBetween('created_at', [$from, $to]);

        // Channel totals.
        foreach (['web', 'api'] as $channel) {
            $row = (clone $base)->where('channel', $channel)->selectRaw(
                'COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) errors, AVG(duration_ms) avg_ms'
            )->first();
            $this->put($day, 'totals', $channel, (int) $row->hits, (int) $row->visitors, (int) $row->errors, $row->avg_ms);
        }

        // Orders and revenue for the day (joined here so Analytics reads one table).
        $orders = DB::table('orders')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) c, COALESCE(SUM(order_amount), 0) amount')->first();
        DB::table('telemetry_daily')->updateOrInsert(
            ['date' => $day, 'scope' => 'totals', 'key' => 'orders'],
            ['hits' => (int) $orders->c, 'orders' => (int) $orders->c, 'revenue' => (float) $orders->amount, 'visitors' => 0, 'errors' => 0],
        );

        // Top paths (web), sources, devices from sessions, status classes, api groups.
        $this->rollupGroup($day, 'path', (clone $base)->where('channel', 'web')
            ->selectRaw('path k, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) errors, AVG(duration_ms) avg_ms')
            ->groupBy('path')->orderByDesc('hits')->limit(100)->get());

        $sessions = DB::table('visit_sessions')->whereBetween('started_at', [$from, $to]);
        $this->rollupGroup($day, 'source', (clone $sessions)
            ->selectRaw("COALESCE(referrer_domain, 'direct') k, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, 0 errors, NULL avg_ms")
            ->groupBy('referrer_domain')->orderByDesc('hits')->limit(50)->get());
        $this->rollupGroup($day, 'utm_source', (clone $sessions)->whereNotNull('utm_source')
            ->selectRaw('utm_source k, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, 0 errors, NULL avg_ms')
            ->groupBy('utm_source')->orderByDesc('hits')->limit(50)->get());
        $this->rollupGroup($day, 'device', (clone $sessions)
            ->selectRaw("COALESCE(device, 'unknown') k, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, 0 errors, NULL avg_ms")
            ->groupBy('device')->get());

        // Status classes and API groups aggregate in PHP over small grouped sets,
        // keeping the SQL portable (the test suite runs on sqlite).
        $statusRows = (clone $base)
            ->selectRaw('status, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, AVG(duration_ms) avg_ms')
            ->groupBy('status')->get();
        $byClass = [];
        foreach ($statusRows as $row) {
            $key = intdiv((int) $row->status, 100) . 'xx';
            $bucket = $byClass[$key] ?? ['hits' => 0, 'visitors' => 0, 'errors' => 0, 'weighted' => 0.0];
            $bucket['hits'] += (int) $row->hits;
            $bucket['visitors'] += (int) $row->visitors;
            $bucket['errors'] += ((int) $row->status >= 500) ? (int) $row->hits : 0;
            $bucket['weighted'] += (float) ($row->avg_ms ?? 0) * (int) $row->hits;
            $byClass[$key] = $bucket;
        }
        foreach ($byClass as $key => $bucket) {
            $this->put($day, 'status_class', $key, $bucket['hits'], $bucket['visitors'], $bucket['errors'], $bucket['hits'] ? $bucket['weighted'] / $bucket['hits'] : null);
        }

        $apiRows = (clone $base)->where('channel', 'api')
            ->selectRaw('path, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors, SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) errors, AVG(duration_ms) avg_ms')
            ->groupBy('path')->orderByDesc('hits')->limit(2000)->get();
        $byGroup = [];
        foreach ($apiRows as $row) {
            $key = implode('/', array_slice(explode('/', $row->path), 0, 3));
            $bucket = $byGroup[$key] ?? ['hits' => 0, 'visitors' => 0, 'errors' => 0, 'weighted' => 0.0];
            $bucket['hits'] += (int) $row->hits;
            $bucket['visitors'] += (int) $row->visitors;
            $bucket['errors'] += (int) $row->errors;
            $bucket['weighted'] += (float) ($row->avg_ms ?? 0) * (int) $row->hits;
            $byGroup[$key] = $bucket;
        }
        arsort($byGroup);
        foreach (array_slice($byGroup, 0, 60, true) as $key => $bucket) {
            $this->put($day, 'api_group', $key, $bucket['hits'], $bucket['visitors'], $bucket['errors'], $bucket['hits'] ? $bucket['weighted'] / $bucket['hits'] : null);
        }

        // Product and shop visits: resolve the day's distinct slugs in one join each.
        $productHits = (clone $base)->where('channel', 'web')->where('path', 'like', 'product/%')
            ->selectRaw("SUBSTRING(path, 9) slug, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors")
            ->groupBy('slug')->orderByDesc('hits')->limit(200)->get();
        if ($productHits->isNotEmpty()) {
            $ids = DB::table('products')->whereIn('slug', $productHits->pluck('slug'))->pluck('id', 'slug');
            foreach ($productHits as $hit) {
                if (isset($ids[$hit->slug])) {
                    $this->put($day, 'product', (string) $ids[$hit->slug], (int) $hit->hits, (int) $hit->visitors, 0, null);
                }
            }
        }

        $shopHits = (clone $base)->where('channel', 'web')->where('path', 'like', 'vendor-shop/%')
            ->selectRaw("SUBSTRING(path, 13) slug, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors")
            ->groupBy('slug')->orderByDesc('hits')->limit(200)->get();
        if ($shopHits->isNotEmpty()) {
            $shopIds = DB::table('shops')->whereIn('slug', $shopHits->pluck('slug'))->pluck('id', 'slug');
            foreach ($shopHits as $hit) {
                if (isset($shopIds[$hit->slug])) {
                    $this->put($day, 'shop', (string) $shopIds[$hit->slug], (int) $hit->hits, (int) $hit->visitors, 0, null);
                }
            }
        }

        if ($this->option('prune')) {
            $cutoff = Carbon::today()->subDays((int) config('telemetry.retention_days', 30));
            $deletedRequests = DB::table('telemetry_requests')->where('created_at', '<', $cutoff)->delete();
            $deletedSessions = DB::table('visit_sessions')->where('last_activity_at', '<', $cutoff)->delete();
            $this->info("pruned {$deletedRequests} requests, {$deletedSessions} sessions older than {$cutoff->toDateString()}");
        }

        $this->info("rolled up {$day}");
        return self::SUCCESS;
    }

    private function rollupGroup(string $day, string $scope, iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->put($day, $scope, (string) ($row->k ?? ''), (int) $row->hits, (int) $row->visitors, (int) ($row->errors ?? 0), $row->avg_ms ?? null);
        }
    }

    private function put(string $day, string $scope, string $key, int $hits, int $visitors, int $errors, $avgMs): void
    {
        if ($key === '') {
            return;
        }
        DB::table('telemetry_daily')->updateOrInsert(
            ['date' => $day, 'scope' => $scope, 'key' => mb_substr($key, 0, 191)],
            ['hits' => $hits, 'visitors' => $visitors, 'errors' => $errors, 'avg_duration_ms' => $avgMs !== null ? (int) round((float) $avgMs) : null],
        );
    }
}
