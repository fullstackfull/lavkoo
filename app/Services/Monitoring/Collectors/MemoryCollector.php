<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use Illuminate\Support\Facades\Cache;

/**
 * Memory, from the kernel's own counters.
 *
 * Three things this deliberately does NOT do, because each one is a standard way memory dashboards
 * lie about a machine:
 *
 * 1. It does not call MemTotal - MemFree "used". Linux lends every idle page to the page cache, so
 *    by that arithmetic a perfectly healthy server sits at 95% forever and nobody believes the
 *    number any more. Used here is MemTotal - MemAvailable: memory that would NOT be handed back
 *    to the next process that asks for it.
 *
 * 2. It does not trust MemTotal inside a container. /proc/meminfo describes the host, not the
 *    cgroup, so a container a hundred megabytes from the OOM killer reads as 12% of the
 *    hypervisor's RAM. Where a cgroup limit is set and is lower than the host's memory, that limit
 *    is the total, and the host figure is reported beside it so the difference is stated rather
 *    than silently swallowed.
 *
 * 3. It does not divide since-boot counters by uptime. pgfault, pswpin and pswpout only become
 *    rates as the difference between two readings; the first sample says so instead of publishing
 *    the machine's lifetime average as if it described the last minute.
 */
class MemoryCollector implements Collector
{
    private const PREVIOUS_KEY = 'monitoring:memory:previous';

    private const MEMINFO_SOURCE = 'Linux /proc/meminfo';
    private const VMSTAT_SOURCE = 'Linux /proc/vmstat';
    private const PSI_SOURCE = 'Linux /proc/pressure/memory';

    private const MEGABYTE = 1048576;

    /**
     * Two samples closer together than this do not describe a rate. The delta is a handful of
     * counter ticks and the tiny denominator turns them into thousands per second, which lands on
     * the dashboard looking like a paging storm.
     */
    private const MINIMUM_SAMPLE_SECONDS = 0.5;

    /**
     * Past this the previous reading no longer describes the present. A burst nine minutes ago,
     * averaged over nine minutes, lands on the chart as a calm machine — a number ending in "/s"
     * is read as "now", so beyond a few missed collection rounds the rate is withdrawn rather
     * than quietly smoothed.
     */
    private const MAXIMUM_SAMPLE_SECONDS = 300;

    /** Everything /proc/meminfo feeds, so one unreadable file marks exactly these unavailable. */
    private const MEMINFO_METRICS = [
        'total', 'host_total', 'used', 'available', 'free', 'cached', 'buffers', 'shared',
        'slab', 'slab_unreclaimable', 'used_pct', 'swap_total', 'swap_used', 'swap_used_pct',
    ];

