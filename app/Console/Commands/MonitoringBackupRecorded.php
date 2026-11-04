<?php

namespace App\Console\Commands;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tell monitoring that a backup happened.
 *
 * This command does NOT take a backup, and deliberately so — the backup tool a shop uses is its
 * own business, and a monitoring system that also runs the backups is a monitoring system that can
 * lose them. It records the fact, which is the part nothing else knows: BackupCheck grades the age
 * of the newest row here every five minutes, and the alert engine watches that grade.
 *
 * It exists because two places in this codebase already told operators to run it — BackupCheck's
 * remedy and the overview card's — and it did not exist. A remedy naming a command that is not
 * there is worse than no remedy: it reads as "you were supposed to have set this up" and there was
 * never anything to set up.
 *
 * Call it as the last line of whatever script takes the backup:
 *
 *   mysqldump … > /backups/db-$(date +%F).sql \
 *     && php artisan monitoring:backup-recorded --destination=/backups --size-bytes=$(stat -c%s …) \
 *     || php artisan monitoring:backup-recorded --status=failed --error="mysqldump exited $?"
 */
class MonitoringBackupRecorded extends Command
{
    protected $signature = 'monitoring:backup-recorded
                            {--kind=database : database or files}
                            {--status=success : success or failed}
                            {--destination= : where the backup was written}
                            {--size-bytes= : size of the produced artefact}
                            {--duration= : how many seconds it took}
                            {--started-at= : when it began (defaults to now minus the duration)}
                            {--error= : the failure message, when status is failed}';

    protected $description = 'Record that a backup ran, so its age can be watched';

    public function handle(EventLog $events): int
    {
        $kind = in_array($this->option('kind'), ['database', 'files'], true) ? (string) $this->option('kind') : 'database';
        $status = $this->option('status') === 'failed' ? 'failed' : 'success';
        $duration = $this->option('duration') !== null ? max(0, (int) $this->option('duration')) : null;

        $finishedAt = Clock::now();
        $startedAt = $this->option('started-at') !== null
            ? Clock::parse((string) $this->option('started-at'))
            : $finishedAt->copy()->subSeconds($duration ?? 0);

        try {
            $id = DB::connection(config('monitoring.connection'))->table('monitoring_backups')->insertGetId([
                'kind' => $kind,
                'status' => $status,
                'destination' => $this->option('destination') !== null ? mb_substr((string) $this->option('destination'), 0, 191) : null,
                'size_bytes' => $this->option('size-bytes') !== null ? max(0, (int) $this->option('size-bytes')) : null,
                'duration_seconds' => $duration,
                'error' => $status === 'failed' ? (string) $this->option('error') : null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'created_at' => Clock::stamp(),
                'updated_at' => Clock::stamp(),
            ]);
        } catch (\Throwable $exception) {
            // A backup that succeeded and could not be recorded is still a backup. Say so and fail
            // the command, so the operator's script can decide — never pretend it was recorded.
            $this->error('The backup could not be recorded: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $events->record(
            type: EventLog::BACKUP,
            severity: $status === 'failed' ? EventLog::CRITICAL : EventLog::SUCCESS,
            title: ucfirst($kind) . ' backup ' . $status,
            key: $kind,
            description: $status === 'failed' ? (string) $this->option('error') : (string) $this->option('destination'),
            context: ['size_bytes' => $this->option('size-bytes'), 'duration_seconds' => $duration],
            relatedId: (int) $id,
        );

        $this->info("Recorded {$kind} backup #{$id} ({$status}).");

        return $status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
