<?php

namespace App\Services\Monitoring\Operations;

use App\Services\AuditLogger;
use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The four facts an operator has to be able to record: a note, a backup, a restore test, a deploy.
 *
 * All four had a console command and nothing else, which had two costs. The obvious one is that an
 * operator reading a chart could not annotate what they were looking at. The quieter one is that
 * BackupCheck grades a shop degraded until a backup is recorded, so every install that deploys
 * through cPanel or the built-in updater — which is most of them — was permanently degraded with no
 * way in the product to say otherwise.
 *
 * The commands still work and now call this, so the shell path and the browser path cannot drift.
 */
class MonitoringJournal
{
    public function __construct(
        private readonly EventLog $events,
        private readonly AuditLogger $audit,
    ) {
    }

    public function annotate(string $title, ?string $description = null, string $severity = EventLog::INFO, ?string $key = null, ?string $at = null): bool
    {
        $title = trim($title);

        if ($title === '') {
            return false;
        }

        $this->events->record(
            type: EventLog::ANNOTATION,
            severity: in_array($severity, EventLog::SEVERITIES, true) ? $severity : EventLog::INFO,
            title: $title,
            key: $key !== null && trim($key) !== '' ? trim($key) : null,
            description: $description !== null && trim($description) !== '' ? trim($description) : null,
            occurredAt: $at !== null && trim($at) !== '' ? trim($at) : null,
        );

        $this->audit->record(action: 'monitoring.annotated', after: ['title' => $title, 'severity' => $severity]);

        return true;
    }

