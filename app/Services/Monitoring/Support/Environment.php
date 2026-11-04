<?php

namespace App\Services\Monitoring\Support;

use Illuminate\Support\Facades\Cache;

/**
 * What this machine can actually be asked.
 *
 * Every "Not supported" and "Not configured" on the dashboard traces back to here, and every answer
 * is a real test performed on this host — never an assumption about what a Linux box usually has.
 * The same code runs on a bare-metal server with thermal sensors and inside a container with a
 * read-only /proc, and it must tell those two apart truthfully.
 *
 * Answers are cached for a few minutes: capabilities do not change between requests, and probing
 * the filesystem on every dashboard refresh would make the monitoring page its own worst metric.
 */
class Environment
{
    private const CACHE_KEY = 'monitoring:capabilities';
    private const CACHE_SECONDS = 300;

    /** @var array<string, bool>|null */
    private ?array $capabilities = null;

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        try {
            $this->capabilities = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->detect());
        } catch (\Throwable) {
            // A broken cache store must not blind the monitoring system.
            $this->capabilities = $this->detect();
        }

        return $this->capabilities;
    }

    public function has(string $capability): bool
    {
        return (bool) ($this->capabilities()[$capability] ?? false);
    }

    /** Forget the cached probe results — used after an operator changes the server setup. */
    public function forget(): void
    {
        $this->capabilities = null;
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // nothing to forget
        }
    }

    /**
     * @return array<string, bool>
     */
    private function detect(): array
    {
        return [
            // ---- kernel counters -------------------------------------------------------------
            'proc' => is_readable('/proc/stat'),
            'proc_meminfo' => is_readable('/proc/meminfo'),
            'proc_loadavg' => is_readable('/proc/loadavg'),
            'proc_diskstats' => is_readable('/proc/diskstats'),
            'proc_netdev' => is_readable('/proc/net/dev'),
            'proc_snmp' => is_readable('/proc/net/snmp'),
            'proc_vmstat' => is_readable('/proc/vmstat'),
            'proc_uptime' => is_readable('/proc/uptime'),
            'proc_cpuinfo' => is_readable('/proc/cpuinfo'),
            // Pressure Stall Information: a kernel build flag, absent on many stock kernels.
            'psi' => is_readable('/proc/pressure/cpu'),

            // ---- container limits ------------------------------------------------------------
            // Inside a container the host's memory is a lie; the cgroup limit is the real ceiling.
            'cgroup_v2' => is_readable('/sys/fs/cgroup/memory.max'),
            'cgroup_v1' => is_readable('/sys/fs/cgroup/memory/memory.limit_in_bytes'),
            'containerised' => $this->isContainerised(),

            // ---- hardware --------------------------------------------------------------------
            'thermal_zones' => $this->globExists('/sys/class/thermal/thermal_zone*/temp'),
            'hwmon' => $this->globExists('/sys/class/hwmon/hwmon*'),
            'sensors_bin' => $this->hasBinary('sensors'),
            'smartctl_bin' => $this->hasBinary('smartctl'),
            'nvme_bin' => $this->hasBinary('nvme'),
            'ipmitool_bin' => $this->hasBinary('ipmitool'),

            // ---- energy ----------------------------------------------------------------------
            // Intel RAPL / powercap: real joules counters. Absent on every VPS I have ever seen,
            // and unreadable by non-root even where the files exist.
            'rapl' => $this->globExists('/sys/class/powercap/intel-rapl*/energy_uj'),
            'rapl_readable' => $this->raplReadable(),

            // ---- runtime ---------------------------------------------------------------------
            'opcache' => function_exists('opcache_get_status'),
            'shell' => $this->canShellOut(),
            'posix' => function_exists('posix_getpwuid'),
            'redis_ext' => extension_loaded('redis'),
            'predis' => class_exists(\Predis\Client::class),
            'fpm' => function_exists('fpm_get_status') || php_sapi_name() === 'fpm-fcgi',
            'curl' => function_exists('curl_init'),
            'openssl' => extension_loaded('openssl'),
        ];
    }

    private function isContainerised(): bool
    {
        if (is_file('/.dockerenv')) {
            return true;
        }

        $cgroup = @file_get_contents('/proc/1/cgroup');

        return is_string($cgroup) && preg_match('/docker|lxc|kubepods|containerd/i', $cgroup) === 1;
    }

    private function globExists(string $pattern): bool
    {
        $matches = @glob($pattern);

        return is_array($matches) && $matches !== [];
    }

    private function raplReadable(): bool
    {
        foreach ((@glob('/sys/class/powercap/intel-rapl*/energy_uj') ?: []) as $path) {
            if (is_readable($path) && @file_get_contents($path) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared hosting routinely disables exec/shell_exec; anything that shells out must check first
     * rather than discover it as a warning in the middle of a dashboard render.
     */
    private function canShellOut(): bool
    {
        if (!function_exists('shell_exec') || !function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('shell_exec', $disabled, true) && !in_array('exec', $disabled, true);
    }

    private function hasBinary(string $binary): bool
    {
        if (!$this->canShellOut()) {
            return false;
        }

        $path = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');

        return is_string($path) && trim($path) !== '';
    }

    /**
     * A one-line description of the host, used as the provenance label on server metrics.
     */
    public function hostDescription(): string
    {
        $kernel = php_uname('s') . ' ' . php_uname('r');
        $host = gethostname() ?: 'unknown host';

        return $this->has('containerised') ? "{$host} (container, {$kernel})" : "{$host} ({$kernel})";
    }
}
