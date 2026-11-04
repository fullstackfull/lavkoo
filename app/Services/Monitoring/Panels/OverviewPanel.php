<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\HealthScoreService;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The first screen: is the shop alright, and if not, which way to walk.
 *
 * Its job is triage, not detail. Every card either says "fine" or points at the section that has
 * the evidence, so the path from "something is wrong" to "this query on this route" is a sequence
 * of clicks rather than a search.
 *
 * The service map matters as much as the score. Knowing the checkout is failing is half an answer;
 * seeing that Laravel is fine, the database is fine, and the payment gateway is red is the other
 * half — and it is the half that decides who gets called.
 */
class OverviewPanel implements Panel
{
    public function __construct(
        private readonly HealthScoreService $health,
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $health = $this->health->evaluate();

        return [
            'health' => $health,
            'services' => $this->serviceCards(),
            'map' => $this->serviceMap(),
            'traffic' => $this->reader->comparison($range),
            'timeline' => $this->reader->requestTimeline($range),
            'events' => $this->recentEvents(),
            'incidents' => $this->openIncidents(),
            'attention' => $this->needsAttention($health),
        ];
    }

    /**
     * One card per thing that can be down.
     *
     * Each carries its own state, its latency where that means something, when it was last known
     * healthy, and the section to open next. A card with nothing behind it says so rather than
     * showing a reassuring green tick it has not earned.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceCards(): array
    {
        return array_values(array_filter([
            $this->requestCard('web_store', 'web', 'live'),
            $this->requestCard('backend_api', 'api', 'apis'),
            $this->platformCard('android', 'android'),
            $this->platformCard('ios', 'ios'),
            $this->collectorCard('database', 'db', 'latency_ms', 'database', warning: (float) config('monitoring.thresholds.db_latency_warning_ms', 50), critical: (float) config('monitoring.thresholds.db_latency_critical_ms', 250)),
            $this->collectorCard('redis_and_cache', 'redis', 'latency_ms', 'redis', warning: (float) config('monitoring.thresholds.redis_latency_warning_ms', 10), critical: (float) config('monitoring.thresholds.redis_latency_critical_ms', 50)),
            $this->queueCard(),
            $this->schedulerCard(),
            $this->collectorCard('storage', 'disk', 'used_pct', 'storage', warning: (float) config('monitoring.thresholds.disk_warning', 80), critical: (float) config('monitoring.thresholds.disk_critical', 90)),
            $this->dependencyCard('payment', ['stripe', 'paypal', 'razorpay', 'sslcommerz', 'paystack', 'flutterwave', 'mercadopago', 'iyzico', 'xendit', 'phonepe', 'paytabs', 'payment'], 'payments'),
            $this->dependencyCard('shipping', ['shipping', 'deliverysyria', 'courier'], 'integrations'),
            $this->dependencyCard('sms', ['sms', 'twilio', 'nexmo', 'vonage'], 'integrations'),
            $this->dependencyCard('email', ['mail', 'smtp', 'email'], 'integrations'),
            $this->dependencyCard('push_notifications', ['fcm', 'firebase', 'push'], 'integrations'),
            $this->collectorCard('ssl', 'ssl', 'days_until_expiry', 'overview', warning: 21, critical: 7, higherIsBetter: true),
            $this->backupCard(),
        ]));
    }

    /**
     * A card driven by real traffic on a channel.
     */
    private function requestCard(string $label, string $channel, string $section): array
    {
        $summary = $this->reader->requestSummary('1h', channel: $channel);

        if (!$summary['has_data']) {
            return [
                'key' => $label,
                'label' => $label,
                'state' => 'no_data',
                'detail' => 'No requests recorded on this channel in the last hour.',
                'section' => $section,
                'source' => 'monitoring_request_buckets',
            ];
        }

        $errorRate = (float) $summary['error_rate'];
        $warning = (float) config('monitoring.thresholds.error_rate_warning', 1.0);
        $critical = (float) config('monitoring.thresholds.error_rate_critical', 5.0);

        return [
            'key' => $label,
            'label' => $label,
            'state' => match (true) {
                $errorRate >= $critical => 'critical',
                $errorRate >= $warning => 'degraded',
                default => 'healthy',
            },
            'value' => $summary['p95'],
            'unit' => 'ms',
            'metric_label' => 'p95',
            'errors' => $summary['errors'],
            'error_rate' => $errorRate,
            'hits' => $summary['hits'],
            'detail' => number_format($summary['hits']) . ' requests, ' . $errorRate . '% failed',
            'section' => $section,
            'source' => 'monitoring_request_buckets',
        ];
    }

