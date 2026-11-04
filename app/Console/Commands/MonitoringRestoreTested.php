<?php

namespace App\Console\Commands;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record that a backup was restored somewhere and came back.
 *
 * The column this fills is the difference between a backup and a hope. A shop can have a year of
 * green backup rows and no way of knowing whether any of them restores; BackupCheck already grades
 * "never restore-tested" as degraded, and until now nothing could clear that grade.
 *
 * It records a test that was performed elsewhere — it does not perform one. Restoring a database
 * is not something a monitoring command should be able to start.
 */
class MonitoringRestoreTested extends Command
{
    protected $signature = 'monitoring:restore-tested
                            {--backup= : the backup row id (defaults to the newest successful one)}
                            {--result= : what happened, e.g. "restored to staging, 1.2M rows, 4m12s"}
                            {--failed : the restore did NOT work}';

    protected $description = 'Record that a backup was restore-tested, and what the test found';

    public function handle(EventLog $events): int
    {
        $connection = DB::connection(config('monitoring.connection'));

        $backup = $this->option('backup') !== null
            ? $connection->table('monitoring_backups')->where('id', (int) $this->option('backup'))->first()
            : $connection->table('monitoring_backups')->where('status', 'success')->orderByDesc('started_at')->first();

        if ($backup === null) {
            // Nothing to attach the test to. Recording it against a backup that is not there would
            // be recording a test of nothing.
            $this->error('No backup to mark as restore-tested. Record the backup first with monitoring:backup-recorded.');

            return self::FAILURE;
        }

        $failed = (bool) $this->option('failed');
        $result = trim((string) ($this->option('result') ?: ($failed ? 'restore failed' : 'restore verified')));

        $connection->table('monitoring_backups')->where('id', $backup->id)->update([
            'restore_tested_at' => Clock::stamp(),
            'restore_test_result' => mb_substr(($failed ? 'FAILED: ' : '') . $result, 0, 191),
            'updated_at' => Clock::stamp(),
        ]);

        $events->record(
            type: EventLog::BACKUP,
            severity: $failed ? EventLog::CRITICAL : EventLog::SUCCESS,
            title: 'Backup restore test ' . ($failed ? 'failed' : 'passed'),
            key: (string) $backup->kind,
            description: $result,
            relatedId: (int) $backup->id,
        );

        $this->info('Backup #' . $backup->id . ' marked restore-tested (' . ($failed ? 'failed' : 'passed') . ').');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
