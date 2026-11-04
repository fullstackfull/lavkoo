<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Environment;
use Illuminate\Support\Facades\Cache;

/**
 * CPU, from the kernel's own counters.
 *
 * Two things this deliberately does NOT do, because both are the usual way CPU monitoring lies:
 *
 * 1. It does not report load average as if it were utilisation. Load is a count of runnable and
 *    uninterruptible tasks; on a 4-core box a load of 4.0 is fully busy, and on a 32-core box it
 *    is idle. Both numbers are reported, separately, with the core count beside them.
 *
 * 2. It does not report a single instantaneous percentage. /proc/stat holds monotonic counters
 *    since boot, so "CPU usage now" only exists as a difference between two readings. The
 *    previous reading is cached and the delta is what gets reported — and on the very first
 *    sample, when there is nothing to subtract, the answer is "no data yet" rather than a number
 *    derived from uptime that would read as a plausible lie.
 */
class CpuCollector implements Collector
{
    private const PREVIOUS_KEY = 'monitoring:cpu:previous';

    public function __construct(private readonly Environment $environment)
    {
    }

    public function key(): string
    {
        return 'cpu';
    }

    public function collect(): array
    {
        $cores = $this->cores();
        $usage = $this->usage();

        return [
            'cores' => $cores,
            'model' => $this->model(),
            'usage_pct' => $usage['total'] ?? Metric::noData('Linux /proc/stat'),
            'user_pct' => $usage['user'] ?? Metric::noData('Linux /proc/stat'),
            'system_pct' => $usage['system'] ?? Metric::noData('Linux /proc/stat'),
            'idle_pct' => $usage['idle'] ?? Metric::noData('Linux /proc/stat'),
            // iowait is the one people miss: a "quiet" CPU that is actually blocked on a slow disk.
            'iowait_pct' => $usage['iowait'] ?? Metric::noData('Linux /proc/stat'),
            // steal only means anything on a VM, and it is the number that proves the noisy
            // neighbour rather than the application is at fault.
            'steal_pct' => $usage['steal'] ?? Metric::noData('Linux /proc/stat'),
            'irq_pct' => $usage['irq'] ?? Metric::noData('Linux /proc/stat'),
            'softirq_pct' => $usage['softirq'] ?? Metric::noData('Linux /proc/stat'),
            'per_core' => $this->perCore(),
            'load_1m' => $this->load(0),
            'load_5m' => $this->load(1),
            'load_15m' => $this->load(2),
            'load_per_core' => $this->loadPerCore($cores),
            'runnable_processes' => $this->runnable(),
            'context_switches' => $this->fromStat('ctxt', 'context switches since boot'),
            'interrupts' => $this->fromStat('intr', 'interrupts since boot'),
            'processes_forked' => $this->fromStat('processes', 'processes forked since boot'),
            'procs_blocked' => $this->fromStat('procs_blocked', 'processes blocked on I/O'),
            'pressure_some_avg10' => $this->pressure('some', 'avg10'),
            'pressure_some_avg60' => $this->pressure('some', 'avg60'),
            'pressure_full_avg10' => $this->pressure('full', 'avg10'),
            'frequency_mhz' => $this->frequency(),
            'thermal_throttled' => $this->throttling(),
        ];
    }

    public function gauges(): array
    {
        $collected = $this->collect();

        return array_filter([
            'server.cpu.usage_pct' => $collected['usage_pct'],
            'server.cpu.iowait_pct' => $collected['iowait_pct'],
            'server.cpu.steal_pct' => $collected['steal_pct'],
            'server.cpu.load_1m' => $collected['load_1m'],
            'server.cpu.load_per_core' => $collected['load_per_core'],
            'server.cpu.pressure_some_avg10' => $collected['pressure_some_avg10'],
        ], fn (Metric $metric) => $metric->isOk());
    }

    // -------------------------------------------------------------------------------------------

    private function cores(): Metric
    {
        return Metric::probe('Linux /proc/cpuinfo', function () {
            if (!$this->environment->has('proc_cpuinfo')) {
                return Metric::notSupported('Linux /proc/cpuinfo', 'This host does not expose /proc/cpuinfo.');
            }

            $contents = (string) @file_get_contents('/proc/cpuinfo');
            $logical = substr_count($contents, 'processor');

            return $logical > 0 ? $logical : Metric::noData('Linux /proc/cpuinfo');
        });
    }

    private function model(): Metric
    {
        return Metric::probe('Linux /proc/cpuinfo', function () {
            if (!$this->environment->has('proc_cpuinfo')) {
                return Metric::notSupported('Linux /proc/cpuinfo', 'This host does not expose /proc/cpuinfo.');
            }
            if (preg_match('/^model name\s*:\s*(.+)$/m', (string) @file_get_contents('/proc/cpuinfo'), $match) === 1) {
                return trim($match[1]);
            }

            return Metric::noData('Linux /proc/cpuinfo');
        });
    }