    /**
     * Mobile clients, identified by the platform their requests declare.
     */
    private function platformCard(string $label, string $platform): array
    {
        $series = $this->reader->series('requests.by_platform', '1h', $platform);

        // `samples`, not the sum of `v`. requests.by_platform is a counter: the writer puts the
        // request count in the sample column and the TOTAL RESPONSE TIME in value_sum, and
        // SeriesReader hands back value_sum for a counter. Summing `v` printed 1,190 on an hour
        // that had twelve requests — and because the error is the latency, a slow app looked like
        // a busy one, which is the opposite of what this card is for.
        $requests = (int) ($series['samples'] ?? 0);

        if ($series['state'] === 'failed') {
            return [
                'key' => $label,
                'label' => $label,
                'state' => 'unavailable',
                'detail' => 'The series store could not be read, so this app\'s traffic is unknown — which is not the same as none.',
                'note' => $series['note'] ?? null,
                'section' => $platform,
                'source' => 'monitoring_series',
            ];
        }

        if ($requests <= 0) {
            return [
                'key' => $label,
                'label' => $label,
                'state' => 'no_data',
                'detail' => 'No traffic from this app in the last hour. It reports itself through the X-Platform header.',
                'section' => $platform,
                'source' => 'monitoring_series',
            ];
        }

        return [
            'key' => $label,
            'label' => $label,
            'state' => 'healthy',
            'value' => $requests,
            'unit' => 'requests',
            'metric_label' => 'last hour',
            'detail' => number_format($requests) . ' requests in the last hour',
            'section' => $platform,
            'source' => 'monitoring_series',
        ];
    }

    /**
     * A card straight off a collector metric, scored against two thresholds.
     */
    private function collectorCard(string $label, string $collector, string $metric, string $section, float $warning, float $critical, bool $higherIsBetter = false): array
    {
        $readings = $this->collectors->collect($collector);
        $reading = $readings[$metric] ?? $this->firstMatching($readings, $metric);

        if (!$reading instanceof Metric) {
            return [
                'key' => $label,
                'label' => $label,
                'state' => 'unavailable',
                'detail' => 'The ' . $collector . ' collector is not installed in this build.',
                'section' => $section,
                'source' => null,
            ];
        }

        if (!$reading->isOk()) {
            return [
                'key' => $label,
                'label' => $label,
                'state' => $reading->state === Metric::NOT_SUPPORTED ? 'not_supported' : 'not_configured',
                'detail' => $reading->note ?? 'No reading available.',
                'remedy' => $reading->remedy,
                'section' => $section,
                'source' => $reading->source,
            ];
        }

        $value = (float) $reading->value;
        $state = $higherIsBetter
            ? match (true) {
                $value <= $critical => 'critical',
                $value <= $warning => 'degraded',
                default => 'healthy',
            }
            : match (true) {
                $value >= $critical => 'critical',
                $value >= $warning => 'degraded',
                default => 'healthy',
            };

        return [
            'key' => $label,
            'label' => $label,
            'state' => $state,
            'value' => $value,
            'unit' => $reading->unit,
            'metric_label' => $metric,
            'detail' => $value . ($reading->unit ? ' ' . $reading->unit : ''),
            'section' => $section,
            'source' => $reading->source,
        ];
    }

    private function queueCard(): array
    {
        $readings = $this->collectors->collect('queue');
        if ($readings === []) {
            return [
                'key' => 'queue',
                'label' => 'queue',
                'state' => 'unavailable',
                'detail' => 'The queue collector is not installed in this build.',
                'section' => 'queues',
                'source' => null,
            ];
        }

        $oldest = $readings['oldest_wait_seconds'] ?? $this->firstMatching($readings, 'oldest_wait_seconds');
        $pending = $readings['pending'] ?? $this->firstMatching($readings, 'pending');

        if (!$oldest instanceof Metric || !$oldest->isOk()) {
            return [
                'key' => 'queue',
                'label' => 'queue',
                'state' => $oldest instanceof Metric && $oldest->state === Metric::NOT_CONFIGURED ? 'not_configured' : 'no_data',
                'detail' => $oldest instanceof Metric ? ($oldest->note ?? 'No queue reading available.') : 'No queue reading available.',
                'remedy' => $oldest instanceof Metric ? $oldest->remedy : null,
                'section' => 'queues',
                'source' => $oldest instanceof Metric ? $oldest->source : null,
            ];
        }

        $seconds = (float) $oldest->value;

        return [
            'key' => 'queue',
            'label' => 'queue',
            // Lag, not depth: a queue with three jobs stuck for an hour is in worse trouble than
            // one with three thousand flowing through it.
            'state' => match (true) {
                $seconds >= (float) config('monitoring.thresholds.queue_lag_critical_seconds', 900) => 'critical',
                $seconds >= (float) config('monitoring.thresholds.queue_lag_warning_seconds', 300) => 'degraded',
                default => 'healthy',
            },
            'value' => (int) $seconds,
            'unit' => 'seconds',
            'metric_label' => 'oldest waiting job',
            'detail' => ($pending instanceof Metric && $pending->isOk() ? number_format((float) $pending->value) . ' pending, ' : '')
                . 'oldest waiting ' . (int) $seconds . 's',
            'section' => 'queues',
            'source' => $oldest->source,
        ];
    }

