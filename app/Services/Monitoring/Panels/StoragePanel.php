<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * Storage: how much room is left, what the devices underneath are doing, and whether the
 * application can still write where Laravel expects to write.
 *
 * Three decisions shape the section, and each of them is a way storage pages usually go wrong.
 *
 * A filesystem is not a disk. Fullness belongs to a mount point, I/O belongs to a block device,
 * and the two do not line up — a volume can fill while the device it shares with everything else
 * sits idle. So mounts and devices are separate cards, split apart by the label the disk collector
 * publishes after the "@" in each metric name, rather than folded into one "disk" that would be
 * wrong on any host with more than one of either.
 *
 * Free space is not the finding; fullness is. Nineteen gigabytes free is comfortable on a laptop
 * and four hours from an outage on an order database, so the used percentage is the headline and
 * it is scored against the same disk_warning / disk_critical thresholds the health score and the
 * scheduled storage check already read. One number, one colour, everywhere on the dashboard.
 *
 * Inodes get equal billing with blocks. A filesystem can refuse to create a file with 200 GB free,
 * and that outage costs hours precisely because nothing on the dashboard hinted at it.
 */
class StoragePanel implements Panel
{
    /** The two collectors this section is made of. Each is read exactly once per request. */
    private const COLLECTORS = ['disk', 'storage'];

    /** A mount's readings, in the order the card reads them. Presence of any of these IS a mount. */
    private const MOUNT_METRICS = ['total', 'used', 'free', 'used_pct', 'inodes_total', 'inodes_used', 'inode_used_pct'];

    /** A device's readings, likewise. Presence of any of these IS a block device. */
    private const DEVICE_METRICS = [
        'read_iops', 'write_iops', 'read_mbps', 'write_mbps',
        'read_latency_ms', 'write_latency_ms', 'util_pct', 'queue_depth',
    ];

    /** Mount readings held in bytes, republished in gigabytes because nobody reads 270553174016. */
    private const MOUNT_GIGABYTES = ['total', 'used', 'free'];

    /** Application readings held in bytes. Megabytes, because these are directories, not volumes. */
    private const APPLICATION_MEGABYTES = ['logs_size', 'newest_log_size', 'public_size', 'compiled_views_size', 'framework_cache_size', 'sessions_size'];

    /** @var array<string, array{metric: string, unit: string, title: string, source: string}> */
    private const MOUNT_CHARTS = [
        'used_pct' => ['metric' => 'server.disk.used_pct', 'unit' => '%', 'title' => 'space_used_over_time', 'source' => 'used_pct'],
        'inode_used_pct' => ['metric' => 'server.disk.inode_used_pct', 'unit' => '%', 'title' => 'inodes_used_over_time', 'source' => 'inode_used_pct'],
    ];

    /** @var array<string, array{metric: string, unit: string, title: string, source: string}> */
    private const DEVICE_CHARTS = [
        'read_iops' => ['metric' => 'server.disk.read_iops', 'unit' => 'IO/s', 'title' => 'reads_over_time', 'source' => 'read_iops'],
        'write_iops' => ['metric' => 'server.disk.write_iops', 'unit' => 'IO/s', 'title' => 'writes_over_time', 'source' => 'write_iops'],
        'util_pct' => ['metric' => 'server.disk.util_pct', 'unit' => '%', 'title' => 'utilisation_over_time', 'source' => 'util_pct'],
    ];

    /** @var array<string, array{metric: string, unit: string, title: string, source: string}> */
    private const APPLICATION_CHARTS = [
        'logs_mb' => ['metric' => 'storage.logs_mb', 'unit' => 'MB', 'title' => 'log_size_over_time', 'source' => 'logs_size'],
        'public_mb' => ['metric' => 'storage.public_mb', 'unit' => 'MB', 'title' => 'public_disk_size_over_time', 'source' => 'public_size'],
        'compiled_views_count' => ['metric' => 'storage.compiled_views_count', 'unit' => 'files', 'title' => 'compiled_views_over_time', 'source' => 'compiled_views'],
        'sessions_count' => ['metric' => 'storage.sessions_count', 'unit' => 'files', 'title' => 'sessions_over_time', 'source' => 'sessions'],
    ];