    /**
     * Utilisation as the delta between this reading of /proc/stat and the previous one.
     *
     * @return array<string, Metric>
     */
    private function usage(): array
    {
        if (!$this->environment->has('proc')) {
            $unavailable = Metric::notSupported('Linux /proc/stat', 'This host does not expose /proc/stat, so CPU utilisation cannot be measured.');

            return array_fill_keys(['total', 'user', 'system', 'idle', 'iowait', 'steal', 'irq', 'softirq'], $unavailable);
        }

        try {
            $current = $this->readAggregateJiffies();
            if ($current === null) {
                return [];
            }

            $previous = Cache::get(self::PREVIOUS_KEY);
            Cache::put(self::PREVIOUS_KEY, $current, 600);

            if (!is_array($previous) || !isset($previous['total'])) {
                // First reading on this host: there is nothing to subtract from. Saying so is the
                // only honest answer — deriving a percentage from since-boot totals would report
                // the machine's lifetime average as if it were the last minute.
                return array_fill_keys(
                    ['total', 'user', 'system', 'idle', 'iowait', 'steal', 'irq', 'softirq'],
                    Metric::noData('Linux /proc/stat', 'Collecting the first sample; utilisation appears one minute after monitoring starts.'),
                );
            }

            $elapsed = $current['total'] - $previous['total'];
            if ($elapsed <= 0) {
                return array_fill_keys(
                    ['total', 'user', 'system', 'idle', 'iowait', 'steal', 'irq', 'softirq'],
                    Metric::noData('Linux /proc/stat', 'No CPU time elapsed between samples.'),
                );
            }

            $share = fn (string $field) => Metric::of(
                round(100 * max(0, $current[$field] - $previous[$field]) / $elapsed, 1),
                'Linux /proc/stat',
                '%',
            );

            $idleTime = ($current['idle'] - $previous['idle']) + ($current['iowait'] - $previous['iowait']);

            return [
                'total' => Metric::of(round(100 * max(0, $elapsed - $idleTime) / $elapsed, 1), 'Linux /proc/stat', '%'),
                'user' => $share('user'),
                'system' => $share('system'),
                'idle' => $share('idle'),
                'iowait' => $share('iowait'),
                'steal' => $share('steal'),
                'irq' => $share('irq'),
                'softirq' => $share('softirq'),
            ];
        } catch (\Throwable $exception) {
            return array_fill_keys(
                ['total', 'user', 'system', 'idle', 'iowait', 'steal', 'irq', 'softirq'],
                Metric::failed('Linux /proc/stat', $exception),
            );
        }
    }

    /** @return array<string, int>|null */
    private function readAggregateJiffies(): ?array
    {
        $line = $this->statLine('cpu ');
        if ($line === null) {
            return null;
        }

        return $this->parseJiffies($line);
    }

    /** @return array<string, int> */
    private function parseJiffies(string $line): array
    {
        $fields = preg_split('/\s+/', trim($line)) ?: [];
        array_shift($fields);   // the "cpu"/"cpuN" label
        $values = array_map('intval', $fields);

        $named = [
            'user' => $values[0] ?? 0,
            'nice' => $values[1] ?? 0,
            'system' => $values[2] ?? 0,
            'idle' => $values[3] ?? 0,
            'iowait' => $values[4] ?? 0,
            'irq' => $values[5] ?? 0,
            'softirq' => $values[6] ?? 0,
            'steal' => $values[7] ?? 0,
        ];
        $named['total'] = array_sum($named);

        return $named;
    }

