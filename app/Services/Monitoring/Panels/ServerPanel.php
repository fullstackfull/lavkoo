<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Environment;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * The machine underneath: where the processor spent its last interval, how much memory is really
 * available, and whether anything is stalling on either.
 *
 * The section is shaped around the two mistakes that make server dashboards lie.
 *
 * The first is reading load average as utilisation. Load counts runnable and uninterruptible
 * tasks, so 4.0 is a saturated four-core box and an idle thirty-two-core one; the number carries
 * no meaning at all without the core count. Load therefore gets a card of its own, never sits in
 * the same block as the utilisation percentages, and is always drawn beside the per-core figure
 * that makes it comparable.
 *
 * The second is treating an absent reading as a calm one. Pressure Stall Information is a kernel
 * build option and this kernel does not carry it, so the pressure card renders "not supported" and
 * the boot flag that would fix it. The one measurement that separates a machine using its RAM from
 * a machine drowning in it is genuinely missing here, and saying so is the honest output — a 0%
 * drawn in its place would describe a perfectly healthy server.
 *
 * Nothing on this page is derived from anything else on it. Every figure arrives from a collector
 * already carrying its state, its provenance and, where an operator could change it, the remedy.
 */
class ServerPanel implements Panel
{
    /** The collectors this section is made of. Both are read exactly once per request. */
    private const COLLECTORS = ['cpu', 'memory'];

    /**
     * Readings grouped the way somebody diagnoses a server, not the order /proc prints them in.
     *
     * Each entry is `label => [collector, metric]`. The label is what the page shows and is unique
     * across the payload, which is what lets CPU pressure and memory pressure — identically named
     * in their two collectors — sit on the same card without one hiding the other.
     *
     * @var array<string, array{why: string, metrics: array<string, array{0: string, 1: string}>}>
     */
    private const GROUPS = [
        'processor' => [
            'why' => 'where_the_processor_spent_the_interval_and_what_the_processor_actually_is',
            'metrics' => [
                'usage_pct' => ['cpu', 'usage_pct'],
                'user_pct' => ['cpu', 'user_pct'],
                'system_pct' => ['cpu', 'system_pct'],
                'idle_pct' => ['cpu', 'idle_pct'],
                'iowait_pct' => ['cpu', 'iowait_pct'],
                'steal_pct' => ['cpu', 'steal_pct'],
                'irq_pct' => ['cpu', 'irq_pct'],
                'softirq_pct' => ['cpu', 'softirq_pct'],
                'cores' => ['cpu', 'cores'],
                'model' => ['cpu', 'model'],
                'frequency_mhz' => ['cpu', 'frequency_mhz'],
                'thermal_throttled' => ['cpu', 'thermal_throttled'],
            ],
        ],
        'load' => [
            'why' => 'how_many_tasks_are_waiting_for_the_processor_which_is_a_queue_length_and_not_a_percentage',
            'metrics' => [
                'load_1m' => ['cpu', 'load_1m'],
                'load_5m' => ['cpu', 'load_5m'],
                'load_15m' => ['cpu', 'load_15m'],
                'load_per_core' => ['cpu', 'load_per_core'],
                'runnable_processes' => ['cpu', 'runnable_processes'],
                'procs_blocked' => ['cpu', 'procs_blocked'],
            ],
        ],
        'memory' => [
            'why' => 'what_would_not_be_handed_back_to_the_next_process_that_asks_which_is_a_different_figure_from_memtotal_minus_memfree',
            'metrics' => [
                'used_pct' => ['memory', 'used_pct'],
                'used' => ['memory', 'used'],
                'available' => ['memory', 'available'],
                'total' => ['memory', 'total'],
                'host_total' => ['memory', 'host_total'],
                'free' => ['memory', 'free'],
                'cached' => ['memory', 'cached'],
                'buffers' => ['memory', 'buffers'],
                'shared' => ['memory', 'shared'],
                'slab' => ['memory', 'slab'],
                'slab_unreclaimable' => ['memory', 'slab_unreclaimable'],
            ],
        ],
        'swap' => [
            'why' => 'whether_this_host_has_anywhere_to_swap_to_and_whether_it_is_currently_doing_so',
            'metrics' => [
                'swap_used_pct' => ['memory', 'swap_used_pct'],
                'swap_used' => ['memory', 'swap_used'],
                'swap_total' => ['memory', 'swap_total'],
                'swap_in_per_sec' => ['memory', 'swap_in_per_sec'],
                'swap_out_per_sec' => ['memory', 'swap_out_per_sec'],
            ],
        ],
        'paging' => [
            'why' => 'minor_faults_are_bookkeeping_major_faults_went_to_the_disk_and_are_the_ones_a_request_pays_for',
            'metrics' => [
                'major_page_faults_per_sec' => ['memory', 'major_page_faults_per_sec'],
                'major_page_faults' => ['memory', 'major_page_faults'],
                'page_faults_per_sec' => ['memory', 'page_faults_per_sec'],
                'page_faults' => ['memory', 'page_faults'],
                'oom_kills' => ['memory', 'oom_kills'],
            ],
        ],
        'pressure' => [
            'why' => 'how_long_tasks_spent_stalled_waiting_for_a_resource_which_is_what_tells_a_busy_machine_from_an_overwhelmed_one',
            'metrics' => [
                'cpu_pressure_some_avg10' => ['cpu', 'pressure_some_avg10'],
                'cpu_pressure_some_avg60' => ['cpu', 'pressure_some_avg60'],
                'cpu_pressure_full_avg10' => ['cpu', 'pressure_full_avg10'],
                'memory_pressure_some_avg10' => ['memory', 'pressure_some_avg10'],
                'memory_pressure_some_avg60' => ['memory', 'pressure_some_avg60'],
                'memory_pressure_full_avg10' => ['memory', 'pressure_full_avg10'],
            ],
        ],
        'kernel_activity' => [
            'why' => 'totals_since_boot_never_rates_so_they_are_only_useful_read_against_an_earlier_look_at_the_same_page',
            'metrics' => [
                'context_switches' => ['cpu', 'context_switches'],
                'interrupts' => ['cpu', 'interrupts'],
                'processes_forked' => ['cpu', 'processes_forked'],
            ],
        ],
    ];

