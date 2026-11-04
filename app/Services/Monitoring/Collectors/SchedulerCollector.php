<?php

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Metric;
use App\Console\ScheduleDefinition;
use Illuminate\Console\Scheduling\Schedule;
use App\Services\Monitoring\Support\Clock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The scheduler, per task, from what actually ran.
 *
 * The old dashboard read a single `scheduler_last_run_at` setting written by one heartbeat closure,
 * which answers exactly one question — "did cron fire at all" — and answers it with a dash the
 * moment the server cron was never installed. Worse, it goes green while a task inside the
 * schedule has been failing for a week, because the heartbeat itself kept succeeding.
 *
 * So this reads two things instead. The DEFINED schedule, from Laravel's own Schedule object, is
 * the list of tasks that are supposed to run and when. The RECORDED runs, written by the listeners
 * in MonitoringServiceProvider, are what actually happened. A task that is defined but has no
 * recent run — past the point its own cron expression says it should have run — is LATE or MISSED,
 * and that is a state the old design could not express at all.
 */
class SchedulerCollector implements Collector
{
    /** How far past its due time a task is merely late rather than missed. */
    private const LATE_GRACE_MINUTES = 5;

    public function key(): string
    {
        return 'scheduler';
    }

    public function collect(): array
    {
        $tasks = $this->tasks();
        $cronInstalled = $this->cronInstalled($tasks);

        return [
            'cron_installed' => $cronInstalled,
            'last_run_at' => $this->lastRunAt(),
            'last_run_age_minutes' => $this->lastRunAgeMinutes(),
            'defined_tasks' => Metric::of(count($tasks), 'Laravel Schedule'),
            'tasks' => Metric::of($tasks, 'Laravel Schedule + monitoring_scheduled_runs'),
            'healthy_tasks' => $this->countWithStatus($tasks, ['healthy', 'running']),
            'late_tasks' => $this->countWithStatus($tasks, ['late']),
            'missed_tasks' => $this->countWithStatus($tasks, ['missed']),
            'failed_tasks' => $this->countWithStatus($tasks, ['failed']),
            'failures_24h' => $this->failures24h(),
        ];
    }

    public function gauges(): array
    {
        $tasks = $this->tasks();

        return array_filter([
            'scheduler.last_run_age_minutes' => $this->lastRunAgeMinutes(),
            'scheduler.late_tasks' => $this->countWithStatus($tasks, ['late']),
            'scheduler.missed_tasks' => $this->countWithStatus($tasks, ['missed']),
            'scheduler.failed_tasks' => $this->countWithStatus($tasks, ['failed']),
        ], fn (Metric $metric) => $metric->isOk());
    }

    /**
     * Every defined task, married to its recorded history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tasks(): array
    {
        try {
            $defined = $this->definedTasks();
            // Best-effort: the recorded history lives on the monitoring connection, and when that is
            // unreachable an operator still needs to see what is supposed to run. Blanking the whole
            // table because the history is missing turns "I cannot reach the monitoring database"
            // into "nothing is scheduled", which are opposite things.
            $runs = $this->latestRuns(array_column($defined, 'task'));
            $now = Clock::now();
            $tasks = [];

            foreach ($defined as $definition) {
                $run = $runs[$definition['task']] ?? null;
                $due = $this->previousDueAt($definition['expression'], $now);

                $tasks[] = [
                    'task' => $definition['task'],
                    'description' => $definition['description'],
                    'expression' => $definition['expression'],
                    'last_run_at' => $run?->started_at,
                    'last_status' => $run?->status,
                    'last_duration_ms' => $run?->duration_ms !== null ? (int) $run->duration_ms : null,
                    'last_error' => $run?->error,
                    'next_due_at' => $this->nextDueAt($definition['expression'], $now)?->toDateTimeString(),
                    'status' => $this->statusOf($run, $due, $now),
                ];
            }

            return $tasks;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What the application says should be running.
     *
     * Read from the live Schedule rather than a hand-maintained list, so a task added to
     * bootstrap/app.php is monitored the moment it exists — the alternative is a monitoring list
     * that silently drifts out of date, which is how a task stops running unnoticed.
     *
     * @return array<int, array{task: string, description: string, expression: string}>
     */
    private function definedTasks(): array
    {
        $tasks = [];

        foreach ($this->scheduledEvents() as $event) {
            $tasks[] = [
                'task' => $this->taskName($event),
                'description' => (string) ($event->description ?? ''),
                'expression' => (string) $event->expression,
            ];
        }

        return $tasks;
    }

    /**
     * The events the application actually schedules, readable from a web request.
     *
     * Laravel registers the schedule from `Artisan::starting`, so the container's Schedule is empty
     * on any request that is not a console command — which is every request that renders this page.
     * Applying the same definition to a throwaway Schedule reads what is defined without starting a
     * console process, and without a second hand-maintained list to drift out of date.
     *
     * @return array<int, \Illuminate\Console\Scheduling\Event>
     */
    private function scheduledEvents(): array
    {
        $registered = app(Schedule::class);

        if ($registered->events() !== []) {
            return $registered->events();
        }

        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        return $schedule->events();
    }