    private function schedulerCard(): array
    {
        $readings = $this->collectors->collect('scheduler');
        $installed = $readings['cron_installed'] ?? null;

        if (!$installed instanceof Metric) {
            return ['key' => 'scheduler', 'label' => 'scheduler', 'state' => 'unavailable', 'detail' => 'Not installed.', 'section' => 'scheduler', 'source' => null];
        }

        if (!$installed->isOk()) {
            return [
                'key' => 'scheduler',
                'label' => 'scheduler',
                'state' => 'critical',
                'detail' => $installed->note ?? 'The scheduler is not running.',
                'remedy' => $installed->remedy,
                'section' => 'scheduler',
                'source' => $installed->source,
            ];
        }

        // valueOr(0) turns "the run ledger could not be read" into "nothing has gone wrong", which
        // on this card printed "All scheduled tasks on time" over a ledger nobody managed to open.
        // A count that was not taken stays null and the card says the outcome is unknown.
        $late = $this->counted($readings['late_tasks'] ?? null);
        $missed = $this->counted($readings['missed_tasks'] ?? null);
        $failed = $this->counted($readings['failed_tasks'] ?? null);
        $lastRun = $readings['last_run_age_minutes'] ?? null;

        if ($late === null || $missed === null || $failed === null) {
            return [
                'key' => 'scheduler',
                'label' => 'scheduler',
                'state' => 'unavailable',
                'value' => $lastRun instanceof Metric && $lastRun->isOk() ? (int) $lastRun->value : null,
                'unit' => 'minutes ago',
                'metric_label' => 'last run',
                'detail' => 'The scheduler is running, but its run ledger could not be read, so whether every task actually ran is unknown.',
                'section' => 'scheduler',
                'source' => 'Laravel Schedule + monitoring_scheduled_runs',
            ];
        }

        return [
            'key' => 'scheduler',
            'label' => 'scheduler',
            'state' => match (true) {
                $missed > 0 || $failed > 0 => 'critical',
                $late > 0 => 'degraded',
                default => 'healthy',
            },
            'value' => $lastRun instanceof Metric && $lastRun->isOk() ? (int) $lastRun->value : null,
            'unit' => 'minutes ago',
            'metric_label' => 'last run',
            'detail' => $missed + $failed + $late === 0
                ? 'All scheduled tasks on time'
                : "{$missed} missed, {$failed} failed, {$late} late",
            'section' => 'scheduler',
            'source' => 'Laravel Schedule + monitoring_scheduled_runs',
        ];
    }

    /** A count that was actually taken, or null where the reading did not arrive. */
    private function counted(mixed $metric): ?int
    {
        return $metric instanceof Metric && $metric->isOk() ? (int) $metric->value : null;
    }

    /**
     * An outbound dependency, matched by any of the service keys it might have been recorded under.
     */
    private function dependencyCard(string $label, array $serviceKeys, string $section): array
    {
        try {
            $row = $this->reader->connection()->table('monitoring_dependency_buckets')
                ->where('resolution', 'minute')
                ->where('bucket_at', '>=', Clock::hoursAgo(1))
                ->where(function ($query) use ($serviceKeys) {
                    foreach ($serviceKeys as $key) {
                        $query->orWhere('service', 'like', '%' . $key . '%');
                    }
                })
                ->selectRaw('SUM(calls) calls, SUM(failures) failures, SUM(duration_sum_ms) duration_sum, MAX(last_success_at) last_success')
                ->first();

            $calls = (int) ($row->calls ?? 0);
            if ($calls === 0) {
                return [
                    'key' => $label,
                    'label' => $label,
                    'state' => 'no_data',
                    'detail' => 'No calls to this service recorded in the last hour.',
                    'section' => $section,
                    'source' => 'monitoring_dependency_buckets',
                ];
            }

            $failures = (int) ($row->failures ?? 0);
            $failureRate = 100 * $failures / $calls;

            return [
                'key' => $label,
                'label' => $label,
                'state' => match (true) {
                    $failureRate >= 10 => 'critical',
                    $failureRate >= 2 => 'degraded',
                    default => 'healthy',
                },
                'value' => (int) round((int) ($row->duration_sum ?? 0) / $calls),
                'unit' => 'ms',
                'metric_label' => 'average',
                'errors' => $failures,
                'error_rate' => round($failureRate, 2),
                'last_healthy' => $row->last_success ?? null,
                'detail' => number_format($calls) . ' calls, ' . round($failureRate, 1) . '% failed',
                'section' => $section,
                'source' => 'monitoring_dependency_buckets',
            ];
        } catch (\Throwable) {
            return ['key' => $label, 'label' => $label, 'state' => 'no_data', 'detail' => 'No dependency data available.', 'section' => $section, 'source' => null];
        }
    }

