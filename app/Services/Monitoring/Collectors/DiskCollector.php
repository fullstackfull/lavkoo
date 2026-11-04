<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Disk: what is left on each filesystem, and what the devices under them are doing.
 *
 * Four things this deliberately does NOT do, because each of them is a way disk monitoring lies:
 *
 * 1. It does not turn /proc/diskstats into rates by dividing by uptime. Those are monotonic
 *    counters since boot; IOPS, throughput, latency and utilisation only exist as a difference
 *    between two readings. The previous reading is cached and the delta is what gets reported —
 *    on the first sample the answer is "no data yet", never a lifetime average dressed up as now.
 *
 * 2. It does not count a partition and its parent device as two disks. vda1's I/O is vda's I/O,
 *    and adding them would double every number on the page.
 *
 * 3. It does not skip inodes because PHP has no API for them. A filesystem can refuse to create a
 *    file with 200 GB free and df -h looking perfectly healthy — that outage costs hours precisely
 *    because nothing on the dashboard hinted at it. So `df -i` is shelled out to where the host
 *    allows it, and where it does not, the metric says so and carries the fix.
 *
 * 4. It does not report the root reserve as used space. statvfs — and therefore every PHP disk
 *    function — exposes only the blocks an unprivileged process may write, so `total - free`
 *    silently counts the kernel's reservation as consumption. On a filesystem with a large
 *    reserve that reads as a disk about to fill up while `df` says it is half empty, and the
 *    thresholds in config/monitoring.php would raise a critical alert on a healthy machine. df
 *    is asked for the real split where the host allows it, and the fallback says out loud that
 *    it is approximating.
 */
class DiskCollector implements Collector
{
    private ?bool $hasTimeout = null;

    private const PREVIOUS_KEY = 'monitoring:disk:previous';

    private const SPACE_SOURCE = 'PHP disk_total_space()/disk_free_space()';
    private const DF_SPACE_SOURCE = 'Linux df -Pk';
    private const INODE_SOURCE = 'Linux df -iP';
    private const DISKSTATS_SOURCE = 'Linux /proc/diskstats';

    /** Counters in /proc/diskstats are always in 512-byte sectors, whatever the hardware uses. */
    private const SECTOR_BYTES = 512;

    private const BYTES_PER_MB = 1048576;

    /**
     * Below this the window is short enough that sampling jitter, not the disk, decides the
     * answer — a single 4 KB read across 20 ms would be published as 50 IOPS.
     */
    private const MIN_INTERVAL_SECONDS = 0.2;

    /** @var list<string> */
    private const DEVICE_METRICS = [
        'read_iops', 'write_iops', 'read_mbps', 'write_mbps',
        'read_latency_ms', 'write_latency_ms', 'util_pct', 'queue_depth',
    ];

    /** @var list<string> */
    private const MOUNT_METRICS = ['total', 'used', 'free', 'used_pct'];

    /** @var list<string> */
    private const INODE_METRICS = ['inodes_total', 'inodes_used', 'inode_used_pct'];

    /** The chartable subset, matched against the metric name in front of its "@label". */
    private const GAUGE_METRICS = ['used_pct', 'inode_used_pct', 'read_iops', 'write_iops', 'util_pct'];