    /**
     * Every gauge the two collectors publish, with the live reading each one is written from.
     *
     * The `source` pair is what lets an empty chart say WHY it is empty: the sampler only stores a
     * reading that is OK, so a gauge with no line on a host that cannot read /proc/stat is a
     * missing kernel file rather than a missing sampler.
     *
     * @var array<string, array{metric: string, unit: string, title: string, source: array{0: string, 1: string}}>
     */
    private const CHARTS = [
        'cpu_usage_pct' => [
            'metric' => 'server.cpu.usage_pct',
            'unit' => '%',
            'title' => 'processor_usage_over_time',
            'source' => ['cpu', 'usage_pct'],
        ],
        'cpu_load_1m' => [
            'metric' => 'server.cpu.load_1m',
            'unit' => 'tasks',
            'title' => 'load_average_over_time',
            'source' => ['cpu', 'load_1m'],
        ],
        'cpu_iowait_pct' => [
            'metric' => 'server.cpu.iowait_pct',
            'unit' => '%',
            'title' => 'io_wait_over_time',
            'source' => ['cpu', 'iowait_pct'],
        ],
        'cpu_steal_pct' => [
            'metric' => 'server.cpu.steal_pct',
            'unit' => '%',
            'title' => 'steal_time_over_time',
            'source' => ['cpu', 'steal_pct'],
        ],
        'memory_used_pct' => [
            'metric' => 'server.memory.used_pct',
            'unit' => '%',
            'title' => 'memory_used_over_time',
            'source' => ['memory', 'used_pct'],
        ],
        'memory_available_mb' => [
            'metric' => 'server.memory.available_mb',
            'unit' => 'MB',
            'title' => 'memory_available_over_time',
            'source' => ['memory', 'available'],
        ],
        'cpu_load_per_core' => [
            'metric' => 'server.cpu.load_per_core',
            'unit' => 'x cores',
            'title' => 'load_per_core_over_time',
            'source' => ['cpu', 'load_per_core'],
        ],
        'cpu_pressure_some_avg10' => [
            'metric' => 'server.cpu.pressure_some_avg10',
            'unit' => '%',
            'title' => 'processor_pressure_over_time',
            'source' => ['cpu', 'pressure_some_avg10'],
        ],
        'memory_swap_used_pct' => [
            'metric' => 'server.memory.swap_used_pct',
            'unit' => '%',
            'title' => 'swap_used_over_time',
            'source' => ['memory', 'swap_used_pct'],
        ],
        'memory_pressure_some_avg10' => [
            'metric' => 'server.memory.pressure_some_avg10',
            'unit' => '%',
            'title' => 'memory_pressure_over_time',
            'source' => ['memory', 'pressure_some_avg10'],
        ],
    ];

