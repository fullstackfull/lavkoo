<?php

namespace App\Console\Commands;

use App\Services\DeveloperPortal\ApiSnapshotService;
use Illuminate\Console\Command;

/**
 * Freeze the API surface, and say what changed since the last time.
 *
 * Run this at each deployment. It is what turns "we think that was backwards compatible" into a
 * list somebody can read before the support tickets arrive.
 */
class ApiSnapshotCommand extends Command
{
    protected $signature = 'api:snapshot
                            {label? : A name for this snapshot, usually the release}
                            {--diff= : Compare this snapshot id against the live API instead of capturing}
                            {--against= : With --diff, compare against this snapshot id rather than live}
                            {--list : List the snapshots taken so far}
                            {--fail-on-breaking : Exit non-zero when a breaking change is detected}';

    protected $description = 'Capture an API surface snapshot and detect breaking changes';

    public function handle(ApiSnapshotService $snapshots): int
    {
        if ($this->option('list')) {
            return $this->listSnapshots($snapshots);
        }

        if ($this->option('diff')) {
            return $this->showDiff($snapshots, (int) $this->option('diff'), $this->option('against') ? (int) $this->option('against') : null);
        }

        $version = app(\App\Services\DeveloperPortal\ApiManifest::class)->appVersion();
        $label = $this->argument('label') ?: 'v' . ($version !== null && $version !== '' ? $version : date('Y-m-d-His'));
        $result = $snapshots->captureAndRecord($label);

        if ($result['unavailable'] ?? false) {
            $this->error('The snapshot tables are not installed, so nothing was captured. Run php artisan migrate.');

            return self::FAILURE;
        }

        $this->info("Snapshot '{$result['label']}' captured: {$result['endpoints']} endpoint(s).");

        if ($result['first']) {
            $this->line('This is the first snapshot, so there is nothing to compare it against yet.');

            return self::SUCCESS;
        }

        $this->line("{$result['changes']} change(s) recorded against the previous snapshot.");

        if ($result['breaking'] > 0) {
            $this->error("⚠ {$result['breaking']} BREAKING change(s) detected. Review them in Developer Portal → Changelog before releasing.");

            return $this->option('fail-on-breaking') ? self::FAILURE : self::SUCCESS;
        }

        $this->info('No breaking changes detected.');

        return self::SUCCESS;
    }

    private function listSnapshots(ApiSnapshotService $snapshots): int
    {
        $rows = $snapshots->snapshots();

        if ($rows === []) {
            $this->warn('No snapshot has been taken yet. Run php artisan api:snapshot "v1.0" to take the first one.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Label', 'App version', 'Endpoints', 'Captured'],
            array_map(static fn (object $row) => [
                $row->id, $row->label, $row->app_version ?? '—', $row->endpoint_count, $row->captured_at,
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function showDiff(ApiSnapshotService $snapshots, int $from, ?int $to): int
    {
        $diff = $snapshots->diff($from, $to);

        if (isset($diff['error'])) {
            $this->error($diff['error']);

            return self::FAILURE;
        }

        if ($diff['changes'] === []) {
            $this->info('The API surface is identical.');

            return self::SUCCESS;
        }

        $this->table(
            ['Severity', 'Change', 'Endpoint', 'Detail'],
            array_map(static fn (array $change) => [
                $change['severity'] === 'breaking' ? '⚠ BREAKING' : $change['severity'],
                $change['detail_type'],
                mb_strimwidth($change['endpoint'], 0, 46, '…'),
                mb_strimwidth((string) $change['detail'], 0, 70, '…'),
            ], array_slice($diff['changes'], 0, 60)),
        );

        $summary = $diff['summary'];
        $this->line("{$summary['total']} change(s): {$summary['added']} added, {$summary['removed']} removed, {$summary['breaking']} breaking, {$summary['warning']} warning.");

        return $summary['breaking'] > 0 && $this->option('fail-on-breaking') ? self::FAILURE : self::SUCCESS;
    }
}
