<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Can the application still write where it needs to, and is the disk about to fill?
 *
 * A read-only storage directory is invisible until a customer uploads a prescription and gets a
 * 500, and a full disk takes the database down with it — both are silent right up to the moment
 * they are not, which is exactly what a scheduled probe is for. Writes go to a temporary file that
 * is removed immediately; nothing is left behind on a healthy run.
 */
class StorageCheck implements Check
{
    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly MonitoringSettings $settings,
    ) {
    }

    public function key(): string
    {
        return 'storage';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        $started = hrtime(true);
        $failures = [];
        $probed = [];

        foreach ($this->targets() as $name => $path) {
            $probed[] = $name;
            $failure = $this->probeWrite($path);
            if ($failure !== null) {
                $failures[$name] = $failure;
            }
        }

        $elapsed = (int) round((hrtime(true) - $started) / 1e6);

        if ($failures !== []) {
            return CheckResult::failing(
                $this->key(),
                'Not writable: ' . implode(', ', array_keys($failures)) . '.',
                $elapsed,
                ['probed' => $probed, 'failures' => $failures],
            );
        }

        return $this->withDiskHeadroom($probed, $elapsed);
    }

    /** @return array<string, string> */
    private function targets(): array
    {
        return [
            'storage/app' => storage_path('app'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/logs' => storage_path('logs'),
            'public/storage uploads' => public_path('storage'),
        ];
    }

    private function probeWrite(string $path): ?string
    {
        if (!is_dir($path)) {
            return 'the directory does not exist';
        }

        if (!is_writable($path)) {
            return 'the directory is not writable by ' . $this->processOwner();
        }

        $probe = rtrim($path, '/') . '/.monitoring-write-probe';

        try {
            if (@file_put_contents($probe, (string) time()) === false) {
                return 'a write was refused';
            }
        } finally {
            @unlink($probe);
        }

        return null;
    }

    /** Writable is necessary but not sufficient — a disk at 96% is writable right up until it is not. */
    private function withDiskHeadroom(array $probed, int $elapsed): CheckResult
    {
        $usage = $this->diskUsedPercent();
        $context = ['probed' => $probed, 'disk_used_pct' => $usage, 'default_disk' => config('filesystems.default')];

        if ($usage === null) {
            return CheckResult::ok($this->key(), 'Every path the application writes to is writable.', $elapsed, $context);
        }

        $warning = $this->settings->threshold('disk_warning');
        $critical = $this->settings->threshold('disk_critical');
        $shown = round($usage, 1);

        if ($critical !== null && $usage >= $critical) {
            return CheckResult::failing($this->key(), "The disk is {$shown}% full.", $elapsed, $context);
        }

        if ($warning !== null && $usage >= $warning) {
            return CheckResult::degraded($this->key(), "The disk is {$shown}% full.", $elapsed, $context);
        }

        return CheckResult::ok($this->key(), "Writable; the disk is {$shown}% full.", $elapsed, $context);
    }

    private function diskUsedPercent(): ?float
    {
        foreach ($this->collectors->collect('disk') as $name => $metric) {
            if (str_starts_with($name, 'used_pct') && $metric instanceof Metric && $metric->isOk() && is_numeric($metric->value)) {
                return (float) $metric->value;
            }
        }

        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());

        return is_float($total) && is_float($free) && $total > 0 ? 100 * ($total - $free) / $total : null;
    }

    private function processOwner(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $owner = @posix_getpwuid(posix_geteuid());

            return is_array($owner) ? (string) $owner['name'] : 'this process';
        }

        return 'this process';
    }
}