    /**
     * The stable name a task is recorded under.
     *
     * A command event knows its command line; a closure only has whatever ->name() gave it, which
     * is why every scheduled closure in this project is named.
     */
    private function taskName(object $event): string
    {
        $command = (string) ($event->command ?? '');
        if ($command !== '') {
            // Laravel prefixes the PHP binary and artisan path; the command itself is what matters.
            $command = preg_replace("/^.*'artisan'\s*/", '', $command) ?? $command;

            return trim(str_replace("'", '', $command));
        }

        return (string) ($event->description ?: 'closure@' . substr(sha1((string) $event->expression), 0, 8));
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, object>
     */
    private function latestRuns(array $names): array
    {
        if ($names === []) {
            return [];
        }

        try {
            $connection = DB::connection(config('monitoring.connection', 'monitoring'));

            // One row per task: the most recent run. A correlated subquery would be N queries; this
            // is one, and the table is small because runs are pruned by retention.
            $latest = $connection->table('monitoring_scheduled_runs')
                ->select('task', DB::raw('MAX(id) as id'))
                ->whereIn('task', $names)
                ->groupBy('task');

            return $connection->table('monitoring_scheduled_runs as runs')
                ->joinSub($latest, 'newest', fn ($join) => $join->on('runs.id', '=', 'newest.id'))
                ->get()
                ->keyBy('task')
                ->all();
        } catch (\Throwable) {
            // No history rather than no schedule. Every task then reads as never-run, which is what
            // the status column already knows how to say.
            return [];
        }
    }

    /**
     * A task's state, which is the whole point of this collector.
     */
    private function statusOf(?object $run, ?Carbon $due, Carbon $now): string
    {
        if ($run === null) {
            // Never recorded. Either the scheduler has never run, or this task was added after the
            // last run — both are "unknown", not "healthy".
            return $due !== null && $due->diffInMinutes($now) > self::LATE_GRACE_MINUTES ? 'missed' : 'unknown';
        }

        if ($run->status === 'running') {
            return 'running';
        }
        if ($run->status === 'failed') {
            return 'failed';
        }

        if ($due === null) {
            return $run->status === 'success' ? 'healthy' : 'unknown';
        }

        $lastRun = Clock::parse($run->started_at);
        if ($lastRun->greaterThanOrEqualTo($due)) {
            return 'healthy';
        }

        // It was due and has not run since. How far past decides late from missed.
        $minutesLate = $due->diffInMinutes($now);

        return $minutesLate > self::LATE_GRACE_MINUTES * 4 ? 'missed' : 'late';
    }

    private function lastRunAt(): Metric
    {
        return Metric::probe('monitoring_scheduled_runs', function () {
            $latest = DB::connection(config('monitoring.connection', 'monitoring'))
                ->table('monitoring_scheduled_runs')
                ->max('started_at');

            if ($latest === null) {
                return Metric::collectorOffline(
                    'monitoring_scheduled_runs',
                    'The scheduler has never reported a run, so no scheduled task is executing.',
                    'Install the server cron that drives Laravel\'s scheduler: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
                );
            }

            return $latest;
        });
    }

    private function lastRunAgeMinutes(): Metric
    {
        $last = $this->lastRunAt();
        if (!$last->isOk()) {
            return $last;
        }

        return Metric::of((int) Clock::parse($last->value)->diffInMinutes(Clock::now()), 'monitoring_scheduled_runs', 'minutes');
    }

    /**
     * Whether the server cron that drives the whole scheduler is actually installed.
     *
     * This is the single most valuable thing on the page: without it, settlements never mature,
     * reminders never send and rollups never run — and every one of those failures is silent.
     */
    private function cronInstalled(array $tasks): Metric
    {
        $age = $this->lastRunAgeMinutes();
        if (!$age->isOk()) {
            return Metric::collectorOffline(
                'monitoring_scheduled_runs',
                'No scheduled task has ever reported a run.',
                'Add the server cron: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
            );
        }

        $minutes = (int) $age->value;
        if ($minutes <= (int) config('monitoring.thresholds.scheduler_late_minutes', 10)) {
            return Metric::of(true, 'monitoring_scheduled_runs');
        }

        return Metric::collectorOffline(
            'monitoring_scheduled_runs',
            "The scheduler last reported a run {$minutes} minutes ago; the server cron appears to have stopped.",
            'Check the crontab of the user that runs the site: * * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
        );
    }

    private function countWithStatus(array $tasks, array $statuses): Metric
    {
        if ($tasks === []) {
            return Metric::noData('Laravel Schedule');
        }

        return Metric::of(
            count(array_filter($tasks, static fn (array $task) => in_array($task['status'], $statuses, true))),
            'Laravel Schedule + monitoring_scheduled_runs',
        );
    }

    private function failures24h(): Metric
    {
        return Metric::probe('monitoring_scheduled_runs', fn () => DB::connection(config('monitoring.connection', 'monitoring'))
            ->table('monitoring_scheduled_runs')
            ->where('status', 'failed')
            ->where('started_at', '>=', Clock::daysAgo(1))
            ->count());
    }

    /**
     * The most recent time a cron expression says the task should have run.
     *
     * Laravel's own CronExpression is used rather than a hand-rolled parser, because "@daily",
     * "0 2 * * *" and step syntax all have to agree with what the scheduler itself will do.
     */
    private function previousDueAt(string $expression, Carbon $now): ?Carbon
    {
        try {
            // Cron expressions are evaluated in the SCHEDULER's timezone — the one Laravel uses when
            // it decides to run a task — and the result is then converted to UTC for comparison
            // against stored run times. Evaluating "daily at 02:00" in UTC on a shop configured for
            // Asia/Dhaka would call a task late by six hours every single day.
            $local = $now->copy()->setTimezone(config('app.timezone', 'UTC'));

            return Clock::parse(Carbon::instance((new \Cron\CronExpression($expression))->getPreviousRunDate($local, 0, true)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextDueAt(string $expression, Carbon $now): ?Carbon
    {
        try {
            $local = $now->copy()->setTimezone(config('app.timezone', 'UTC'));

            return Clock::parse(Carbon::instance((new \Cron\CronExpression($expression))->getNextRunDate($local, 0, true)));
        } catch (\Throwable) {
            return null;
        }
    }
}