    private function backupCard(): array
    {
        try {
            $latest = $this->reader->connection()->table('monitoring_backups')
                ->where('status', 'success')
                ->orderByDesc('started_at')
                ->first();

            if ($latest === null) {
                return [
                    'key' => 'backups',
                    'label' => 'backups',
                    'state' => 'not_configured',
                    'detail' => 'No backup has ever been recorded.',
                    'remedy' => 'Report each backup to monitoring with `php artisan monitoring:backup-recorded` as the last line of whatever script takes it; monitoring records backups, it does not take them.',
                    'section' => 'backups',
                    'source' => 'monitoring_backups',
                ];
            }

            $hours = (int) Clock::parse($latest->started_at)->diffInHours(Clock::now());
            $warningHours = (int) config('monitoring.thresholds.backup_age_warning_hours', 36);

            return [
                'key' => 'backups',
                'label' => 'backups',
                'state' => match (true) {
                    $hours > $warningHours * 2 => 'critical',
                    $hours > $warningHours => 'degraded',
                    default => 'healthy',
                },
                'value' => $hours,
                'unit' => 'hours ago',
                'metric_label' => 'last successful backup',
                'detail' => 'Last successful backup ' . $hours . 'h ago'
                    . ($latest->restore_tested_at ? '' : ' — restore never tested'),
                'section' => 'backups',
                'source' => 'monitoring_backups',
            ];
        } catch (\Throwable) {
            return ['key' => 'backups', 'label' => 'backups', 'state' => 'no_data', 'detail' => 'No backup data available.', 'section' => 'backups', 'source' => null];
        }
    }