    /**
     * The application's own storage, grouped by what breaks when the group is wrong.
     *
     * @var array<string, array{why: string, metrics: list<string>}>
     */
    private const APPLICATION_GROUPS = [
        'directories_the_application_must_be_able_to_write' => [
            'why' => 'each_of_these_is_proven_by_writing_a_byte_and_removing_it_again_because_permission_bits_still_read_as_writable_on_a_read_only_remount_and_on_a_full_filesystem',
            'metrics' => [
                'storage_writable', 'framework_views_writable', 'framework_cache_writable',
                'framework_sessions_writable', 'bootstrap_cache_writable', 'public_writable',
            ],
        ],
        'logs' => [
            'why' => 'an_unrotated_log_file_is_the_most_common_way_a_healthy_server_runs_out_of_disk',
            'metrics' => ['logs_size', 'log_files', 'newest_log', 'newest_log_size', 'newest_log_age_minutes'],
        ],
        'compiled_views_and_file_cache' => [
            'why' => 'what_the_framework_has_written_for_itself_which_grows_across_deploys_and_is_safe_to_clear',
            'metrics' => ['compiled_views', 'compiled_views_size', 'framework_cache_files', 'framework_cache_size'],
        ],
        'sessions' => [
            'why' => 'one_file_per_signed_in_visitor_so_the_count_is_traffic_and_the_expired_count_is_what_the_sweeper_has_not_collected_yet',
            'metrics' => ['sessions', 'sessions_size', 'sessions_expired'],
        ],
        'public_disk_and_symlink' => [
            'why' => 'a_deploy_that_copies_public_turns_the_storage_symlink_into_an_ordinary_directory_which_serves_yesterdays_uploads_forever_without_erroring_once',
            'metrics' => ['public_size', 'public_files', 'public_storage_link'],
        ],
        'default_filesystem_disk' => [
            'why' => 'where_uploads_actually_land_and_whether_this_deployment_can_reach_it',
            'metrics' => ['default_disk', 'cloud_bucket', 'cloud_region', 'cloud_endpoint', 'cloud_credentials_set', 'cloud_reachable'],
        ],
    ];

    /**
     * Disk readings the storage collector also publishes, which the application cards draw.
     *
     * Both collectors probe storage/ and storage/logs. Drawing both would put the same two numbers
     * on the page twice under different provenance and read as a disagreement waiting to happen.
     */
    private const RENDERED_ELSEWHERE = ['disk' => ['storage_writable', 'logs_size'], 'storage' => []];

    /**
     * A hard ceiling on the rows one render may pull out of monitoring_series.
     *
     * The window and the resolution already bound the query; this bounds the other axis, which is
     * the host's — a machine with forty block devices would otherwise ask for forty lines' worth of
     * points to draw a page nobody can read anyway. When the cap bites, the oldest points are the
     * ones lost and the charts say so rather than quietly starting late.
     */
    private const MAX_SERIES_ROWS = 4000;

    private const BYTES_PER_GB = 1073741824;

