<?php

namespace App\Console\Commands;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tell monitoring which build started running, and when.
 *
 * Half of this is already true without the command: every error, trace and error group is stamped
 * with app_release_version(), so "which build produced this" is answerable today. What is missing
 * is the other half — WHEN that build started running — and without it nothing can say "p95 doubled
 * at 14:20 and the deploy was at 14:19", which is the single most useful sentence a monitoring
 * system produces.
 *
 * Everything defaults from what the application can already see: the release from version.json plus
 * the commit SHA from .git, the environment from the app, the time from now. A deploy script that
 * adds one line gets the whole feature:
 *
 *   php artisan monitoring:deploy-recorded --by="$(whoami)" --duration=$SECONDS
 *
 * Nothing here guesses. If .git is absent and version.json says 1.0.0, that is what gets recorded,
 * and the panel says the release could not be identified more precisely rather than inventing a sha.
 */
class MonitoringDeployRecorded extends Command
{
    protected $signature = 'monitoring:deploy-recorded
                            {--release= : overrides the release read from version.json + .git}
                            {--sha= : overrides the commit sha}
                            {--branch= : the branch that was deployed}
                            {--by= : who deployed it}
                            {--duration= : how many seconds the deploy took}
                            {--migrations= : how many migrations ran (defaults to counting the migrations table)}
                            {--status=success : success, failed or unknown}
                            {--notes= : anything worth remembering about this release}
                            {--environment= : defaults to the application environment}';

    protected $description = 'Record that a release started running, so behaviour can be tied to it';

    public function handle(EventLog $events): int
    {
        $release = (string) ($this->option('release') ?: app_release_version());
        $sha = $this->option('sha') !== null ? (string) $this->option('sha') : app_commit_sha();
        $status = in_array($this->option('status'), ['success', 'failed', 'unknown'], true)
            ? (string) $this->option('status')
            : 'unknown';

        try {
            $id = DB::connection(config('monitoring.connection'))->table('monitoring_deployments')->insertGetId([
                'release' => mb_substr($release, 0, 40),
                'commit_sha' => $sha !== null ? mb_substr($sha, 0, 40) : null,
                'branch' => $this->option('branch') !== null ? mb_substr((string) $this->option('branch'), 0, 96) : null,
                'environment' => mb_substr((string) ($this->option('environment') ?: app()->environment()), 0, 24),
                'deployed_by' => $this->option('by') !== null ? mb_substr((string) $this->option('by'), 0, 96) : null,
                'duration_seconds' => $this->option('duration') !== null ? max(0, (int) $this->option('duration')) : null,
                'migrations_run' => $this->migrationsRun(),
                'status' => $status,
                'notes' => $this->option('notes') !== null ? (string) $this->option('notes') : null,
                'deployed_at' => Clock::stamp(),
                'created_at' => Clock::stamp(),
                'updated_at' => Clock::stamp(),
            ]);
        } catch (\Throwable $exception) {
            $this->error('The deployment could not be recorded: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $events->record(
            type: EventLog::DEPLOY,
            severity: $status === 'failed' ? EventLog::CRITICAL : EventLog::INFO,
            title: 'Deployed ' . $release,
            key: $release,
            description: $this->option('notes') !== null ? (string) $this->option('notes') : null,
            context: ['status' => $status, 'branch' => $this->option('branch'), 'by' => $this->option('by')],
            relatedId: (int) $id,
        );

        $this->info("Recorded deployment #{$id}: {$release} ({$status}).");

        return self::SUCCESS;
    }

    /**
     * How many migrations this application has run in total.
     *
     * Counted rather than asked for, because a number typed into a deploy script is a number that
     * goes stale. The panel reads the DIFFERENCE between consecutive deployments, which is the
     * figure that matters: a release that ran fourteen migrations is a release to watch.
     */
    private function migrationsRun(): ?int
    {
        if ($this->option('migrations') !== null) {
            return max(0, (int) $this->option('migrations'));
        }

        try {
            return (int) DB::table('migrations')->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
