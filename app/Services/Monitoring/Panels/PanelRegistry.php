<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\HealthScoreService;
use App\Services\Monitoring\Ingest\MetricSink;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a section to its panel, and answers with something honest when a panel does not exist
 * yet.
 *
 * The "not built yet" path matters. A section listed in the navigation with no panel behind it
 * would otherwise render an empty page that looks like "there is no data" — which is the exact
 * confusion this whole system is built to avoid. So an unimplemented section says so, in the same
 * vocabulary every unavailable metric uses.
 */
class PanelRegistry
{
    /** @var array<string, class-string<Panel>> */
    private const PANELS = [
        'overview' => OverviewPanel::class,
        'live' => LiveTrafficPanel::class,
        'requests' => RequestsPanel::class,
        'errors' => ErrorsPanel::class,
        'traces' => TracesPanel::class,
        'logs' => LogsPanel::class,
        'database' => DatabasePanel::class,
        'redis' => RedisPanel::class,
        'queues' => QueuesPanel::class,
        'scheduler' => SchedulerPanel::class,
        'server' => ServerPanel::class,
        'network' => NetworkPanel::class,
        'storage' => StoragePanel::class,
        'webserver' => WebServerPanel::class,
        'energy' => EnergyPanel::class,
        'application' => ApplicationPanel::class,
        'timeline' => TimelinePanel::class,
        'incidents' => IncidentsPanel::class,
        'alerts' => AlertsPanel::class,
        'settings' => SettingsPanel::class,
        'deployments' => DeploymentsPanel::class,
        'backups' => BackupsPanel::class,
        'synthetics' => SyntheticsPanel::class,
        'sla' => SlaPanel::class,
        'security' => SecurityPanel::class,
        'payments' => PaymentsPanel::class,
        'orders' => OrderIntegrityPanel::class,
        'inventory' => InventoryIntegrityPanel::class,
        'search' => SearchIndexPanel::class,
        'integrations' => IntegrationsPanel::class,
        'apis' => ApisPanel::class,
        'web-vitals' => WebVitalsPanel::class,
        'android' => AndroidPanel::class,
        'ios' => IosPanel::class,
    ];

    public function __construct(
        private readonly HealthScoreService $health,
        private readonly CollectorRegistry $collectors,
        private readonly MetricSink $sink,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    /** Is there a panel behind this section, or does it open on the "not installed" page? */
    public static function has(string $section): bool
    {
        $class = self::PANELS[$section] ?? null;

        return $class !== null && class_exists($class);
    }

    public function data(string $section, string $range, Request $request): array
    {
        $class = self::PANELS[$section] ?? null;

        if ($class === null || !class_exists($class)) {
            return [
                'state' => 'not_built',
                'message' => 'This section is part of the monitoring system but its panel is not installed in this build.',
            ];
        }

        try {
            return array_merge(['state' => 'ok'], app($class)->data($range, $request));
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'state' => 'failed',
                'message' => Metric::describeFailure($exception),
            ];
        }
    }

    /**
     * The header strip: status, score, and whether monitoring itself is still alive.
     *
     * Polled every few seconds, so it stays to two cheap reads. Everything expensive belongs in a
     * section, not here — a monitoring page that costs more than the pages it monitors is its own
     * worst problem.
     *
     * @return array<string, mixed>
     */
    public function pulse(): array
    {
        $health = $this->health->evaluate();

        return [
            'status' => $health['status'],
            'score' => $health['score'],
            'coverage' => $health['coverage'],
            'stale' => $health['stale'],
            'self' => $this->selfHealth(),
            'generated_at' => Clock::now()->toIso8601String(),
            'display_timezone' => Clock::displayTimezone(),
        ];
    }

    /**
     * Monitoring watching itself.
     *
     * Without this the dashboard's most dangerous failure is invisible: a collector that has
     * stopped makes every chart flat and every threshold quiet, which looks exactly like a calm,
     * healthy shop.
     *
     * @return array<string, mixed>
     */
    public function selfHealth(): array
    {
        $reader = app(SeriesReader::class);
        $newestSeries = $reader->newestBucketAt('monitoring_series');
        $newestRequests = $reader->newestBucketAt('monitoring_request_buckets');
        $staleAfter = (int) config('monitoring.stale_after_seconds', 180);

        $ageOf = static function (?string $stamp) use ($staleAfter): array {
            if ($stamp === null) {
                return ['state' => 'no_data', 'age_seconds' => null, 'stale' => true];
            }
            $age = (int) Clock::parse($stamp)->diffInSeconds(Clock::now());

            return ['state' => $age > $staleAfter ? 'stale' : 'ok', 'age_seconds' => $age, 'stale' => $age > $staleAfter];
        };

        return [
            'collection_enabled' => (bool) config('monitoring.enabled', true),
            'buffer' => ['driver' => $this->sink->driver(), 'description' => $this->sink->describe()],
            'gauges' => $ageOf($newestSeries),
            'requests' => $ageOf($newestRequests),
            'tracing' => [
                'enabled' => (bool) config('monitoring.tracing.enabled', true),
                'sample_rate' => (float) config('monitoring.tracing.sample_rate', 0.02),
            ],
            'storage' => $this->storageFootprint(),
            'retention' => $this->liveRetention(),
        ];
    }

    /**
     * How much room monitoring is taking, so it can be held to its own standard.
     *
     * @return array<string, mixed>
     */
    private function storageFootprint(): array
    {
        try {
            $connection = DB::connection(config('monitoring.connection', 'monitoring'));
            $database = $connection->getDatabaseName();

            $rows = $connection->select(
                'SELECT table_name AS name, data_length + index_length AS bytes, table_rows AS rows_estimate
                 FROM information_schema.tables
                 WHERE table_schema = ? AND table_name LIKE ?',
                [$database, 'monitoring_%'],
            );

            $total = 0;
            $tables = [];
            foreach ($rows as $row) {
                $total += (int) $row->bytes;
                $tables[] = [
                    'table' => $row->name,
                    'mb' => round((int) $row->bytes / 1048576, 2),
                    'rows' => (int) $row->rows_estimate,
                ];
            }
            usort($tables, static fn ($a, $b) => $b['mb'] <=> $a['mb']);

            return [
                'state' => 'ok',
                'total_mb' => round($total / 1048576, 2),
                'separate_database' => $database !== config('database.connections.' . config('database.default') . '.database'),
                'tables' => array_slice($tables, 0, 10),
            ];
        } catch (\Throwable $exception) {
            // information_schema is readable on every deployment tested, but a locked-down grant is
            // possible — and the size of the monitoring store is not worth an error page.
            return ['state' => 'unavailable', 'reason' => class_basename($exception)];
        }
    }
    /**
     * The retention windows actually in force, which are the stored ones where they exist.
     *
     * Reported rather than the shipped block, because the page that shows this is the page an
     * operator uses to decide whether their data is being kept — and the shipped number stopped
     * being the answer the moment the Settings page could change it.
     *
     * @return array<string, int>
     */
    private function liveRetention(): array
    {
        $settings = app(MonitoringSettings::class);
        $live = [];

        foreach ((array) config('monitoring.retention', []) as $kind => $default) {
            $live[$kind] = $settings->retentionDays($kind, (int) $default);
        }

        return $live;
    }

}