    /**
     * @param  array{kind?: string, status?: string, destination?: ?string, size_bytes?: ?int, duration?: ?int, started_at?: ?string, error?: ?string}  $input
     * @return array{ok: bool, id: ?int, error: ?string}
     */
    public function recordBackup(array $input): array
    {
        $kind = in_array($input['kind'] ?? null, ['database', 'files'], true) ? $input['kind'] : 'database';
        $status = ($input['status'] ?? null) === 'failed' ? 'failed' : 'success';
        $duration = isset($input['duration']) && $input['duration'] !== null ? max(0, (int) $input['duration']) : null;

        $finishedAt = Clock::now();
        $startedAt = !empty($input['started_at'])
            ? Clock::parse((string) $input['started_at'])
            : $finishedAt->copy()->subSeconds($duration ?? 0);

        try {
            $id = (int) DB::connection(config('monitoring.connection'))->table('monitoring_backups')->insertGetId([
                'kind' => $kind,
                'status' => $status,
                'destination' => !empty($input['destination']) ? mb_substr((string) $input['destination'], 0, 191) : null,
                'size_bytes' => isset($input['size_bytes']) && $input['size_bytes'] !== null ? max(0, (int) $input['size_bytes']) : null,
                'duration_seconds' => $duration,
                'error' => $status === 'failed' ? (string) ($input['error'] ?? '') : null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'created_at' => Clock::stamp(),
                'updated_at' => Clock::stamp(),
            ]);
        } catch (\Throwable $exception) {
            // A backup that succeeded and could not be recorded is still a backup. Say so rather
            // than pretending it was recorded.
            return ['ok' => false, 'id' => null, 'error' => $exception->getMessage()];
        }

        $this->events->record(
            type: EventLog::BACKUP,
            severity: $status === 'failed' ? EventLog::CRITICAL : EventLog::SUCCESS,
            title: ucfirst($kind) . ' backup ' . $status,
            key: $kind,
            description: $status === 'failed' ? (string) ($input['error'] ?? '') : (string) ($input['destination'] ?? ''),
            context: ['size_bytes' => $input['size_bytes'] ?? null, 'duration_seconds' => $duration],
            relatedId: $id,
        );

        $this->audit->record(
            action: 'monitoring.backup_recorded',
            subject: ['type' => 'monitoring_backup', 'id' => $id],
            after: ['kind' => $kind, 'status' => $status],
        );

        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /**
     * @return array{ok: bool, id: ?int, error: ?string}
     */
    public function recordRestoreTest(?int $backupId, bool $failed, ?string $result): array
    {
        $connection = DB::connection(config('monitoring.connection'));

        $backup = $backupId !== null
            ? $connection->table('monitoring_backups')->where('id', $backupId)->first()
            : $connection->table('monitoring_backups')->where('status', 'success')->orderByDesc('started_at')->first();

        if ($backup === null) {
            // Recording a test against a backup that is not there would be recording a test of
            // nothing.
            return ['ok' => false, 'id' => null, 'error' => 'no_backup_to_mark_as_restore_tested'];
        }

        $summary = trim((string) ($result ?: ($failed ? 'restore failed' : 'restore verified')));

        $connection->table('monitoring_backups')->where('id', $backup->id)->update([
            'restore_tested_at' => Clock::stamp(),
            'restore_test_result' => mb_substr(($failed ? 'FAILED: ' : '') . $summary, 0, 191),
            'updated_at' => Clock::stamp(),
        ]);

        $this->events->record(
            type: EventLog::BACKUP,
            severity: $failed ? EventLog::CRITICAL : EventLog::SUCCESS,
            title: 'Backup restore test ' . ($failed ? 'failed' : 'passed'),
            key: (string) $backup->kind,
            description: $summary,
            relatedId: (int) $backup->id,
        );

        $this->audit->record(
            action: 'monitoring.restore_tested',
            subject: ['type' => 'monitoring_backup', 'id' => (int) $backup->id],
            after: ['passed' => !$failed, 'result' => $summary],
        );

        return ['ok' => true, 'id' => (int) $backup->id, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, id: ?int, error: ?string}
     */
    public function recordDeployment(array $input): array
    {
        $release = (string) (($input['release'] ?? '') ?: app_release_version());
        $sha = !empty($input['sha']) ? (string) $input['sha'] : app_commit_sha();
        $status = in_array($input['status'] ?? null, ['success', 'failed', 'unknown'], true) ? $input['status'] : 'unknown';

        try {
            $id = (int) DB::connection(config('monitoring.connection'))->table('monitoring_deployments')->insertGetId([
                'release' => mb_substr($release, 0, 40),
                'commit_sha' => $sha !== null ? mb_substr((string) $sha, 0, 40) : null,
                'branch' => !empty($input['branch']) ? mb_substr((string) $input['branch'], 0, 96) : null,
                'environment' => mb_substr((string) (($input['environment'] ?? '') ?: app()->environment()), 0, 24),
                'deployed_by' => !empty($input['by']) ? mb_substr((string) $input['by'], 0, 96) : null,
                'duration_seconds' => isset($input['duration']) && $input['duration'] !== null ? max(0, (int) $input['duration']) : null,
                'migrations_run' => isset($input['migrations']) && $input['migrations'] !== null ? max(0, (int) $input['migrations']) : $this->migrationsRun(),
                'status' => $status,
                'notes' => !empty($input['notes']) ? (string) $input['notes'] : null,
                'deployed_at' => Clock::stamp(),
                'created_at' => Clock::stamp(),
                'updated_at' => Clock::stamp(),
            ]);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'id' => null, 'error' => $exception->getMessage()];
        }

        $this->events->record(
            type: EventLog::DEPLOY,
            severity: $status === 'failed' ? EventLog::CRITICAL : EventLog::INFO,
            title: 'Deployed ' . $release,
            key: $release,
            description: !empty($input['notes']) ? (string) $input['notes'] : null,
            context: ['status' => $status, 'branch' => $input['branch'] ?? null, 'by' => $input['by'] ?? null],
            relatedId: $id,
        );

        $this->audit->record(
            action: 'monitoring.deployment_recorded',
            subject: ['type' => 'monitoring_deployment', 'id' => $id],
            after: ['release' => $release, 'status' => $status],
        );

        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /** How many migrations have run, when the caller does not say. */
    private function migrationsRun(): ?int
    {
        try {
            return (int) DB::table('migrations')->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