    /** Readings that cannot be drawn as one value and are rendered as their own block instead. */
    private const RENDERED_ELSEWHERE = ['cpu' => ['per_core'], 'memory' => []];

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
        private readonly Environment $environment,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);

        // Collected once each, and reused everywhere below. Both collectors report a delta against
        // a cached previous sample, so a second call inside the same request would find its own
        // first call's reading to subtract from and report a machine that did nothing at all.
        $readings = [
            'cpu' => $this->collectors->collect('cpu'),
            'memory' => $this->collectors->collect('memory'),
        ];

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
            ],
            'host' => $this->host(),
            'collectors' => $this->collectorFaults($readings),
            'groups' => $this->groups($readings),
            'cores' => $this->cores($readings['cpu']),
            'charts' => $this->charts($range, $window['resolution'], $readings),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // The host

    /**
     * Which machine every number on this page describes.
     *
     * Whether it is containerised is part of that: inside a container /proc/meminfo describes the
     * hypervisor rather than the cgroup the OOM killer actually enforces, and the memory card is
     * measured against the limit instead — a substitution worth stating rather than hiding.
     *
     * @return array<string, mixed>
     */
    private function host(): array
    {
        return [
            'description' => $this->environment->hostDescription(),
            'containerised' => $this->environment->has('containerised'),
        ];
    }

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

    // -------------------------------------------------------------------------------------------
    // The cards

    /**
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<int, array<string, mixed>>
     */
    private function groups(array $readings): array
    {
        $groups = [];

        foreach (self::GROUPS as $key => $definition) {
            $metrics = [];

            foreach ($definition['metrics'] as $label => [$collector, $name]) {
                $metric = $readings[$collector][$name] ?? null;
                if (!$metric instanceof Metric) {
                    continue;
                }

                // An unavailable reading goes in whole: its state and its remedy are the content.
                // A reading that is OK but not scalar has no honest one-line rendering, and handing
                // an array to the metric partial prints a PHP warning where a value should be.
                if ($metric->isOk() && !is_scalar($metric->value)) {
                    continue;
                }

                $metrics[$label] = $metric;
            }

            if ($metrics === []) {
                continue;
            }

            $groups[] = ['key' => $key, 'why' => $definition['why'], 'metrics' => $metrics];
        }

        return $groups;
    }

    /**
     * Per-core utilisation, which is the only place one pinned core is visible.
     *
     * An average across four cores where one sits at 100% and three idle reads as 25% — a
     * comfortable number describing a queue of requests stuck behind a single saturated thread.
     * The busiest core is carried beside the mean for exactly that comparison, and both are the
     * arithmetic of readings that are on the page rather than figures from anywhere else.
     *
     * @param  array<string, Metric>  $cpu
     * @return array<string, mixed>
     */
    private function cores(array $cpu): array
    {
        $coreCount = $cpu['cores'] ?? null;
        $base = [
            'count' => $coreCount instanceof Metric && $coreCount->isOk() ? (int) $coreCount->value : null,
            'warning_pct' => (float) config('monitoring.thresholds.cpu_warning', 75),
            'critical_pct' => (float) config('monitoring.thresholds.cpu_critical', 90),
            'rows' => [],
            'busiest_pct' => null,
            'average_pct' => null,
        ];

        $metric = $cpu['per_core'] ?? null;

        if (!$metric instanceof Metric) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'The CPU collector returned no per-core reading.',
                'remedy' => null,
                'source' => null,
            ]);
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return array_merge($base, [
                'state' => $metric->isOk() ? 'no_data' : $metric->state,
                'note' => $metric->note ?? 'No per-core reading is available on this host.',
                'remedy' => $metric->remedy,
                'source' => $metric->source,
            ]);
        }

        $rows = [];
        foreach ($metric->value as $entry) {
            $entry = (array) $entry;
            // Skipped rather than defaulted: a core whose reading did not arrive is not a core at
            // 0%, and the bar this draws would be indistinguishable from a genuinely idle one.
            if (!isset($entry['core'], $entry['usage_pct'])) {
                continue;
            }

            $rows[] = ['core' => (int) $entry['core'], 'usage_pct' => (float) $entry['usage_pct']];
        }

        if ($rows === []) {
            return array_merge($base, [
                'state' => 'no_data',
                'note' => 'The per-core reading arrived without a usable core in it.',
                'remedy' => null,
                'source' => $metric->source,
            ]);
        }

        usort($rows, static fn (array $first, array $second) => $first['core'] <=> $second['core']);
        $usage = array_column($rows, 'usage_pct');

        return array_merge($base, [
            'state' => 'ok',
            'note' => $metric->note,
            'remedy' => null,
            'source' => $metric->source,
            'rows' => $rows,
            'busiest_pct' => round(max($usage), 1),
            'average_pct' => round(array_sum($usage) / count($usage), 1),
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Stored gauges

    /**
     * Every server.* gauge in the window, each carrying why it has no line when it has none.
     *
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<string, array<string, mixed>>
     */
    private function charts(string $range, string $resolution, array $readings): array
    {
        $charts = [];

        foreach (self::CHARTS as $key => $definition) {
            $charts[$key] = $this->chart($key, $definition, $range, $resolution, $readings);
        }

        return $charts;
    }

    /**
     * @param  array{metric: string, unit: string, title: string, source: array{0: string, 1: string}}  $definition
     * @param  array<string, array<string, Metric>>  $readings
     * @return array<string, mixed>
     */
    private function chart(string $key, array $definition, string $range, string $resolution, array $readings): array
    {
        [$collector, $name] = $definition['source'];
        $live = $readings[$collector][$name] ?? null;

        try {
            $series = $this->reader->series($definition['metric'], $range);
        } catch (\Throwable $exception) {
            // PanelRegistry would catch this too, but it can only blank the whole section. Failing
            // one gauge by name leaves the cards and the per-core bars readable.
            return array_merge($definition, [
                'key' => $key,
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'latest' => null,
                // Null, not zero: a read that failed did not find nothing, it did not look.
                'stored_points' => null,
                'points' => [],
            ]);
        }

        $points = array_values(array_filter(
            $series['points'],
            static fn (array $point) => ($point['v'] ?? null) !== null,
        ));

        $chart = array_merge($definition, [
            'key' => $key,
            'latest' => $series['latest'],
            // Points, not samples. One stored row is a bucket, and at hour or day resolution a
            // bucket is a rollup of sixty or of fourteen hundred samples — so calling this a
            // sample count would understate a week's collection by two orders of magnitude.
            'stored_points' => count($points),
            'points' => $points,
        ]);

        // One point is a reading; a line needs two. Saying which of those it is stops a single
        // sample being read as a flat trend.
        if (count($points) < 2) {
            return array_merge($chart, $this->gaugeGap($resolution, count($points), $live));
        }

        return array_merge($chart, ['state' => 'ok', 'note' => null, 'remedy' => null]);
    }

    /**
     * Why a gauge has no line.
     *
     * Four different silences with four different answers, and the flat empty chart they all draw
     * looks identical.
     *
     * @return array{state: string, note: string, remedy: string|null}
     */
    private function gaugeGap(string $resolution, int $points, ?Metric $live): array
    {
        if (!config('monitoring.enabled', true)) {
            return [
                'state' => 'not_configured',
                'note' => 'Monitoring collection is switched off, so no gauge has been sampled since it was disabled.',
                'remedy' => 'Set MONITORING_ENABLED=true in .env, then run `php artisan optimize:clear`.',
            ];
        }

        // The sampler only stores a reading that is OK, so a metric this host cannot produce has
        // never been written. The gap is the host, not the scheduler, and the reading says which.
        //
        // Only a reading that is structurally unavailable earns that answer. NO_DATA means the
        // opposite — the probe works here and has simply not recorded yet — and the CPU and memory
        // collectors return it for the first sample of every process, because utilisation is a
        // delta against a cached previous reading. Blaming "not on this host" for that printed
        // "this host cannot measure processor usage" over a gauge with a fortnight of samples in
        // the table underneath it.
        $unavailable = [Metric::NOT_SUPPORTED, Metric::NOT_CONFIGURED, Metric::PERMISSION_DENIED, Metric::COLLECTOR_OFFLINE, Metric::FAILED];
        if ($live instanceof Metric && in_array($live->state, $unavailable, true)) {
            return [
                'state' => $live->state,
                'note' => 'This gauge is only stored while the reading behind it is available, and it is not on this host. '
                    . ($live->note ?? 'The collector returned no value for it.'),
                'remedy' => $live->remedy,
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
        $claimed = self::RENDERED_ELSEWHERE;
        foreach (self::GROUPS as $definition) {
            foreach ($definition['metrics'] as [$collector, $name]) {
                $claimed[$collector][] = $name;
            }
        }

        $unrendered = [];
        foreach ($readings as $collector => $collected) {
            foreach ($collected as $name => $metric) {
                // __collector is the registry's own failure marker and is reported at the top of
                // the page rather than as a reading the collector produced.
                if ($name === '__collector' || !$metric instanceof Metric) {
                    continue;
                }
                if (in_array($name, $claimed[$collector] ?? [], true)) {
                    continue;
                }

                $unrendered[] = ['collector' => $collector, 'metric' => $name, 'state' => $metric->state];
            }
        }

        return $unrendered;
    }
}
