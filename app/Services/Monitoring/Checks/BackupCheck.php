<?php

namespace App\Services\Monitoring\Checks;

use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;

/**
 * When did a backup last succeed, and has anyone ever restored one?
 *
 * The two failure modes this exists for are the same failure: silence. A backup job that stopped
 * running looks exactly like a backup job that is running fine — until the day it is needed. And a
 * backup that has never been restore-tested is a hope, not a backup, which is why the restore test
 * is reported separately rather than folded into "last backup ok".
 *
 * Nothing here runs a backup. This reads the record that a backup tool writes; with no records at
 * all it reports not_configured rather than pretending the shop is unprotected or protected.
 */
class BackupCheck implements Check
{
    public function __construct(private readonly MonitoringSettings $settings)
    {
    }

    public function key(): string
    {
        return 'backup';
    }

    public function kind(): string
    {
        return 'health';
    }

    public function run(): CheckResult
    {
        try {
            $latest = DB::connection(config('monitoring.connection'))
                ->table('monitoring_backups')
                ->orderByDesc('started_at')
                ->first();
        } catch (\Throwable $exception) {
            return CheckResult::unknown($this->key(), Metric::describeFailure($exception));
        }

        if ($latest === null) {
            return CheckResult::notConfigured(
                $this->key(),
                'No backup has ever been recorded. Point your backup script at php artisan monitoring:backup-recorded (or write a row to monitoring_backups when it finishes) so its age can be watched.',
            );
        }

        $startedAt = Clock::parse($latest->started_at);
        $ageHours = (int) floor(Clock::now()->diffInSeconds($startedAt, false) / -3600);
        $context = [
            'kind' => $latest->kind,
            'status' => $latest->status,
            'started_at' => $startedAt->toDateTimeString(),
            'age_hours' => $ageHours,
            'size_bytes' => $latest->size_bytes !== null ? (int) $latest->size_bytes : null,
            'restore_tested_at' => $latest->restore_tested_at,
        ];

        if ($latest->status !== 'success') {
            return CheckResult::failing(
                $this->key(),
                'The last backup failed' . ($latest->error ? ': ' . mb_substr((string) $latest->error, 0, 120) : '.'),
                context: $context,
            );
        }

        $maxAge = (int) ($this->settings->threshold('backup_age_warning_hours') ?? 36);

        if ($ageHours > $maxAge * 2) {
            return CheckResult::failing($this->key(), "The last successful backup is {$ageHours} hour(s) old.", context: $context);
        }

        if ($ageHours > $maxAge) {
            return CheckResult::degraded($this->key(), "The last successful backup is {$ageHours} hour(s) old.", context: $context);
        }

        if ($latest->restore_tested_at === null) {
            return CheckResult::degraded(
                $this->key(),
                "The last backup is {$ageHours} hour(s) old and has never been restore-tested.",
                context: $context,
            );
        }

        return CheckResult::ok($this->key(), "The last successful backup is {$ageHours} hour(s) old.", context: $context);
    }
}