    /** Everything /proc/vmstat feeds. */
    private const VMSTAT_METRICS = [
        'page_faults', 'page_faults_per_sec', 'major_page_faults', 'major_page_faults_per_sec',
        'swap_in_per_sec', 'swap_out_per_sec', 'oom_kills',
    ];

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'memory';
    }

    public function collect(): array
    {
        $memory = $this->memory();
        $paging = $this->paging();

        return [
            'total' => $memory['total'],
            'host_total' => $memory['host_total'],
            'used' => $memory['used'],
            'available' => $memory['available'],
            'free' => $memory['free'],
            'cached' => $memory['cached'],
            'buffers' => $memory['buffers'],
            'shared' => $memory['shared'],
            'slab' => $memory['slab'],
            // The unreclaimable half of slab is the half that never comes back under pressure, which
            // is where a kernel-side leak becomes visible long before user space feels it.
            'slab_unreclaimable' => $memory['slab_unreclaimable'],
            'used_pct' => $memory['used_pct'],
            'swap_total' => $memory['swap_total'],
            'swap_used' => $memory['swap_used'],
            'swap_used_pct' => $memory['swap_used_pct'],
            'page_faults' => $paging['page_faults'],
            'page_faults_per_sec' => $paging['page_faults_per_sec'],
            // Minor faults are bookkeeping and happen in their millions; major faults went to the
            // disk for the page, so they are the ones that cost a request its latency.
            'major_page_faults' => $paging['major_page_faults'],
            'major_page_faults_per_sec' => $paging['major_page_faults_per_sec'],
            'swap_in_per_sec' => $paging['swap_in_per_sec'],
            'swap_out_per_sec' => $paging['swap_out_per_sec'],
            'oom_kills' => $paging['oom_kills'],
            'pressure_some_avg10' => $this->pressure('some', 'avg10'),
            'pressure_some_avg60' => $this->pressure('some', 'avg60'),
            'pressure_full_avg10' => $this->pressure('full', 'avg10'),
        ];
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'server.memory.used_pct' => $collected['used_pct'],
            'server.memory.available_mb' => $collected['available'],
            'server.memory.swap_used_pct' => $collected['swap_used_pct'],
            'server.memory.pressure_some_avg10' => $collected['pressure_some_avg10'],
        ], fn (Metric $metric) => $metric->isOk());
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Everything /proc/meminfo answers, measured against the ceiling that actually applies here.
     *
     * @return array<string, Metric>
     */
    private function memory(): array
    {
        try {
            $meminfo = $this->meminfo();
            if ($meminfo instanceof Metric) {
                return array_fill_keys(self::MEMINFO_METRICS, $meminfo);
            }

            $hostTotal = $meminfo['MemTotal'] ?? 0;
            if ($hostTotal <= 0) {
                return array_fill_keys(
                    self::MEMINFO_METRICS,
                    Metric::noData(self::MEMINFO_SOURCE, 'MemTotal is missing from /proc/meminfo.'),
                );
            }

            $ceiling = $this->ceiling($hostTotal);
            $total = $ceiling['total'] ?? $hostTotal;
            $source = $ceiling['source'] ?? self::MEMINFO_SOURCE;
            $note = $ceiling === null
                ? null
                : sprintf('Measured against the container limit; the host itself reports %s of RAM.', $this->human($hostTotal));

            if ($ceiling !== null) {
                $used = $ceiling['used'];
                $unavailable = Metric::noData($source, 'This cgroup publishes a limit but not its current usage.');
            } elseif (isset($meminfo['MemAvailable'])) {
                $used = $hostTotal - $meminfo['MemAvailable'];
                $unavailable = Metric::noData(self::MEMINFO_SOURCE);
            } else {
                $used = null;
                $unavailable = Metric::notSupported(
                    self::MEMINFO_SOURCE,
                    'This kernel predates MemAvailable (Linux 3.14), and MemTotal - MemFree would count the page cache as used, so no usage figure is reported.',
                );
            }

            $field = fn (string $name) => isset($meminfo[$name])
                ? Metric::of(round($meminfo[$name] / self::MEGABYTE, 1), self::MEMINFO_SOURCE, 'MB')
                : Metric::noData(self::MEMINFO_SOURCE, "This kernel does not report {$name} in /proc/meminfo.");

            $megabytes = fn (int $bytes) => round($bytes / self::MEGABYTE, 1);

            $swapTotal = $meminfo['SwapTotal'] ?? null;
            $swapUsed = $swapTotal === null || !isset($meminfo['SwapFree'])
                ? null
                : max(0, $swapTotal - $meminfo['SwapFree']);
            $swapNote = $swapTotal === 0 ? 'No swap device is active on this host.' : null;

            // Reclaimable slab is cache like any other, and free(1) counts it as such; leaving it
            // out of "cached" makes it reappear as unexplained missing memory.
            $cached = isset($meminfo['Cached']) ? $meminfo['Cached'] + ($meminfo['SReclaimable'] ?? 0) : null;

            return [
                'total' => Metric::of($megabytes($total), $source, 'MB', $note),
                'host_total' => Metric::of($megabytes($hostTotal), self::MEMINFO_SOURCE, 'MB'),
                'used' => $used === null ? $unavailable : Metric::of($megabytes($used), $source, 'MB', $note),
                'available' => $used === null ? $unavailable : Metric::of($megabytes(max(0, $total - $used)), $source, 'MB', $note),
                'free' => $field('MemFree'),
                'cached' => $cached === null
                    ? Metric::noData(self::MEMINFO_SOURCE, 'This kernel does not report Cached in /proc/meminfo.')
                    : Metric::of($megabytes($cached), self::MEMINFO_SOURCE, 'MB', 'Cached plus reclaimable slab, the figure free(1) shows as cache.'),
                'buffers' => $field('Buffers'),
                'shared' => $field('Shmem'),
                'slab' => $field('Slab'),
                'slab_unreclaimable' => $field('SUnreclaim'),
                'used_pct' => $used === null ? $unavailable : Metric::of(round(100 * $used / $total, 1), $source, '%', $note),
                'swap_total' => $swapTotal === null
                    ? Metric::noData(self::MEMINFO_SOURCE, 'This kernel does not report SwapTotal in /proc/meminfo.')
                    : Metric::of($megabytes($swapTotal), self::MEMINFO_SOURCE, 'MB', $swapNote),
                'swap_used' => $swapUsed === null
                    ? Metric::noData(self::MEMINFO_SOURCE, 'This kernel does not report SwapFree in /proc/meminfo.')
                    : Metric::of($megabytes($swapUsed), self::MEMINFO_SOURCE, 'MB', $swapNote),
                'swap_used_pct' => $this->swapUsedPct($swapTotal, $swapUsed),
            ];
        } catch (\Throwable $exception) {
            return array_fill_keys(self::MEMINFO_METRICS, Metric::failed(self::MEMINFO_SOURCE, $exception));
        }
    }

    /**
     * /proc/meminfo as bytes, keyed by its own labels.
     *
     * Read once per collect() so every derived figure describes the same instant: total and free
     * sampled a few milliseconds apart do not have to add up.
     *
     * @return array<string, int>|Metric
     */
    private function meminfo(): array|Metric
    {
        if (!$this->environment->has('proc_meminfo')) {
            return Metric::notSupported(
                self::MEMINFO_SOURCE,
                'This host does not expose /proc/meminfo, so memory cannot be measured.',
            );
        }

        $contents = @file_get_contents('/proc/meminfo');
        if ($contents === false) {
            return Metric::permissionDenied(
                self::MEMINFO_SOURCE,
                'The web user cannot read /proc/meminfo.',
                'Remove the open_basedir restriction covering /proc, or set ProtectProc=default and ProcSubset=all in the php-fpm systemd unit.',
            );
        }

        $parsed = [];
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^(\S+):\s+(\d+)(\s+kB)?$/', trim($line), $match) === 1) {
                $parsed[$match[1]] = (int) $match[2] * (isset($match[3]) ? 1024 : 1);
            }
        }

        return $parsed === [] ? Metric::noData(self::MEMINFO_SOURCE) : $parsed;
    }

    /**
     * The ceiling the OOM killer actually enforces on this process.
     *
     * A limit at or above the host's RAM is not a limit: cgroup v1 writes a value a whisker below
     * PHP_INT_MAX to mean "unlimited" and v2 writes the literal string "max", and reporting either
     * as the total would put an eight-exabyte machine on the dashboard.
     *
     * @return array{total: int, used: int|null, source: string}|null
     */
    private function ceiling(int $hostTotal): ?array
    {
        foreach ($this->cgroups() as $cgroup) {
            $limit = $this->readInt($cgroup['limit']);
            if ($limit === null || $limit <= 0 || $limit >= $hostTotal) {
                continue;
            }

            return [
                'total' => $limit,
                'used' => $this->cgroupUsage($cgroup),
                'source' => $cgroup['source'],
            ];
        }

        return null;
    }

    /**
     * The cgroup files worth asking, newest hierarchy first.
     *
     * @return list<array{limit: string, usage: string, stat: string, cache: string, source: string}>
     */
    private function cgroups(): array
    {
        $cgroups = [];

        if ($this->environment->has('cgroup_v2')) {
            $cgroups[] = [
                'limit' => '/sys/fs/cgroup/memory.max',
                'usage' => '/sys/fs/cgroup/memory.current',
                'stat' => '/sys/fs/cgroup/memory.stat',
                'cache' => 'inactive_file',
                'source' => 'Linux cgroup v2 memory.max',
            ];
        }

        if ($this->environment->has('cgroup_v1')) {
            $cgroups[] = [
                'limit' => '/sys/fs/cgroup/memory/memory.limit_in_bytes',
                'usage' => '/sys/fs/cgroup/memory/memory.usage_in_bytes',
                'stat' => '/sys/fs/cgroup/memory/memory.stat',
                'cache' => 'total_inactive_file',
                'source' => 'Linux cgroup v1 memory.limit_in_bytes',
            ];
        }

        return $cgroups;
    }

    /**
     * What the cgroup is holding, less the page cache it would drop rather than die for.
     *
     * @param array{usage: string, stat: string, cache: string} $cgroup
     */
    private function cgroupUsage(array $cgroup): ?int
    {
        $usage = $this->readInt($cgroup['usage']);
        if ($usage === null) {
            return null;
        }

        // Inactive file pages are charged to the cgroup but reclaimed under pressure instead of
        // triggering a kill, so the working set the limit is really being spent on is the charge
        // minus those pages — the same subtraction the kernel makes before it decides to OOM.
        $stat = (string) @file_get_contents($cgroup['stat']);
        if (preg_match('/^' . preg_quote($cgroup['cache'], '/') . '\s+(\d+)$/m', $stat, $match) === 1) {
            return max(0, $usage - (int) $match[1]);
        }

        return $usage;
    }

    /**
     * Swap utilisation, or the reason the question has no answer.
     *
     * With no swap device there is no percentage: 0% would report swap as healthy and 100% would
     * report it as exhausted, when the truth is that this host has nowhere to swap to at all.
     */
    private function swapUsedPct(?int $total, ?int $used): Metric
    {
        if ($total === null || $used === null) {
            return Metric::noData(self::MEMINFO_SOURCE, 'This kernel does not report SwapTotal and SwapFree.');
        }

        if ($total === 0) {
            return Metric::notConfigured(
                self::MEMINFO_SOURCE,
                'Add swap: sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile, then append "/swapfile none swap sw 0 0" to /etc/fstab.',
                'No swap device is active, so there is no swap utilisation to measure.',
            );
        }

        return Metric::of(round(100 * $used / $total, 1), self::MEMINFO_SOURCE, '%');
    }

    /**
     * Paging: totals since boot, and the rates that only exist as a delta between two readings.
     *
     * @return array<string, Metric>
     */
    private function paging(): array
    {
        try {
            if (!$this->environment->has('proc_vmstat')) {
                return array_fill_keys(self::VMSTAT_METRICS, Metric::notSupported(
                    self::VMSTAT_SOURCE,
                    'This host does not expose /proc/vmstat, so paging activity cannot be measured.',
                ));
            }

            $counters = $this->vmstat();
            if ($counters === []) {
                return array_fill_keys(self::VMSTAT_METRICS, Metric::permissionDenied(
                    self::VMSTAT_SOURCE,
                    'The web user cannot read /proc/vmstat.',
                    'Remove the open_basedir restriction covering /proc, or set ProtectProc=default and ProcSubset=all in the php-fpm systemd unit.',
                ));
            }

            $now = microtime(true);
            $previous = $this->previousSample();
            Cache::put(self::PREVIOUS_KEY, ['at' => $now, 'counters' => $counters], 600);

            $elapsed = $previous === null ? null : $now - $previous['at'];

            $counter = fn (string $field, string $unit, string $note) => isset($counters[$field])
                ? Metric::of($counters[$field], self::VMSTAT_SOURCE, $unit, $note)
                : Metric::notSupported(self::VMSTAT_SOURCE, "This kernel does not report {$field} in /proc/vmstat.");

            $rate = function (string $field, string $unit) use ($counters, $previous, $elapsed) {
                if (!isset($counters[$field])) {
                    return Metric::notSupported(self::VMSTAT_SOURCE, "This kernel does not report {$field} in /proc/vmstat.");
                }
                if ($elapsed === null) {
                    return Metric::noData(self::VMSTAT_SOURCE, 'Collecting the first sample; paging rates appear one minute after monitoring starts.');
                }
                if ($elapsed < 0) {
                    return Metric::noData(self::VMSTAT_SOURCE, 'The clock stepped backwards between the two samples, so they span no measurable interval.');
                }
                if ($elapsed < self::MINIMUM_SAMPLE_SECONDS) {
                    return Metric::noData(self::VMSTAT_SOURCE, 'The previous sample is too recent to measure a rate against.');
                }
                if ($elapsed > self::MAXIMUM_SAMPLE_SECONDS) {
                    return Metric::noData(self::VMSTAT_SOURCE, 'The previous sample is too old to describe the present; the rate returns at the next collection.');
                }
                if (!isset($previous['counters'][$field])) {
                    // Nothing to subtract from. Reading the absence as zero would make the delta
                    // the entire since-boot total and publish the machine's whole lifetime of
                    // faults as if they had all happened in the last few seconds.
                    return Metric::noData(self::VMSTAT_SOURCE, "The previous sample did not record {$field}, so there is nothing to subtract.");
                }

                $delta = $counters[$field] - $previous['counters'][$field];
                if ($delta < 0) {
                    // vmstat counters only ever climb, so a fall means the host rebooted between
                    // the two samples and the older one describes a machine that no longer exists.
                    return Metric::noData(self::VMSTAT_SOURCE, 'The counter reset; the host rebooted between samples.');
                }

                return Metric::of(round($delta / $elapsed, 1), self::VMSTAT_SOURCE, $unit);
            };

            return [
                'page_faults' => $counter('pgfault', 'faults', 'Since boot.'),
                'page_faults_per_sec' => $rate('pgfault', 'faults/s'),
                'major_page_faults' => $counter('pgmajfault', 'faults', 'Since boot; each one went to the disk for its page.'),
                'major_page_faults_per_sec' => $rate('pgmajfault', 'faults/s'),
                'swap_in_per_sec' => $rate('pswpin', 'pages/s'),
                'swap_out_per_sec' => $rate('pswpout', 'pages/s'),
                'oom_kills' => isset($counters['oom_kill'])
                    ? Metric::of($counters['oom_kill'], self::VMSTAT_SOURCE, 'kills', 'Processes killed by the kernel out-of-memory killer since boot.')
                    : Metric::notSupported(
                        self::VMSTAT_SOURCE,
                        'This kernel exports no oom_kill counter (added in Linux 4.13); kills are only visible in dmesg here.',
                    ),
            ];
        } catch (\Throwable $exception) {
            return array_fill_keys(self::VMSTAT_METRICS, Metric::failed(self::VMSTAT_SOURCE, $exception));
        }
    }

    /**
     * The last reading, or null when the cache holds nothing sound to subtract from.
     *
     * Anything half-shaped — an entry from an older build, a truncated write — is treated as no
     * previous sample at all rather than being patched up with defaults. A missing timestamp read
     * as zero puts the previous sample at the epoch, and every rate measured against it divides by
     * fifty-odd years and rounds to a calm 0.0/s.
     *
     * @return array{at: float, counters: array<string, int>}|null
     */
    private function previousSample(): ?array
    {
        $previous = Cache::get(self::PREVIOUS_KEY);
        if (!is_array($previous) || !is_array($previous['counters'] ?? null) || !is_numeric($previous['at'] ?? null)) {
            return null;
        }

        $at = (float) $previous['at'];

        return $at > 0 ? ['at' => $at, 'counters' => $previous['counters']] : null;
    }

    /** @return array<string, int> */
    private function vmstat(): array
    {
        $contents = @file_get_contents('/proc/vmstat');
        if ($contents === false) {
            return [];
        }

        $counters = [];
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^(\w+)\s+(\d+)$/', trim($line), $match) === 1) {
                $counters[$match[1]] = (int) $match[2];
            }
        }

        return $counters;
    }

    /**
     * Pressure Stall Information: how much time tasks spent stalled waiting for memory.
     *
     * The one number that separates a machine using its RAM from a machine drowning in it — a high
     * used_pct with no pressure is a working cache, the same used_pct with rising pressure is
     * thrashing. It is a kernel build option, so its absence is reported as such rather than as
     * zero pressure, which would read as a perfectly calm server.
     */
    private function pressure(string $scope, string $window): Metric
    {
        return Metric::probe(self::PSI_SOURCE, function () use ($scope, $window) {
            if (!$this->environment->has('psi')) {
                return Metric::notSupported(
                    self::PSI_SOURCE,
                    'This kernel was built without Pressure Stall Information, so memory pressure cannot be measured.',
                    'Boot the kernel with psi=1 (and CONFIG_PSI=y) to enable /proc/pressure.',
                );
            }

            $contents = (string) @file_get_contents('/proc/pressure/memory');
            if (preg_match('/^' . preg_quote($scope, '/') . '\s+.*' . preg_quote($window, '/') . '=([0-9.]+)/m', $contents, $match) === 1) {
                return round((float) $match[1], 2);
            }

            return Metric::noData(self::PSI_SOURCE);
        }, '%');
    }

    private function human(int $bytes): string
    {
        return $bytes >= self::MEGABYTE * 1024
            ? round($bytes / (self::MEGABYTE * 1024), 1) . ' GB'
            : round($bytes / self::MEGABYTE) . ' MB';
    }

    private function readInt(string $path): ?int
    {
        $contents = @file_get_contents($path);

        return is_string($contents) && is_numeric(trim($contents)) ? (int) trim($contents) : null;
    }
}