    /** @var array<string, Metric>|null */
    private ?array $readings = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'disk';
    }

    public function collect(): array
    {
        // Sampled once per instance. The I/O metrics are deltas against a cached previous reading,
        // so collecting twice in one request — once for the table, once for gauges() — would leave
        // the second pass with a few microseconds of window and nothing honest to report.
        return $this->readings ??= array_merge(
            $this->mountReadings(),
            $this->deviceReadings(),
            [
                'storage_writable' => $this->storageWritable(),
                'logs_size' => $this->logsSize(),
            ],
        );
    }

    public function gauges(): array
    {
        $gauges = [];

        foreach ($this->collect() as $name => $metric) {
            $base = explode('@', $name, 2)[0];
            if (!in_array($base, self::GAUGE_METRICS, true) || !$metric->isOk() || !is_numeric($metric->value)) {
                continue;
            }

            $gauges['server.disk.' . $name] = $metric;
        }

        return $gauges;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Space and inodes for every filesystem the application actually depends on.
     *
     * @return array<string, Metric>
     */
    private function mountReadings(): array
    {
        $keys = array_merge(self::MOUNT_METRICS, self::INODE_METRICS);

        try {
            $mounts = $this->mounts();
            if ($mounts === []) {
                return array_fill_keys(
                    $keys,
                    Metric::noData(self::SPACE_SOURCE, 'None of the application paths could be resolved on disk.'),
                );
            }

            $readings = [];
            foreach ($mounts as $mount => $group) {
                $readings += $this->labelled($this->space($group['probe_path'], $group['note']), $mount);
                $readings += $this->labelled($this->inodes($group['probe_path']), $mount);
            }

            return $readings;
        } catch (\Throwable $exception) {
            return array_fill_keys($keys, Metric::failed(self::SPACE_SOURCE, $exception));
        }
    }

    /**
     * @return array<string, Metric>
     */
    private function space(string $path, ?string $note): array
    {
        $viaDf = $this->environment->has('shell') ? $this->readSpaceRow($path) : null;
        if ($viaDf !== null) {
            return $this->spaceMetrics($viaDf, self::DF_SPACE_SOURCE, $note);
        }

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return array_fill_keys(self::MOUNT_METRICS, Metric::permissionDenied(
                self::SPACE_SOURCE,
                "PHP could not read the free space of {$path}.",
                "Grant the PHP user traverse (x) permission on every directory in {$path}, or add it to open_basedir in php.ini.",
            ));
        }

        // No df here, so the blocks held back for root cannot be told apart from the blocks in
        // use and land in "used". The metric says so rather than leaving someone to discover the
        // gap by running df themselves.
        return $this->spaceMetrics(
            [(int) $total, (int) max(0.0, $total - $free), (int) $free],
            self::SPACE_SOURCE,
            trim(($note !== null && $note !== '' ? $note . '; ' : '') . 'approximate: counts blocks reserved for root as used'),
        );
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $row  [size, used, available] in bytes
     * @return array<string, Metric>
     */
    private function spaceMetrics(array $row, string $source, ?string $note): array
    {
        [$size, $used, $free] = $row;

        // Fullness is measured against what the filesystem will actually hand out — used plus
        // available — which is the percentage df prints and the one an operator cross-checks the
        // dashboard against. Anything the kernel is reserving is neither of those.
        $addressable = $used + $free;

        return [
            'total' => Metric::of($size, $source, 'bytes', $note),
            'used' => Metric::of($used, $source, 'bytes', $note),
            'free' => Metric::of($free, $source, 'bytes', $note),
            'used_pct' => $addressable > 0
                ? Metric::of(round(100 * $used / $addressable, 1), $source, '%', $note)
                : Metric::noData($source, 'This filesystem reports no addressable blocks.'),
        ];
    }

    /**
     * Space as df sees it: the only way to separate blocks that are in use from blocks the kernel
     * is holding back for root, a split statvfs does not expose at all.
     *
     * @return array{0: int, 1: int, 2: int}|null  [size, used, available] in bytes
     */
    private function readSpaceRow(string $path): ?array
    {
        try {
            $row = $this->readDfRow('-Pk', $path);
        } catch (\Throwable) {
            // Capabilities are cached for minutes, so disable_functions can have grown a
            // shell_exec since they were probed. statvfs still answers, so this degrades to the
            // approximation instead of losing the most important number on the page.
            return null;
        }

        // A row missing any of the three is not a usable space reading — the caller falls back to
        // statvfs rather than publishing a size of zero.
        if ($row === null || $row[0] === null || $row[1] === null || $row[2] === null) {
            return null;
        }

        // -k is POSIX and works on BusyBox and BSD too; GNU's -B1 does not.
        return [$row[0] * 1024, $row[1] * 1024, $row[2] * 1024];
    }

    /**
     * Inode usage, the one disk number with no PHP API at all.
     *
     * @return array<string, Metric>
     */
    private function inodes(string $path): array
    {
        $noShell = fn () => array_fill_keys(self::INODE_METRICS, Metric::permissionDenied(
            self::INODE_SOURCE,
            'This PHP may not run df, and no PHP function reports inode counts.',
            'Remove exec and shell_exec from disable_functions in php.ini and reload PHP-FPM, or expose the numbers another way (a cron writing df -i to a file that this collector reads).',
        ));

        if (!$this->environment->has('shell')) {
            return $noShell();
        }

        try {
            $row = $this->readDfRow('-iP', $path);
        } catch (\Throwable) {
            // Capability detection is cached for minutes at a time, so disable_functions can have
            // grown a shell_exec since it last ran. The operator gets the same actionable answer
            // either way, rather than a stack trace where a percentage should be.
            return $noShell();
        }

        if ($row === null) {
            return array_fill_keys(self::INODE_METRICS, Metric::noData(
                self::INODE_SOURCE,
                "df -i returned no usable line for {$path}.",
            ));
        }

        [$total, $used] = $row;

        if ($total === null || $used === null) {
            return array_fill_keys(self::INODE_METRICS, Metric::notSupported(
                self::INODE_SOURCE,
                'This filesystem does not publish inode counts — df printed "-" where the numbers belong.',
            ));
        }

        if ($total <= 0) {
            return array_fill_keys(self::INODE_METRICS, Metric::notSupported(
                self::INODE_SOURCE,
                'This filesystem allocates inodes on demand (btrfs, ZFS, XFS with dynamic inodes), so there is no inode table to exhaust.',
            ));
        }

        return [
            'inodes_total' => Metric::of($total, self::INODE_SOURCE, 'inodes'),
            'inodes_used' => Metric::of($used, self::INODE_SOURCE, 'inodes'),
            'inode_used_pct' => Metric::of(round(100 * $used / $total, 1), self::INODE_SOURCE, '%'),
        ];
    }

    /**
     * The three numeric columns of a `df -P` row. Both layouts this collector asks for line up:
     * total, used, free, then a percentage — 1024-blocks/Used/Available for space, Inodes/IUsed/
     * IFree for inodes.
     *
     * @return array{0: int, 1: int, 2: int}|null  [total, used, free]
     */
    private function readDfRow(string $flags, string $path): ?array
    {
        $argument = escapeshellarg($path);

        // ONE command, and it is the bounded one wherever coreutils has it. Trying `timeout 5 df`
        // and then falling back to a bare `df` defeated the guard entirely: on a hung NFS or CIFS
        // mount the timeout does its job, the retry runs the same blocked call with no limit, and
        // the dashboard hangs for as long as the mount does. The bare form is used only where
        // there is no timeout binary to use.
        $command = $this->hasTimeoutBinary()
            ? "timeout 5 df {$flags} -- {$argument}"
            : "df {$flags} -- {$argument}";

        $output = @shell_exec($command . ' 2>/dev/null');

        if (!is_string($output)) {
            return null;
        }

        foreach (explode("\n", $output) as $line) {
            // A filesystem that has no such counter prints "-" where the number belongs; the
            // header row has words there and never matches. Null, not zero: "this filesystem does
            // not publish a used figure" and "nothing is used" are different claims, and the
            // second one sizes a disk wrongly.
            if (preg_match('/^\S+\s+(\d+|-)\s+(\d+|-)\s+(\d+|-)\s+(\d+%|-)\s+\S/', trim($line), $match) === 1) {
                return [
                    ctype_digit($match[1]) ? (int) $match[1] : null,
                    ctype_digit($match[2]) ? (int) $match[2] : null,
                    ctype_digit($match[3]) ? (int) $match[3] : null,
                ];
            }
        }

        return null;
    }

    /**
     * Is coreutils' timeout available? Probed once per process, with its own bounded call.
     */
    private function hasTimeoutBinary(): bool
    {
        if ($this->hasTimeout !== null) {
            return $this->hasTimeout;
        }

        $found = @shell_exec('command -v timeout 2>/dev/null');

        return $this->hasTimeout = is_string($found) && trim($found) !== '';
    }

    /**
     * The filesystems worth reporting, deduplicated by mount point.
     *
     * base_path(), storage_path() and / are usually the same filesystem, and reporting it three
     * times under three names would read as three disks filling up at once.
     *
     * @return array<string, array{probe_path: string, note: ?string}>
     */
    private function mounts(): array
    {
        $table = $this->mountTable();
        $candidates = [
            base_path() => 'application root',
            storage_path() => 'storage',
            DIRECTORY_SEPARATOR => 'root filesystem',
        ];

        $groups = [];
        foreach ($candidates as $path => $description) {
            $real = realpath($path);
            if ($real === false) {
                continue;
            }

            $mount = $this->mountPointFor($real, $table);
            $groups[$mount] ??= ['probe_path' => $real, 'device' => $table[$mount] ?? null, 'holds' => []];
            $groups[$mount]['holds'][] = $description;
        }

        return array_map(fn (array $group) => [
            'probe_path' => $group['probe_path'],
            'note' => trim(($group['device'] ? $group['device'] . '; ' : '') . implode(', ', $group['holds'])),
        ], $groups);
    }

    /**
     * @param  array<string, string>  $table
     */
    private function mountPointFor(string $path, array $table): string
    {
        // Without /proc/mounts there is no way to know which filesystem a path belongs to, so the
        // path itself becomes the label. It is still stable and still bounded to three entries.
        if ($table === []) {
            return $path;
        }

        $best = DIRECTORY_SEPARATOR;
        foreach (array_keys($table) as $mount) {
            $prefix = rtrim($mount, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (($path === $mount || str_starts_with($path . DIRECTORY_SEPARATOR, $prefix)) && strlen($mount) > strlen($best)) {
                $best = $mount;
            }
        }

        return $best;
    }

    /**
     * @return array<string, string>  mount point => "device fstype"
     */
    private function mountTable(): array
    {
        $contents = is_readable('/proc/mounts') ? @file_get_contents('/proc/mounts') : false;
        if (!is_string($contents)) {
            return [];
        }

        $table = [];
        foreach (explode("\n", $contents) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) < 3) {
                continue;
            }

            // A later mount of the same point shadows the earlier one, which is what a process
            // walking the path would land on too.
            $table[$this->unescapeMountPath($fields[1])] = $fields[0] . ' ' . $fields[2];
        }

        return $table;
    }

    /** The kernel octal-escapes whitespace and backslashes in /proc/mounts. */
    private function unescapeMountPath(string $path): string
    {
        return str_replace(['\040', '\011', '\012', '\134'], [' ', "\t", "\n", '\\'], $path);
    }

    /**
     * Per-device I/O, as the delta between this reading of /proc/diskstats and the previous one.
     *
     * @return array<string, Metric>
     */
    private function deviceReadings(): array
    {
        // The capability probe is inside the guard too: it reads the filesystem and the cache,
        // and a collector that throws takes the dashboard down with it.
        try {
            if (!$this->environment->has('proc_diskstats')) {
                return ['device_io' => Metric::notSupported(
                    self::DISKSTATS_SOURCE,
                    'This host does not expose /proc/diskstats, so per-device I/O cannot be measured.',
                )];
            }

            $current = $this->readDiskstats();
            if ($current === []) {
                return ['device_io' => Metric::noData(
                    self::DISKSTATS_SOURCE,
                    'No physical block devices in /proc/diskstats; everything listed is a loop, ram or partition entry.',
                )];
            }

            $now = microtime(true);
            $previous = Cache::get(self::PREVIOUS_KEY);
            Cache::put(self::PREVIOUS_KEY, ['at' => $now, 'devices' => $current], 600);

            if (!is_array($previous) || !isset($previous['at'], $previous['devices'])) {
                return ['device_io' => Metric::noData(
                    self::DISKSTATS_SOURCE,
                    'Collecting the first sample; disk I/O rates appear one minute after monitoring starts.',
                )];
            }

            $elapsed = $now - (float) $previous['at'];
            if ($elapsed < self::MIN_INTERVAL_SECONDS) {
                return ['device_io' => Metric::noData(
                    self::DISKSTATS_SOURCE,
                    'The previous sample is too recent to derive a rate from.',
                )];
            }

            $readings = [];
            foreach ($current as $device => $counters) {
                $readings += $this->labelled(
                    $this->deviceRates($counters, $previous['devices'][$device] ?? null, $elapsed),
                    $device,
                );
            }

            return $readings;
        } catch (\Throwable $exception) {
            return ['device_io' => Metric::failed(self::DISKSTATS_SOURCE, $exception)];
        }
    }

    /**
     * @param  array<string, int>  $counters
     * @param  array<string, int>|null  $previous
     * @return array<string, Metric>
     */
    private function deviceRates(array $counters, ?array $previous, float $elapsed): array
    {
        if ($previous === null) {
            return array_fill_keys(self::DEVICE_METRICS, Metric::noData(
                self::DISKSTATS_SOURCE,
                'This device was not present at the previous sample.',
            ));
        }

        $delta = [];
        foreach ($counters as $field => $value) {
            // Defaulting a missing counter to zero would make the delta the whole since-boot
            // total, and the machine's lifetime traffic would be published as one window of it.
            // A previous sample written by an older build is exactly how that happens.
            if (!isset($previous[$field])) {
                return array_fill_keys(self::DEVICE_METRICS, Metric::noData(
                    self::DISKSTATS_SOURCE,
                    'The previous sample does not carry the same counters as this one.',
                ));
            }

            $delta[$field] = $value - $previous[$field];
        }

        // Counters only ever climb, so a negative delta means they were reset underneath us — a
        // reboot, or the device being removed and re-added. There is no window to measure.
        if (min($delta) < 0) {
            return array_fill_keys(self::DEVICE_METRICS, Metric::noData(
                self::DISKSTATS_SOURCE,
                'The kernel counters were reset since the previous sample (reboot or device re-attached).',
            ));
        }

        $elapsedMs = $elapsed * 1000;

        return [
            'read_iops' => Metric::of(round($delta['reads'] / $elapsed, 1), self::DISKSTATS_SOURCE, 'IO/s'),
            'write_iops' => Metric::of(round($delta['writes'] / $elapsed, 1), self::DISKSTATS_SOURCE, 'IO/s'),
            'read_mbps' => Metric::of($this->throughput($delta['read_sectors'], $elapsed), self::DISKSTATS_SOURCE, 'MB/s'),
            'write_mbps' => Metric::of($this->throughput($delta['write_sectors'], $elapsed), self::DISKSTATS_SOURCE, 'MB/s'),
            // Service time per request, not per second: the kernel accumulates the wait of every
            // completed request, so the average only exists when requests actually completed.
            'read_latency_ms' => $this->latency($delta['read_ticks'], $delta['reads'], 'reads'),
            'write_latency_ms' => $this->latency($delta['write_ticks'], $delta['writes'], 'writes'),
            // io_ticks counts wall time with at least one request in flight. On multi-queue
            // devices it is sampled rather than integrated and can drift a hair past the window,
            // so it is capped: 101% utilisation is a rounding artefact, not a finding.
            'util_pct' => Metric::of(round(min(100, 100 * $delta['io_ticks'] / $elapsedMs), 1), self::DISKSTATS_SOURCE, '%'),
            // Weighted time in queue over wall time is the average number of requests outstanding
            // — iostat's aqu-sz. This is the number that separates "the disk is busy" from "the
            // disk is busy and everything is piling up behind it".
            'queue_depth' => Metric::of(round($delta['weighted_ticks'] / $elapsedMs, 2), self::DISKSTATS_SOURCE, 'requests'),
        ];
    }

    private function throughput(int $sectors, float $elapsed): float
    {
        return round($sectors * self::SECTOR_BYTES / self::BYTES_PER_MB / $elapsed, 2);
    }

    private function latency(int $ticks, int $operations, string $kind): Metric
    {
        if ($operations <= 0) {
            return Metric::noData(self::DISKSTATS_SOURCE, "No {$kind} completed on this device during the sample window.");
        }

        return Metric::of(round($ticks / $operations, 2), self::DISKSTATS_SOURCE, 'ms');
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function readDiskstats(): array
    {
        $lines = @file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $devices = [];
        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            // major, minor, name and the eleven counters this collector reads; older kernels emit
            // a short four-field line for partitions, which is skipped here and again below.
            if (count($fields) < 14) {
                continue;
            }

            $devices[$fields[2]] = [
                'reads' => (int) $fields[3],
                'read_sectors' => (int) $fields[5],
                'read_ticks' => (int) $fields[6],
                'writes' => (int) $fields[7],
                'write_sectors' => (int) $fields[9],
                'write_ticks' => (int) $fields[10],
                'io_ticks' => (int) $fields[12],
                'weighted_ticks' => (int) $fields[13],
            ];
        }

        return $this->physicalDevices($devices);
    }

    /**
     * @param  array<string, array<string, int>>  $devices
     * @return array<string, array<string, int>>
     */
    private function physicalDevices(array $devices): array
    {
        $names = array_keys($devices);

        $physical = [];
        foreach ($devices as $name => $counters) {
            // Loopback, ramdisk and zram entries are backing files and compressed memory, not
            // disks; on a stock Ubuntu they alone would fill the table with a dozen idle rows.
            if (preg_match('/^(loop|z?ram)\d+$/', $name) === 1 || $this->isPartition($name, $names)) {
                continue;
            }

            $physical[$name] = $counters;
        }

        return $physical;
    }

    /**
     * A partition reports the same I/O as the device it lives on, so counting both doubles
     * everything. The parent is whichever other entry this name extends by nothing but a
     * partition number — and the kernel inserts a "p" first when the parent already ends in a
     * digit, which is what keeps md127 from being read as partition 27 of md1.
     *
     * @param  list<string>  $names
     */
    private function isPartition(string $name, array $names): bool
    {
        foreach ($names as $parent) {
            if ($parent === $name || !str_starts_with($name, $parent)) {
                continue;
            }

            $pattern = ctype_digit(substr($parent, -1)) ? '/^p\d+$/' : '/^\d+$/';
            if (preg_match($pattern, substr($name, strlen($parent))) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the application can still write where Laravel expects to write.
     */
    private function storageWritable(): Metric
    {
        $source = 'PHP is_writable(storage_path())';
        $path = storage_path();

        return Metric::probe($source, function () use ($source, $path) {
            if (!is_dir($path)) {
                return Metric::noData($source, "{$path} does not exist.");
            }

            // False is a real reading, not a failed probe — the store is one cache write away from
            // a 500 — so it is reported as a value, with the fix in the note rather than as a
            // state that would hide it among the things monitoring merely could not measure.
            $writable = is_writable($path);

            return Metric::of($writable, $source, null, $writable
                ? null
                : 'The PHP user cannot write to storage/. Run php artisan file:permission, or chown the storage tree to the web user.');
        });
    }

    /**
     * The size of storage/logs, because an unrotated laravel.log is the most common way a healthy
     * server runs out of disk.
     */
    private function logsSize(): Metric
    {
        $source = 'PHP filesize() over storage/logs';
        $directory = storage_path('logs');

        return Metric::probe($source, function () use ($source, $directory) {
            if (!is_dir($directory)) {
                return Metric::noData($source, "{$directory} does not exist.");
            }
            if (!is_readable($directory)) {
                return Metric::permissionDenied(
                    $source,
                    'The PHP user cannot read storage/logs.',
                    'Run php artisan file:permission, or chown -R the storage tree to the web user.',
                );
            }

            $bytes = 0;
            $files = 0;
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($entries as $entry) {
                if ($entry->isFile()) {
                    $bytes += $entry->getSize();
                    $files++;
                }
            }

            return Metric::of($bytes, $source, 'bytes', $files . ' file' . ($files === 1 ? '' : 's'));
        });
    }

    /**
     * @param  array<string, Metric>  $metrics
     * @return array<string, Metric>
     */
    private function labelled(array $metrics, string $label): array
    {
        $keyed = [];
        foreach ($metrics as $name => $metric) {
            $keyed[$name . '@' . $label] = $metric;
        }

        return $keyed;
    }
}