    /**
     * The dependency graph, with each node's live state.
     *
     * Drawn from what the application actually is — its configured cache, queue and filesystem
     * drivers, and the outbound services it has really called — rather than a fixed picture, so it
     * cannot show a Redis box on a deployment that does not use Redis.
     *
     * @return array<string, mixed>
     */
    private function serviceMap(): array
    {
        $cards = collect($this->serviceCards())->keyBy('key');
        $stateOf = static fn (string $key) => $cards[$key]['state'] ?? 'unavailable';

        $nodes = [
            ['id' => 'user', 'label' => 'shoppers', 'tier' => 0, 'state' => 'healthy', 'fixed' => true],
            ['id' => 'cdn', 'label' => 'cdn', 'tier' => 1, 'state' => 'unavailable', 'detail' => 'No CDN is configured for this deployment.'],
            ['id' => 'webserver', 'label' => 'web_server', 'tier' => 2, 'state' => $this->webServerState()],
            ['id' => 'laravel', 'label' => 'application', 'tier' => 3, 'state' => $stateOf('web_store')],
            ['id' => 'database', 'label' => 'database', 'tier' => 4, 'state' => $stateOf('database')],
            ['id' => 'redis', 'label' => 'redis_and_cache', 'tier' => 4, 'state' => $stateOf('redis_and_cache')],
            ['id' => 'queue', 'label' => 'queue', 'tier' => 4, 'state' => $stateOf('queue')],
            ['id' => 'storage', 'label' => 'storage', 'tier' => 4, 'state' => $stateOf('storage')],
            ['id' => 'payment', 'label' => 'payment', 'tier' => 5, 'state' => $stateOf('payment')],
            ['id' => 'shipping', 'label' => 'shipping', 'tier' => 5, 'state' => $stateOf('shipping')],
            ['id' => 'sms', 'label' => 'sms', 'tier' => 5, 'state' => $stateOf('sms')],
            ['id' => 'email', 'label' => 'email', 'tier' => 5, 'state' => $stateOf('email')],
            ['id' => 'push', 'label' => 'push_notifications', 'tier' => 5, 'state' => $stateOf('push_notifications')],
        ];

        $edges = [
            ['from' => 'user', 'to' => 'cdn'],
            ['from' => 'cdn', 'to' => 'webserver'],
            ['from' => 'user', 'to' => 'webserver'],
            ['from' => 'webserver', 'to' => 'laravel'],
            ['from' => 'laravel', 'to' => 'database'],
            ['from' => 'laravel', 'to' => 'redis'],
            ['from' => 'laravel', 'to' => 'queue'],
            ['from' => 'laravel', 'to' => 'storage'],
            ['from' => 'laravel', 'to' => 'payment'],
            ['from' => 'laravel', 'to' => 'shipping'],
            ['from' => 'queue', 'to' => 'sms'],
            ['from' => 'queue', 'to' => 'email'],
            ['from' => 'queue', 'to' => 'push'],
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function webServerState(): string
    {
        $readings = $this->collectors->collect('webserver');
        $reading = $readings['server'] ?? $readings['software'] ?? null;

        return $reading instanceof Metric && $reading->isOk() ? 'healthy' : 'unavailable';
    }

    /**
     * What an operator should look at first, ordered by how much it matters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function needsAttention(array $health): array
    {
        $items = [];

        if ($health['stale']) {
            $items[] = [
                'severity' => 'critical',
                'title' => 'monitoring_has_stopped_receiving_data',
                'detail' => 'the_dashboard_cannot_see_the_shop_right_now_so_nothing_below_can_be_trusted_as_current',
                'section' => 'settings',
            ];
        }

        foreach ($health['signals'] as $signal) {
            if ($signal['measured'] && $signal['score'] !== null && $signal['score'] <= 50) {
                $items[] = [
                    'severity' => $signal['score'] <= 25 ? 'critical' : 'warning',
                    'title' => $signal['label'],
                    'detail' => $signal['display'],
                    'remedy' => $signal['remedy'] ?? null,
                    'section' => $this->sectionForSignal($signal['key']),
                ];
            }
        }

        // Signals we cannot measure at all are worth surfacing once, quietly: they are the gaps in
        // what this dashboard can promise.
        $unmeasured = array_values(array_filter(
            $health['signals'],
            static fn (array $signal) => !$signal['measured'] && ($signal['remedy'] ?? null) !== null,
        ));
        foreach (array_slice($unmeasured, 0, 3) as $signal) {
            $items[] = [
                'severity' => 'info',
                'title' => translate($signal['label']) . ' — ' . translate('is_not_being_measured'),
                'detail' => $signal['display'],
                'remedy' => $signal['remedy'],
                'section' => $this->sectionForSignal($signal['key']),
            ];
        }

        return $items;
    }

    private function sectionForSignal(string $key): string
    {
        return match ($key) {
            'availability', 'latency' => 'requests',
            'database' => 'database',
            'cpu', 'memory' => 'server',
            'disk' => 'storage',
            'queue' => 'queues',
            'scheduler' => 'scheduler',
            'redis' => 'redis',
            'dependencies' => 'integrations',
            'errors' => 'errors',
            default => 'overview',
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function recentEvents(): array
    {
        try {
            return $this->reader->connection()->table('monitoring_events')
                ->orderByDesc('occurred_at')
                ->limit(12)
                ->get(['type', 'severity', 'title', 'occurred_at'])
                ->map(static fn ($row) => [
                    'type' => $row->type,
                    // `type` is a plain column: a foreign producer can put anything in it, and
                    // translate() MINTS a language key for whatever it is handed. Without this the
                    // events strip would write a new key into new-messages.php for every unknown
                    // value that ever lands in the table.
                    'type_known' => in_array($row->type, EventLog::TYPES, true),
                    'severity' => $row->severity,
                    'title' => $row->title,
                    'at' => Clock::display($row->occurred_at)->toDateTimeString(),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function openIncidents(): array
    {
        try {
            return $this->reader->connection()->table('monitoring_incidents')
                ->whereIn('status', ['open', 'investigating', 'monitoring'])
                ->orderByDesc('started_at')
                ->limit(5)
                ->get(['reference', 'title', 'severity', 'status', 'started_at', 'probable_cause'])
                ->map(static fn ($row) => [
                    'reference' => $row->reference,
                    'title' => $row->title,
                    'severity' => $row->severity,
                    'status' => $row->status,
                    'started_at' => Clock::display($row->started_at)->toDateTimeString(),
                    'probable_cause' => $row->probable_cause,
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, Metric> $readings */
    private function firstMatching(array $readings, string $prefix): ?Metric
    {
        foreach ($readings as $name => $reading) {
            if ($name === $prefix || str_starts_with($name, $prefix . '@')) {
                return $reading;
            }
        }

        return null;
    }
}