    private function statLine(string $prefix): ?string
    {
        $handle = @fopen('/proc/stat', 'r');
        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (str_starts_with($line, $prefix)) {
                    return $line;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * Per-core utilisation, so one pinned core is visible behind a calm average.
     */
    private function perCore(): Metric
    {
        return Metric::probe('Linux /proc/stat', function () {
            if (!$this->environment->has('proc')) {
                return Metric::notSupported('Linux /proc/stat', 'This host does not expose /proc/stat.');
            }

            $current = [];
            $handle = @fopen('/proc/stat', 'r');
            if ($handle === false) {
                return Metric::permissionDenied('Linux /proc/stat', 'The web user cannot read /proc/stat.');
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    if (preg_match('/^cpu(\d+)\s/', $line, $match) === 1) {
                        $current[(int) $match[1]] = $this->parseJiffies($line);
                    }
                }
            } finally {
                fclose($handle);
            }

            if ($current === []) {
                return Metric::noData('Linux /proc/stat');
            }

            $previous = Cache::get(self::PREVIOUS_KEY . ':cores');
            Cache::put(self::PREVIOUS_KEY . ':cores', $current, 600);
            if (!is_array($previous)) {
                return Metric::noData('Linux /proc/stat', 'Collecting the first per-core sample.');
            }

            $result = [];
            foreach ($current as $core => $now) {
                $then = $previous[$core] ?? null;
                if ($then === null) {
                    continue;
                }
                $elapsed = $now['total'] - $then['total'];
                if ($elapsed <= 0) {
                    continue;
                }
                $idle = ($now['idle'] - $then['idle']) + ($now['iowait'] - $then['iowait']);
                $result[] = ['core' => $core, 'usage_pct' => round(100 * max(0, $elapsed - $idle) / $elapsed, 1)];
            }

            return $result === [] ? Metric::noData('Linux /proc/stat') : $result;
        });
    }

    private function load(int $index): Metric
    {
        return Metric::probe('Linux /proc/loadavg', function () use ($index) {
            $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
            if ($load === false || !isset($load[$index])) {
                return Metric::notSupported('Linux /proc/loadavg', 'Load average is not available on this platform.');
            }

            return round((float) $load[$index], 2);
        });
    }

    /**
     * Load relative to the number of cores — the form that actually means something.
     *
     * 1.0 means "the machine is exactly saturated" on any size of box, which is the comparison
     * everyone tries to do in their head and gets wrong.
     */
    private function loadPerCore(Metric $cores): Metric
    {
        $load = $this->load(0);
        if (!$load->isOk() || !$cores->isOk() || (int) $cores->value < 1) {
            return $load->isOk() ? Metric::noData('Linux /proc/loadavg') : $load;
        }

        return Metric::of(round($load->value / (int) $cores->value, 2), 'Linux /proc/loadavg', 'x cores');
    }

    private function runnable(): Metric
    {
        return Metric::probe('Linux /proc/loadavg', function () {
            if (!$this->environment->has('proc_loadavg')) {
                return Metric::notSupported('Linux /proc/loadavg', 'This host does not expose /proc/loadavg.');
            }
            $contents = trim((string) @file_get_contents('/proc/loadavg'));
            if (preg_match('#(\d+)/(\d+)#', $contents, $match) === 1) {
                return (int) $match[1];
            }

            return Metric::noData('Linux /proc/loadavg');
        });
    }

    private function fromStat(string $field, string $note): Metric
    {
        return Metric::probe('Linux /proc/stat', function () use ($field, $note) {
            if (!$this->environment->has('proc')) {
                return Metric::notSupported('Linux /proc/stat', 'This host does not expose /proc/stat.');
            }
            $line = $this->statLine($field . ' ');
            if ($line === null) {
                return Metric::noData('Linux /proc/stat', $note);
            }

            return (int) trim(substr($line, strlen($field)));
        });
    }

    /**
     * Pressure Stall Information: how much time tasks spent waiting for CPU.
     *
     * A kernel build option, missing on plenty of stock and VPS kernels, so its absence is
     * reported as unsupported with the flag that would enable it — not as zero pressure, which
     * would read as a perfectly healthy machine.
     */
    private function pressure(string $scope, string $window): Metric
    {
        return Metric::probe('Linux /proc/pressure/cpu', function () use ($scope, $window) {
            if (!$this->environment->has('psi')) {
                return Metric::notSupported(
                    'Linux /proc/pressure/cpu',
                    'This kernel was built without Pressure Stall Information, so CPU pressure cannot be measured.',
                    'Boot the kernel with psi=1 (and CONFIG_PSI=y) to enable /proc/pressure.',
                );
            }

            $contents = (string) @file_get_contents('/proc/pressure/cpu');
            if (preg_match('/^' . preg_quote($scope, '/') . '\s+.*' . preg_quote($window, '/') . '=([0-9.]+)/m', $contents, $match) === 1) {
                return round((float) $match[1], 2);
            }

            return Metric::noData('Linux /proc/pressure/cpu');
        }, '%');
    }

    private function frequency(): Metric
    {
        return Metric::probe('Linux /proc/cpuinfo', function () {
            if (!$this->environment->has('proc_cpuinfo')) {
                return Metric::notSupported('Linux /proc/cpuinfo', 'This host does not expose /proc/cpuinfo.');
            }
            if (preg_match_all('/^cpu MHz\s*:\s*([0-9.]+)$/m', (string) @file_get_contents('/proc/cpuinfo'), $matches) >= 1) {
                $values = array_map('floatval', $matches[1]);

                return round(array_sum($values) / count($values), 0);
            }

            return Metric::notSupported(
                'Linux /proc/cpuinfo',
                'This CPU does not report a scaling frequency (common on virtualised hosts).',
            );
        }, 'MHz');
    }

    private function throttling(): Metric
    {
        return Metric::probe('Linux /sys/devices/system/cpu', function () {
            $counters = @glob('/sys/devices/system/cpu/cpu*/thermal_throttle/core_throttle_count') ?: [];
            if ($counters === []) {
                return Metric::notSupported(
                    'Linux /sys thermal_throttle',
                    'This host exposes no thermal throttle counters, which is normal on a virtual machine.',
                );
            }

            $total = 0;
            foreach ($counters as $path) {
                $total += (int) @file_get_contents($path);
            }

            return $total;
        });
    }
}