    private const BYTES_PER_MB = 1048576;

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);

        // Collected once each and reused everywhere below. The disk collector reports per-device
        // I/O as a delta against a cached previous sample, so a second call inside the same request
        // would find its own first call's reading to subtract from and report an idle machine.
        $readings = [
            'disk' => $this->collectors->collect('disk'),
            'storage' => $this->collectors->collect('storage'),
        ];

        $labelled = $this->byLabel($readings['disk']);
        $stored = $this->storedSeries($range, $window['resolution']);
        $mounts = $this->mounts($labelled, $stored, $window['resolution']);
        $devices = $this->devices($labelled, $stored, $window['resolution']);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'thresholds' => [
                'warning_pct' => $this->threshold('disk_warning', 80.0),
                'critical_pct' => $this->threshold('disk_critical', 90.0),
            ],
            'collectors' => $this->collectorFaults($readings),
            'mounts' => $mounts,
            'devices' => $devices,
            // Why there are no device cards, when there are none. Without it an absent block-device
            // section reads as a machine doing no I/O, which is a very different claim.
            'device_io' => $this->deviceIoGap($readings['disk'], $devices),
            'application' => $this->applicationGroups($readings['storage']),
            'application_charts' => $this->applicationCharts($readings['storage'], $stored, $window['resolution']),
            'series' => ['state' => $stored['state'], 'note' => $stored['note'], 'truncated' => $stored['truncated']],
            'history_only' => $this->historyOnlyLabels($stored, $mounts, $devices),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Grouping

    /**
     * Split the disk collector's readings by the label after the "@" in each metric name.
     *
     * `used_pct@/` and `read_iops@vda` are one flat list from the collector and two different kinds
     * of thing on the page. The empty-string key holds everything published without a label —
     * `device_io`, and the fallback readings the collector emits when no mount could be resolved
     * at all.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, array<string, Metric>>
     */
    private function byLabel(array $readings): array
    {
        $grouped = [];

        foreach ($readings as $key => $metric) {
            if ($key === '__collector' || !$metric instanceof Metric) {
                continue;
            }

            [$name, $label] = array_pad(explode('@', $key, 2), 2, '');
            $grouped[$label][$name] = $metric;
        }

        return $grouped;
    }

    /**
     * One card per filesystem.
     *
     * @param  array<string, array<string, Metric>>  $labelled
     * @param  array<string, mixed>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function mounts(array $labelled, array $stored, string $resolution): array
    {
        $warning = $this->threshold('disk_warning', 80.0);
        $critical = $this->threshold('disk_critical', 90.0);
        $mounts = [];

        foreach ($labelled as $label => $metrics) {
            if (array_intersect(self::MOUNT_METRICS, array_keys($metrics)) === []) {
                continue;
            }

            $mounts[] = [
                'key' => $label,
                // An unlabelled mount reading is the collector's own fallback for a host where no
                // application path could be resolved; it is still a real state, not a nameless bug.
                'label' => $label === '' ? null : $label,
                'note' => $this->firstNote($metrics),
                'space' => $this->usage($metrics['used_pct'] ?? null, $warning, $critical),
                'inodes' => $this->usage($metrics['inode_used_pct'] ?? null, $warning, $critical),
                'metrics' => $this->ordered($metrics, self::MOUNT_METRICS, self::MOUNT_GIGABYTES, self::BYTES_PER_GB, 'GB'),
                'charts' => $this->charts(self::MOUNT_CHARTS, $metrics, $label, $stored, $resolution),
            ];
        }

        usort($mounts, static fn (array $first, array $second) => ($first['key'] ?? '') <=> ($second['key'] ?? ''));

        return $mounts;
    }

    /**
     * One card per block device.
     *
     * @param  array<string, array<string, Metric>>  $labelled
     * @param  array<string, mixed>  $stored
     * @return array<int, array<string, mixed>>
     */
    private function devices(array $labelled, array $stored, string $resolution): array
    {
        $devices = [];

        foreach ($labelled as $label => $metrics) {
            if ($label === '' || array_intersect(self::DEVICE_METRICS, array_keys($metrics)) === []) {
                continue;
            }

            $devices[] = [
                'key' => $label,
                'label' => $label,
                // Drawn, but deliberately not scored. There is no device-utilisation threshold in
                // Settings, and a busy disk is not a failing one — a backup running flat out at
                // 100% is the machine doing its job. Colouring it against a number invented here
                // would teach the eye a state no alert rule shares.
                'utilisation' => $this->usage($metrics['util_pct'] ?? null, warning: null, critical: null),
                'metrics' => $this->ordered($metrics, self::DEVICE_METRICS),
                'charts' => $this->charts(self::DEVICE_CHARTS, $metrics, $label, $stored, $resolution),
            ];
        }

        usort($devices, static fn (array $first, array $second) => $first['key'] <=> $second['key']);

        return $devices;
    }

    /**
     * The readings a card shows, in a fixed order, with byte counts republished in a unit a person
     * can read.
     *
     * Metric::map() is what makes that conversion safe: it carries the state, the provenance and
     * the note across, so an unreadable byte count stays unreadable rather than becoming 0 GB.
     *
     * @param  array<string, Metric>  $metrics
     * @param  list<string>  $order
     * @param  list<string>  $convert
     * @return array<string, Metric>
     */
    private function ordered(array $metrics, array $order, array $convert = [], int $divisor = 1, string $unit = ''): array
    {
        $card = [];

        foreach ($order as $name) {
            $metric = $metrics[$name] ?? null;
            if (!$metric instanceof Metric) {
                continue;
            }

            $card[$name] = in_array($name, $convert, true)
                ? $metric->map(static fn (mixed $bytes) => is_numeric($bytes) ? round((float) $bytes / $divisor, 2) : null, $unit)
                : $metric;
        }

        return $card;
    }

    /**
     * A percentage ready to be drawn as a bar, scored where there is a threshold to score it
     * against and left plain where there is not.
     *
     * Inodes are scored against the same two numbers as blocks. There is no separate inode
     * threshold in Settings, and inventing one here would put a figure on the page that the alert
     * rules do not share — a filesystem 92% out of inodes is exactly as close to refusing writes as
     * one 92% out of space.
     *
     * @return array<string, mixed>
     */
    private function usage(?Metric $metric, ?float $warning, ?float $critical): array
    {
        $base = ['warning_pct' => $warning, 'critical_pct' => $critical, 'pct' => null, 'level' => 'unknown'];

        if (!$metric instanceof Metric) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'The collector published no percentage for this label.',
                'remedy' => null,
                'source' => null,
            ]);
        }

        if (!$metric->isOk() || !is_numeric($metric->value)) {
            return array_merge($base, [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note,
                'remedy' => $metric->remedy,
                'source' => $metric->source,
            ]);
        }

        $value = (float) $metric->value;

        return array_merge($base, [
            'state' => 'ok',
            'pct' => round($value, 1),
            'level' => match (true) {
                $warning === null || $critical === null => 'unscored',
                $value >= $critical => 'critical',
                $value >= $warning => 'degraded',
                default => 'healthy',
            },
            'note' => $metric->note,
            'remedy' => null,
            'source' => $metric->source,
        ]);
    }

    /**
     * The note the collector attached to this mount — which device it is and what it holds.
     *
     * @param  array<string, Metric>  $metrics
     */
    private function firstNote(array $metrics): ?string
    {
        foreach ($metrics as $metric) {
            if ($metric->isOk() && $metric->note !== null && $metric->note !== '') {
                return $metric->note;
            }
        }

        return null;
    }

    private function threshold(string $name, float $default): float
    {
        return $this->settings->threshold($name, $default) ?? $default;
    }

    // -------------------------------------------------------------------------------------------
    // Stored gauges

    /**
     * Every gauge this section draws, for every label, in one read.
     *
     * SeriesReader::series() reads one metric and one label at a time, which is right for a page
     * with a fixed handful of lines and wrong for this one: a host with six devices would cost
     * twenty-odd round trips to draw a single screen. The window, the resolution and the UTC
     * boundary still come from the reader — only the grouping happens here, on the same index
     * (metric, resolution, bucket_at) every other series read uses.
     *
     * @return array{state: string, note: string|null, truncated: bool, points: array<string, array<string, list<array{t: string, v: float|null}>>>}
     */
    private function storedSeries(string $range, string $resolution): array
    {
        $metrics = array_values(array_unique(array_merge(
            array_column(self::MOUNT_CHARTS, 'metric'),
            array_column(self::DEVICE_CHARTS, 'metric'),
            array_column(self::APPLICATION_CHARTS, 'metric'),
        )));

        try {
            $rows = $this->reader->connection()->table('monitoring_series')
                ->whereIn('metric', $metrics)
                ->where('resolution', $resolution)
                ->where('bucket_at', '>=', $this->reader->since($range))
                // Newest first, so a window wider than the cap loses its oldest points rather than
                // its most recent ones — a line that stops an hour ago is a lie about right now.
                ->orderByDesc('bucket_at')
                ->limit(self::MAX_SERIES_ROWS)
                ->get(['metric', 'label', 'bucket_at', 'samples', 'value_sum', 'value_last']);
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this as well, but it can only blank the whole section.
            // Failing the charts alone leaves every live reading on the page readable.
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'truncated' => false,
                'points' => [],
            ];
        }

        $points = [];
        foreach ($rows as $row) {
            // Mirrors SeriesReader::series(): a gauge's honest value for a bucket is its last
            // reading, a counter's is its sum, and value_last being null is how the two are told
            // apart. Kept identical deliberately — two readings of the same stored row must not
            // disagree between this page and any other.
            $value = $row->value_last !== null
                ? (float) $row->value_last
                : ((int) $row->samples > 0 ? (float) $row->value_sum : null);

            $points[$row->metric][(string) $row->label][] = [
                't' => Clock::parse($row->bucket_at)->toIso8601String(),
                'v' => $value,
            ];
        }

        foreach ($points as $metric => $labels) {
            foreach ($labels as $label => $series) {
                $points[$metric][$label] = array_reverse($series);
            }
        }

        return [
            'state' => 'ok',
            'note' => null,
            'truncated' => count($rows) >= self::MAX_SERIES_ROWS,
            'points' => $points,
        ];
    }

    /**
     * @param  array<string, array{metric: string, unit: string, title: string, source: string}>  $definitions
     * @param  array<string, Metric>  $metrics
     * @param  array<string, mixed>  $stored
     * @return array<string, array<string, mixed>>
     */
    private function charts(array $definitions, array $metrics, string $label, array $stored, string $resolution): array
    {
        $charts = [];

        foreach ($definitions as $key => $definition) {
            $charts[$key] = $this->chart($key, $definition, $label, $metrics[$definition['source']] ?? null, $stored, $resolution);
        }

        return $charts;
    }

    /**
     * One stored gauge over the window, carrying why it has no line when it has none.
     *
     * @param  array{metric: string, unit: string, title: string, source: string}  $definition
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function chart(string $key, array $definition, string $label, ?Metric $live, array $stored, string $resolution): array
    {
        $points = array_values(array_filter(
            $stored['points'][$definition['metric']][$label] ?? [],
            static fn (array $point) => $point['v'] !== null,
        ));

        $chart = [
            'key' => $key,
            'metric' => $definition['metric'],
            'label' => $label,
            'unit' => $definition['unit'],
            'title' => $definition['title'],
            'latest' => $points === [] ? null : end($points)['v'],
            // Stored points, not stored samples. At hour and day resolution one row is a rollup of
            // many samples — its own `samples` column — so counting rows and calling the answer
            // "samples" printed 24 over a window that holds about 1,440 of them.
            'stored_points' => count($points),
            'points' => $points,
        ];

        if ($stored['state'] !== 'ok') {
            return array_merge($chart, ['state' => 'failed', 'note' => $stored['note'], 'remedy' => null]);
        }

        // One point is a reading; a line needs two. Saying which of those it is stops a single
        // sample being read as a flat trend.
        if (count($points) < 2) {
            return array_merge($chart, $this->gaugeGap($resolution, count($points), $live, $stored['truncated']));
        }

        return array_merge($chart, [
            'state' => 'ok',
            'note' => $stored['truncated']
                ? 'This render hit its ' . self::MAX_SERIES_ROWS . '-row read cap, so the line may start later than the window does.'
                : null,
            'remedy' => null,
        ]);
    }

    /**
     * Why a gauge has no line.
     *
     * Five different silences with five different answers, and the empty chart they all draw looks
     * identical.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points, ?Metric $live, bool $truncated): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        if ($live instanceof Metric && !$live->isOk()) {
            // The sampler only stores a reading that is OK, so an unreadable metric has never been
            // written. The gap is this host, not the scheduler, and the reading says which.
            return [
                'state' => $live->state,
                'note' => 'This gauge is only stored while the reading behind it is available, and it is not on this host. '
                    . ($live->note ?? 'The collector returned no value for it.'),
                'remedy' => $live->remedy,
            ];
        }

        if ($truncated) {
            // This render threw the older rows away itself. Reporting that as "nothing was stored"
            // would be a claim about the store that this panel never checked — and the charts that
            // did survive the same read are, two cards up, saying the opposite.
            return [
                'state' => 'no_data',
                'note' => 'This render hit its ' . self::MAX_SERIES_ROWS . '-row read cap and dropped the oldest rows first, so whether this gauge has points earlier in the window is unknown rather than answered.',
                'remedy' => 'Choose a shorter range, which reads fewer rows per gauge and leaves the cap unreached.',
            ];
        }

        if ($resolution !== 'minute') {
            // Gauges are written once a minute. Longer ranges read rolled-up rows, which the rollup
            // produces — so this window can be empty while the minute rows under it are full.
            return [
                'state' => 'no_data',
                'note' => 'This range reads ' . $resolution . ' rows, which the monitoring rollup builds from the minute samples rather than the sampler writing directly.',
                'remedy' => 'Choose a shorter range to read the minute samples, or check the hourly rollup is running: `php artisan schedule:list`.',
            ];
        }

        return [
            'state' => 'no_data',
            'note' => $points === 1
                ? 'Only one sample has been stored in this window, and one point is not a line.'
                : 'No sample of this gauge has been stored in this window.',
            'remedy' => 'Gauges are sampled by `php artisan monitoring:flush`, scheduled every minute. Check the Laravel scheduler is running: `php artisan schedule:list`.',
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The application's own storage

    /**
     * @param  array<string, Metric>  $readings
     * @return array<int, array<string, mixed>>
     */
    private function applicationGroups(array $readings): array
    {
        $groups = [];

        foreach (self::APPLICATION_GROUPS as $key => $definition) {
            $metrics = $this->ordered(
                $readings,
                $definition['metrics'],
                self::APPLICATION_MEGABYTES,
                self::BYTES_PER_MB,
                'MB',
            );

            // A reading that is OK but not scalar has no honest one-line rendering, and handing an
            // array to the metric partial prints a PHP warning where a value should be. It is said
            // rather than dropped: a measurement this page silently removes is indistinguishable
            // from one nobody ever took, which is the confusion the whole section exists to avoid.
            $metrics = array_map(
                static fn (Metric $metric) => $metric->isOk() && !is_scalar($metric->value)
                    ? Metric::noData($metric->source, 'This reading is not a single value, so this page cannot draw it as one.')
                    : $metric,
                $metrics,
            );

            if ($metrics === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'why' => $definition['why'],
                'metrics' => $metrics,
                // A directory that cannot be written is the whole finding, so the card is marked
                // rather than leaving one `no` among twelve rows to be spotted by eye.
                'failing' => $this->failingWrites($metrics),
            ];
        }

        return $groups;
    }

    /**
     * Directories whose write probe came back false.
     *
     * @param  array<string, Metric>  $metrics
     * @return array<int, string>
     */
    private function failingWrites(array $metrics): array
    {
        $failing = [];

        foreach ($metrics as $name => $metric) {
            if (str_ends_with($name, '_writable') && $metric->isOk() && $metric->value === false) {
                $failing[] = $name;
            }
        }

        return $failing;
    }

    /**
     * @param  array<string, Metric>  $readings
     * @param  array<string, mixed>  $stored
     * @return array<string, array<string, mixed>>
     */
    private function applicationCharts(array $readings, array $stored, string $resolution): array
    {
        $charts = [];

        foreach (self::APPLICATION_CHARTS as $key => $definition) {
            // These four are published without a label, so the stored rows carry an empty one.
            $charts[$key] = $this->chart($key, $definition, '', $readings[$definition['source']] ?? null, $stored, $resolution);
        }

        return $charts;
    }

    // -------------------------------------------------------------------------------------------
    // Gaps worth naming

    /**
     * Collectors that could not answer at all, so it can be said once at the top.
     *
     * Normally empty. A collector that is missing or that threw produces two dozen identical
     * unavailable rows underneath, and two dozen copies of one fault reads as two dozen faults.
     *
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array<string, mixed>>
     */
    private function collectorFaults(array $readings): array
    {
        $faults = [];

        foreach (self::COLLECTORS as $key) {
            $collected = $readings[$key] ?? [];

            if ($collected === []) {
                $faults[] = [
                    'collector' => $key,
                    'state' => 'not_supported',
                    'note' => 'The ' . $key . ' collector is not installed in this build, so nothing on this page can be read from it.',
                ];

                continue;
            }

            $failure = $collected['__collector'] ?? null;
            if ($failure instanceof Metric) {
                $faults[] = ['collector' => $key, 'state' => 'failed', 'note' => $failure->note];
            }
        }

        return $faults;
    }

    /**
     * Why the block-device section is empty, when it is.
     *
     * The disk collector publishes a single unlabelled `device_io` reading in place of the per
     * device set whenever it cannot derive rates — no /proc/diskstats, a first sample with nothing
     * to subtract from, or counters reset by a reboot. Those are four different situations and none
     * of them is "this machine did no I/O".
     *
     * @param  array<string, Metric>  $disk
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<string, mixed>|null
     */
    private function deviceIoGap(array $disk, array $devices): ?array
    {
        // Devices on the page answer the question themselves. Drawing a gap as well — which the
        // collector can do, since `device_io` and a stale per-device set can both survive one
        // collect() — put "per-device I/O could not be derived" directly above six live cards.
        if ($devices !== []) {
            return null;
        }

        $reading = $disk['device_io'] ?? null;
        if ($reading instanceof Metric) {
            return [
                'state' => $reading->isOk() ? 'no_data' : $reading->state,
                'note' => $reading->note ?? 'Per-device I/O could not be derived on this host.',
                'remedy' => $reading->remedy,
                'source' => $reading->source,
            ];
        }

        // No devices, no `device_io`, and the reason is one the top of the page already carries:
        // the collector is missing or it threw. Saying "no reason for its absence" underneath it
        // was a second, contradictory explanation of the same fault — and a false one.
        $failure = $disk['__collector'] ?? null;
        if ($failure instanceof Metric) {
            return [
                'state' => 'failed',
                'note' => 'The disk collector threw before it reached /proc/diskstats, so nothing about the block devices was measured. ' . ($failure->note ?? ''),
                'remedy' => null,
                'source' => $failure->source,
            ];
        }

        if ($disk === []) {
            return [
                'state' => 'not_supported',
                'note' => 'The disk collector is not installed in this build, so per-device I/O is not measured on this deployment at all.',
                'remedy' => null,
                'source' => null,
            ];
        }

        return [
            'state' => 'no_data',
            'note' => 'The disk collector answered, but published neither per-device readings nor a reason for their absence.',
            'remedy' => null,
            'source' => null,
        ];
    }

    /**
     * Labels with stored history that no live reading matches.
     *
     * A device that has been removed, renamed, or dropped by a newer collector build keeps its
     * points in monitoring_series forever. Naming it is the difference between "this disk is gone"
     * and a chart quietly missing from a page.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<int, array<string, mixed>>  $mounts
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<int, string>
     */
    private function historyOnlyLabels(array $stored, array $mounts, array $devices): array
    {
        $live = array_merge(array_column($mounts, 'key'), array_column($devices, 'key'));

        $historic = [];
        foreach (array_merge(array_column(self::MOUNT_CHARTS, 'metric'), array_column(self::DEVICE_CHARTS, 'metric')) as $metric) {
            foreach (array_keys($stored['points'][$metric] ?? []) as $label) {
                if ($label !== '' && !in_array($label, $live, true)) {
                    $historic[$label] = true;
                }
            }
        }

        return array_keys($historic);
    }

    /**
     * Readings this page does not draw anywhere.
     *
     * Normally empty. It exists so that a collector which grows a reading cannot have it silently
     * disappear from the page: an undrawn measurement is indistinguishable from an unmeasured one,
     * and that is the confusion this whole system is built to avoid.
     *
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array{collector: string, metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = array_merge(self::RENDERED_ELSEWHERE, [
            'disk' => array_merge(self::RENDERED_ELSEWHERE['disk'], self::MOUNT_METRICS, self::DEVICE_METRICS, ['device_io']),
        ]);
        foreach (self::APPLICATION_GROUPS as $definition) {
            $claimed['storage'] = array_merge($claimed['storage'], $definition['metrics']);
        }

        $unrendered = [];
        foreach ($readings as $collector => $collected) {
            foreach ($collected as $key => $metric) {
                // __collector is the registry's own failure marker and is reported at the top of
                // the page rather than as a reading the collector produced.
                if ($key === '__collector' || !$metric instanceof Metric) {
                    continue;
                }

                $name = explode('@', $key, 2)[0];
                if (in_array($name, $claimed[$collector] ?? [], true)) {
                    continue;
                }

                $unrendered[] = ['collector' => $collector, 'metric' => $key, 'state' => $metric->state];
            }
        }

        return $unrendered;
    }
}
