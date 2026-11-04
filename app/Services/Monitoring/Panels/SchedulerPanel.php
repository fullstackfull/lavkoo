<?php

namespace App\Services\Monitoring\Panels;

use App\Services\Monitoring\Collectors\CollectorRegistry;
use App\Services\Monitoring\Metric;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\SeriesReader;
use Illuminate\Http\Request;

/**
 * The scheduler: whether cron is firing at all, and what each task did the last time it ran.
 *
 * One fact outranks everything else on this page. If the server cron that calls `schedule:run` is
 * not installed, no task in the schedule executes — settlements never mature, abandoned-cart
 * reminders never send, the analytics and monitoring rollups never build — and not one of those
 * failures raises anything. The shop keeps taking orders and looks perfectly healthy. So the cron
 * verdict is the first thing this panel assembles and the first thing the view draws, and the task
 * table underneath is only detail about a machine that may not be running.
 *
 * Three sources, kept apart because a gap in one is not evidence about the others. The DEFINED
 * schedule comes from Laravel's own Schedule object, via the collector — it is the list of tasks
 * that are supposed to run and when. The RECORDED runs in monitoring_scheduled_runs are what
 * actually happened, written by the task listeners in MonitoringServiceProvider. The SUCCESS RATE
 * is those runs folded over the selected window, which is the only one of the three that can say a
 * task has been failing rather than that it failed once.
 *
 * A task with no recorded run is 'unknown', never 'healthy'. That distinction is the whole reason
 * this section exists: the previous dashboard held a single heartbeat timestamp, which stayed
 * green while a task inside the schedule failed every night for a week.
 */
class SchedulerPanel implements Panel
{
    /**
     * How many recent runs the history table carries.
     *
     * A page is a page: fifty rows is more than anybody reads in one sitting, and the table says
     * out loud when the window held more so a truncated list is never mistaken for the whole story.
     */
    private const HISTORY_LIMIT = 50;

    /**
     * Upper bound on the per-task aggregate.
     *
     * The grouping is by task name, which a schedule bounds naturally — this guards the case where
     * renamed or removed tasks have left more distinct names in the window than any schedule holds,
     * so the read stays bounded and the panel reports that it was cut rather than under-counting.
     */
    private const MAX_TASK_GROUPS = 200;

    /** Collector readings that are honestly one number, drawn as cards above the table. */
    private const HEADLINE = [
        'defined_tasks', 'healthy_tasks', 'late_tasks', 'missed_tasks', 'failed_tasks',
        'failures_24h', 'last_run_age_minutes',
    ];

    /**
     * The headline readings that are counted off the defined schedule.
     *
     * They are only as readable as the schedule itself, and on a page served by a web request the
     * schedule is empty — which the collector counts as zero defined and zero healthy tasks. Those
     * zeros are wrong rather than empty, so they are withheld together and the task card says why.
     */
    private const SCHEDULE_DERIVED = ['defined_tasks', 'healthy_tasks', 'late_tasks', 'missed_tasks', 'failed_tasks'];

    /**
     * Worst first. An operator opens this page to find what is broken, and a table ordered by task
     * name puts the fifteen healthy rows above the one that has not run since Tuesday.
     */
    private const STATUS_ORDER = ['missed' => 0, 'failed' => 1, 'late' => 2, 'unknown' => 3, 'running' => 4, 'healthy' => 5];

    /**
     * Month names for reading a cron expression out loud, spelled out rather than formatted.
     *
     * Carbon::create() fills a missing year from the ambient clock and the process timezone, and no
     * timestamp in this system is allowed to come from either — a month name needs neither anyway.
     */
    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public function __construct(
        private readonly CollectorRegistry $collectors,
        private readonly SeriesReader $reader,
    ) {
    }

    public function data(string $range, Request $request): array
    {
        $window = $this->reader->window($range);
        $readings = $this->collectors->collect('scheduler');
        $statistics = $this->statistics($range);
        $tasks = $this->tasks($readings, $statistics);

        return [
            'window' => [
                'range' => $range,
                'minutes' => $window['minutes'],
                'resolution' => $window['resolution'],
                'since' => Clock::display($this->reader->since($range))->toDateTimeString(),
                'until' => Clock::display(Clock::now())->toDateTimeString(),
                'timezone' => Clock::displayTimezone(),
                // Cron expressions are evaluated in the scheduler's timezone, not the dashboard's.
                // "every day at 02:00" is meaningless without saying which clock that is.
                'scheduler_timezone' => (string) config('app.timezone', 'UTC'),
            ],
            'cron' => $this->cron($readings),
            'headline' => $this->headline($readings, scheduleReadable: $tasks['state'] === 'ok'),
            'tasks' => $tasks,
            'observed' => $this->observed($statistics),
            'statistics' => $statistics,
            'history' => $this->history($range, $readings),
            'unrendered' => $this->unrendered($readings),
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Is cron running at all

    /**
     * The verdict the rest of the page depends on.
     *
     * Three-valued on purpose. "Cron is not running" and "I cannot tell whether it is" send an
     * operator to different places — one to the crontab, the other to whatever stopped monitoring
     * from reading its own table — and flattening them into a boolean would turn an unreadable
     * probe into an outage report, or an outage into a shrug.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function cron(array $readings): array
    {
        $installed = $readings['cron_installed'] ?? null;
        $lastRun = $readings['last_run_at'] ?? null;
        $age = $readings['last_run_age_minutes'] ?? null;
        $failure = $readings['__collector'] ?? null;
        $lateAfter = (int) config('monitoring.thresholds.scheduler_late_minutes', 10);

        if (!$installed instanceof Metric) {
            return [
                'state' => $failure instanceof Metric ? $failure->state : 'not_supported',
                'running' => null,
                'note' => $failure instanceof Metric
                    ? $failure->note
                    : 'The scheduler collector is not installed in this build, so whether cron is firing cannot be read here.',
                'remedy' => null,
                'source' => $failure instanceof Metric ? $failure->source : null,
                'last_run_at' => null,
                'last_run_age_minutes' => null,
                'late_after_minutes' => $lateAfter,
                'stopped_tasks' => [],
            ];
        }

        // The collector is left without an age both when the table says nothing has ever run and
        // when the table could not be read at all, and it reports the two as the same
        // COLLECTOR_OFFLINE. Only the first is evidence that cron has stopped. Believing the second
        // would print "not one scheduled task is executing" across a page that has measured
        // nothing, and send somebody to the crontab of a server whose scheduler is running fine —
        // so the reading behind the verdict is checked before the verdict is repeated.
        $observed = $lastRun instanceof Metric
            && in_array($lastRun->state, [Metric::OK, Metric::COLLECTOR_OFFLINE], true);

        $running = match (true) {
            $installed->isOk() && $installed->value === true => true,
            // COLLECTOR_OFFLINE is the collector's own explicit verdict: it read the table and
            // found either no run ever or the newest one too old to be a running cron.
            $installed->state === Metric::COLLECTOR_OFFLINE && $observed => false,
            default => null,
        };

        // When the verdict is unknown because the read failed, the collector's note still claims
        // nothing has ever run — the very thing it could not check. The failed read's own note
        // names what actually broke, which is the sentence that leads somebody somewhere.
        $unreadable = $running === null && $lastRun instanceof Metric && !$observed;

        return [
            'state' => $unreadable ? $lastRun->state : $installed->state,
            'running' => $running,
            'note' => $unreadable ? $lastRun->note : $installed->note,
            'remedy' => $unreadable ? $lastRun->remedy : $installed->remedy,
            'source' => $installed->source,
            'last_run_at' => $lastRun instanceof Metric && $lastRun->isOk()
                ? $this->displayStamp($lastRun->value)
                : null,
            'last_run_age_minutes' => $age instanceof Metric && $age->isOk() ? (int) $age->value : null,
            'late_after_minutes' => $lateAfter,
            // Named rather than described. "Cron is down" is abstract; the fifteen commands that
            // are consequently not running is the sentence that gets somebody to fix it.
            'stopped_tasks' => $running === false ? $this->definedTaskNames($readings) : [],
        ];
    }

    /**
     * @param  array<string, Metric>  $readings
     * @return array<int, string>
     */
    private function definedTaskNames(array $readings): array
    {
        $metric = $readings['tasks'] ?? null;
        if (!$metric instanceof Metric || !$metric->isOk() || !is_array($metric->value)) {
            return [];
        }

        $names = [];
        foreach ($metric->value as $task) {
            $name = (string) (((array) $task)['task'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, Metric>  $readings
     * @return array<string, Metric>
     */
    private function headline(array $readings, bool $scheduleReadable): array
    {
        $headline = [];

        foreach (self::HEADLINE as $name) {
            if (!$scheduleReadable && in_array($name, self::SCHEDULE_DERIVED, true)) {
                continue;
            }

            $metric = $readings[$name] ?? null;
            if (!$metric instanceof Metric) {
                continue;
            }
            // An unavailable reading goes in whole — its state and remedy are the content. Only a
            // successful reading that is not a single value has nowhere honest to be drawn.
            if ($metric->isOk() && !is_scalar($metric->value)) {
                continue;
            }

            $headline[$name] = $metric;
        }

        return $headline;
    }

    // -------------------------------------------------------------------------------------------
    // The task table

    /**
     * Every defined task, its last run, and how it has behaved across the window.
     *
     * @param  array<string, Metric>  $readings
     * @param  array<string, mixed>  $statistics
     * @return array<string, mixed>
     */
    private function tasks(array $readings, array $statistics): array
    {
        $metric = $readings['tasks'] ?? null;

        if (!$metric instanceof Metric) {
            return $this->emptyTasks(
                state: 'no_data',
                note: 'The scheduler collector returned no task list, so no scheduled task can be named here.',
            );
        }

        if (!$metric->isOk() || !is_array($metric->value)) {
            return $this->emptyTasks(
                state: $metric->isOk() ? 'no_data' : $metric->state,
                note: $metric->note ?? 'The scheduler collector could not read the defined schedule.',
                remedy: $metric->remedy,
                source: $metric->source,
            );
        }

        if ($metric->value === []) {
            return $this->emptyTasks(...$this->whyNoDefinedTasks($metric->source));
        }

        $rows = [];
        foreach ($metric->value as $task) {
            $task = (array) $task;
            $name = (string) ($task['task'] ?? '');
            if ($name === '') {
                continue;
            }

            $rows[] = $this->row($name, $task, $statistics['by_task'][$name] ?? null);
        }

        usort($rows, static fn (array $a, array $b) => [self::STATUS_ORDER[$a['status']] ?? 9, $a['task']]
            <=> [self::STATUS_ORDER[$b['status']] ?? 9, $b['task']]);

        return [
            'state' => 'ok',
            'note' => null,
            'remedy' => null,
            'source' => $metric->source,
            'rows' => $rows,
            'counts' => $this->countByStatus($rows),
            // Recorded under a name the schedule no longer defines: a renamed or removed task. Its
            // history is real and worth keeping visible, but it has no next due date and no owner.
            'retired' => $this->retiredTasks($rows, $statistics),
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>|null  $statistics
     * @return array<string, mixed>
     */
    private function row(string $name, array $task, ?array $statistics): array
    {
        $expression = (string) ($task['expression'] ?? '');
        $lastRunAt = $task['last_run_at'] ?? null;
        $nextDueAt = $task['next_due_at'] ?? null;
        $description = trim((string) ($task['description'] ?? ''));

        return [
            'task' => $name,
            // The description defaults to the command line itself, which would print the command
            // twice in a two-column table and say nothing the first column has not already said.
            'description' => $description === '' || $description === $name ? null : $description,
            'expression' => $expression,
            'schedule' => $this->humanExpression($expression),
            'last_run_at' => $this->displayStamp($lastRunAt),
            'last_run_age_minutes' => $this->minutesSince($lastRunAt),
            'last_status' => ($task['last_status'] ?? null) === null ? null : (string) $task['last_status'],
            'last_duration_ms' => isset($task['last_duration_ms']) ? (int) $task['last_duration_ms'] : null,
            // First line only, like the history below: a scheduled task's error is regularly a whole
            // paragraph of exception, and a table cell is not where a stack trace belongs.
            'last_error' => $this->firstLine($task['last_error'] ?? null),
            'next_due_at' => $this->displayStamp($nextDueAt),
            'next_due_in_minutes' => $this->minutesUntil($nextDueAt),
            'status' => (string) ($task['status'] ?? 'unknown'),
            'runs' => $statistics['runs'] ?? null,
            'successes' => $statistics['successes'] ?? null,
            'failures' => $statistics['failures'] ?? null,
            'skipped' => $statistics['skipped'] ?? null,
            'settled' => $statistics['settled'] ?? null,
            'success_rate' => $statistics['success_rate'] ?? null,
            'avg_duration_ms' => $statistics['avg_duration_ms'] ?? null,
            'max_duration_ms' => $statistics['max_duration_ms'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countByStatus(array $rows): array
    {
        $counts = [];
        foreach (array_keys(self::STATUS_ORDER) as $status) {
            $counts[$status] = 0;
        }

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Task names the window recorded runs for that the schedule no longer defines.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $statistics
     * @return array<int, string>
     */
    private function retiredTasks(array $rows, array $statistics): array
    {
        $defined = array_column($rows, 'task');

        return array_values(array_diff(array_keys($statistics['by_task'] ?? []), $defined));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTasks(string $state, string $note, ?string $remedy = null, ?string $source = null): array
    {
        return [
            'state' => $state,
            'note' => $note,
            'remedy' => $remedy,
            'source' => $source,
            'rows' => [],
            'counts' => [],
            'retired' => [],
        ];
    }

    /**
     * Why the defined schedule is empty, which is not the same question everywhere it is asked.
     *
     * Laravel registers the schedule through `Artisan::starting()` — see ->withSchedule() in
     * bootstrap/app.php — so the callback only fires when the console kernel boots. A page served
     * by a web request therefore resolves a Schedule with no events in it, and the collector counts
     * that as zero defined tasks. Zero is the honest count of what this request can see and a false
     * statement about the application, so it is reported as a gap in the page rather than printed.
     *
     * @return array{state: string, note: string, remedy: string, source: string|null}
     */
    private function whyNoDefinedTasks(?string $source): array
    {
        if (app()->runningInConsole()) {
            return [
                'state' => 'no_data',
                'note' => 'This application defines no scheduled task at all, so there is nothing for cron to run.',
                'remedy' => 'Register the schedule in bootstrap/app.php (->withSchedule(...)), then confirm it with `php artisan schedule:list`.',
                'source' => $source,
            ];
        }

        return [
            'state' => 'not_supported',
            'note' => 'Laravel registers the schedule when the console kernel boots, so a page served by a web request sees an empty Schedule. The defined task list — and the healthy, late and missed counts derived from it — cannot be read here. That is a gap in what this page can see, not an empty schedule.',
            'remedy' => 'Run `php artisan schedule:list` for the defined schedule. The tasks below are read from the runs that were actually recorded, which needs no console context.',
            'source' => $source,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // What the runs say

    /**
     * Per-task outcomes over the selected window.
     *
     * One grouped read rather than a count per task: the table has as many rows as the schedule
     * has tasks, and a query each would make this page the slowest thing on the server it watches.
     *
     * @return array<string, mixed>
     */
    private function statistics(string $range): array
    {
        $source = 'monitoring_scheduled_runs';

        try {
            $connection = $this->reader->connection();
            $rows = $connection->table('monitoring_scheduled_runs')
                ->where('started_at', '>=', $this->reader->since($range))
                ->groupBy('task')
                ->limit(self::MAX_TASK_GROUPS + 1)
                ->get([
                    'task',
                    $connection->raw('COUNT(*) AS runs'),
                    $connection->raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successes"),
                    $connection->raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failures"),
                    $connection->raw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped"),
                    $connection->raw("SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS still_running"),
                    $connection->raw('SUM(duration_ms) AS total_ms'),
                    $connection->raw('MAX(duration_ms) AS max_ms'),
                    $connection->raw('COUNT(duration_ms) AS timed_runs'),
                    $connection->raw('MAX(started_at) AS last_started'),
                    $connection->raw('MAX(expression) AS expression'),
                    // An expression that changed inside the window would make MAX() one of two true
                    // answers. Counting them is what lets the table say "changed" instead of picking.
                    $connection->raw('COUNT(DISTINCT expression) AS expressions'),
                ]);
        } catch (\Throwable $exception) {
            // Caught here rather than left to PanelRegistry: losing this read blanks four columns,
            // while letting it escape would blank the cron verdict that was read perfectly well.
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'source' => $source,
                'by_task' => [],
                'truncated' => false,
            ];
        }

        $byTask = [];
        foreach ($rows->take(self::MAX_TASK_GROUPS) as $row) {
            $successes = (int) $row->successes;
            $failures = (int) $row->failures;
            $settled = $successes + $failures;
            $timed = (int) $row->timed_runs;

            $expressions = (int) $row->expressions;

            $byTask[(string) $row->task] = [
                'runs' => (int) $row->runs,
                'successes' => $successes,
                'failures' => $failures,
                'skipped' => (int) $row->skipped,
                'still_running' => (int) $row->still_running,
                'settled' => $settled,
                // Only settled runs can carry a rate. A window holding one skipped run and nothing
                // else has no success rate at all, and printing 0% there would report an outage
                // where the task was deliberately not run.
                'success_rate' => $settled > 0 ? round(100 * $successes / $settled, 1) : null,
                'avg_duration_ms' => $timed > 0 ? (int) round((int) $row->total_ms / $timed) : null,
                'max_duration_ms' => $row->max_ms === null ? null : (int) $row->max_ms,
                'last_started_at' => $this->displayStamp($row->last_started),
                'last_started_minutes_ago' => $this->minutesSince($row->last_started),
                'expression' => $expressions === 1 ? (string) $row->expression : null,
                'expression_changed' => $expressions > 1,
            ];
        }

        return [
            'state' => $byTask === [] ? 'no_data' : 'ok',
            'note' => $byTask === []
                ? 'No scheduled task reported a run inside this window, so no success rate can be calculated for it.'
                : null,
            'remedy' => $byTask === [] ? 'Widen the range, or check that cron is firing: `php artisan schedule:list`.' : null,
            'source' => $source,
            'by_task' => $byTask,
            'truncated' => $rows->count() > self::MAX_TASK_GROUPS,
        ];
    }

    /**
     * The tasks that actually reported a run in this window.
     *
     * Folded out of the aggregate already read, so it costs nothing extra. It answers a different
     * question from the table above and must not be confused with it: that one lists what the
     * application says should run, this one lists what did. When the defined schedule cannot be
     * read at all — a browser request resolves an empty Schedule — this is the only task list on
     * the page that is made of measurements, which is why it is built whether or not it is drawn.
     *
     * No status is assigned here. On-time, late and missed are judgements against a due date, and
     * the due date comes from the defined schedule; calling a task healthy because it ran would be
     * inventing the one verdict this section exists to get right.
     *
     * @param  array<string, mixed>  $statistics
     * @return array<string, mixed>
     */
    private function observed(array $statistics): array
    {
        $rows = [];
        foreach ($statistics['by_task'] ?? [] as $task => $counts) {
            $rows[] = array_merge($counts, [
                'task' => (string) $task,
                'schedule' => $counts['expression'] === null ? null : $this->humanExpression($counts['expression']),
            ]);
        }

        usort($rows, static fn (array $a, array $b) => [$b['failures'], $b['last_started_at'] ?? '']
            <=> [$a['failures'], $a['last_started_at'] ?? '']);

        return [
            'state' => $rows === [] ? ($statistics['state'] === 'ok' ? 'no_data' : $statistics['state']) : 'ok',
            'note' => $rows === [] ? $statistics['note'] : null,
            'remedy' => $rows === [] ? $statistics['remedy'] : null,
            'source' => $statistics['source'] ?? null,
            'rows' => $rows,
            'truncated' => (bool) ($statistics['truncated'] ?? false),
        ];
    }

    /**
     * The last runs recorded, newest first.
     *
     * Bounded three ways — the window, the indexed started_at it is bounded on, and a hard row
     * limit — because this is the one table on the page that grows with every minute cron fires.
     *
     * @param  array<string, Metric>  $readings
     * @return array<string, mixed>
     */
    private function history(string $range, array $readings): array
    {
        $source = 'monitoring_scheduled_runs';

        try {
            $rows = $this->reader->connection()->table('monitoring_scheduled_runs')
                ->where('started_at', '>=', $this->reader->since($range))
                ->orderByDesc('started_at')
                ->limit(self::HISTORY_LIMIT + 1)
                ->get(['task', 'expression', 'status', 'duration_ms', 'error', 'started_at', 'finished_at', 'expected_next_at']);
        } catch (\Throwable $exception) {
            return [
                'state' => 'failed',
                'note' => Metric::describeFailure($exception),
                'remedy' => null,
                'source' => $source,
                'rows' => [],
                'truncated' => false,
                'limit' => self::HISTORY_LIMIT,
            ];
        }

        $truncated = $rows->count() > self::HISTORY_LIMIT;
        $history = [];
        foreach ($rows->take(self::HISTORY_LIMIT) as $row) {
            $history[] = [
                'task' => (string) $row->task,
                'expression' => $row->expression === null ? null : (string) $row->expression,
                'status' => (string) $row->status,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                // Already redacted and truncated where it was written; only the first line is shown,
                // because a scheduled task's error is frequently a whole paragraph of exception.
                'error' => $this->firstLine($row->error),
                'started_at' => $this->displayStamp($row->started_at),
                'finished_at' => $this->displayStamp($row->finished_at),
                'expected_next_at' => $this->displayStamp($row->expected_next_at),
            ];
        }

        return [
            'state' => $history === [] ? 'no_data' : 'ok',
            'note' => $history === [] ? $this->whyNoHistory($readings) : null,
            'remedy' => $history === [] ? $this->historyRemedy($readings) : null,
            'source' => $source,
            'rows' => $history,
            'truncated' => $truncated,
            'limit' => self::HISTORY_LIMIT,
        ];
    }

    /**
     * Why the history is empty, which is three different situations.
     *
     * "Cron has never fired" and "cron is fine, this window is just quiet" draw the same empty
     * table and lead to opposite conclusions, so the reason is read from the newest run that
     * exists rather than left to the operator to guess.
     *
     * @param  array<string, Metric>  $readings
     */
    private function whyNoHistory(array $readings): string
    {
        if (!config('monitoring.enabled', true)) {
            return 'Monitoring collection is switched off, so no scheduled run has been recorded since it was disabled — this is not a reading of zero runs.';
        }

        $lastRun = $readings['last_run_at'] ?? null;

        if (!$lastRun instanceof Metric || !$lastRun->isOk()) {
            return 'No scheduled task has ever reported a run on this deployment, in this window or any other.';
        }

        return 'No scheduled task ran inside this window. The most recent run on record is '
            . $this->displayStamp($lastRun->value) . ' (' . Clock::displayTimezone() . ').';
    }

    /** @param array<string, Metric> $readings */
    private function historyRemedy(array $readings): ?string
    {
        $installed = $readings['cron_installed'] ?? null;

        // The cron remedy is the only useful one when cron is the reason. When cron is fine, a
        // quiet window is a fact rather than a fault, and offering a fix for it would invent one.
        return $installed instanceof Metric && !$installed->isOk() ? $installed->remedy : null;
    }

    // -------------------------------------------------------------------------------------------
    // Reading a cron expression out loud

    /**
     * A cron expression in words, or null when it cannot be said exactly.
     *
     * Null rather than an approximation. `0 2 * * *` is worth spelling out because "every day at
     * 02:00" is what an operator checks against their expectation; a mangled rendering of an
     * expression this method does not fully understand would be worse than the raw five fields,
     * which are always shown beside it.
     */
    private function humanExpression(string $expression): ?string
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        $macros = [
            '@yearly' => 'once a year, on 1 January at 00:00',
            '@annually' => 'once a year, on 1 January at 00:00',
            '@monthly' => 'on the 1st of every month at 00:00',
            '@weekly' => 'every Sunday at 00:00',
            '@daily' => 'every day at 00:00',
            '@midnight' => 'every day at 00:00',
            '@hourly' => 'every hour, on the hour',
        ];
        $macro = $macros[strtolower($expression)] ?? null;
        if ($macro !== null) {
            return $macro;
        }

        $fields = preg_split('/\s+/', $expression) ?: [];
        if (count($fields) !== 5) {
            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $time = $this->humanTime($minute, $hour);
        if ($time === null) {
            return null;
        }

        $days = $this->humanDays($dayOfMonth, $month, $dayOfWeek);
        if ($days === null) {
            return null;
        }

        // A clock time alone does not say how often. Unrestricted, it is every day; restricted, the
        // restriction is what the sentence has to open with — "on Sunday at 04:00", not the reverse.
        if (!str_starts_with($time, 'at ')) {
            return trim($time . ' ' . $days);
        }

        return $days === '' ? 'every day ' . $time : $days . ' ' . $time;
    }

    private function humanTime(string $minute, string $hour): ?string
    {
        $minuteStep = $this->step($minute);
        $hourStep = $this->step($hour);

        if ($minute === '*' && $hour === '*') {
            return 'every minute';
        }
        if ($minuteStep !== null && $hour === '*') {
            return 'every ' . $minuteStep . ' minutes';
        }
        if ($this->isNumber($minute) && $hour === '*') {
            return 'every hour at :' . str_pad($minute, 2, '0', STR_PAD_LEFT);
        }
        if ($this->isNumber($minute) && $hourStep !== null) {
            return 'every ' . $hourStep . ' hours at :' . str_pad($minute, 2, '0', STR_PAD_LEFT);
        }
        if ($this->isNumber($minute) && $this->isNumber($hour)) {
            return 'at ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minute, 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /** The day restriction in words, an empty string when there is none, null when unreadable. */
    private function humanDays(string $dayOfMonth, string $month, string $dayOfWeek): ?string
    {
        $parts = [];

        if ($dayOfWeek !== '*' && $dayOfWeek !== '?') {
            $names = $this->weekdayNames($dayOfWeek);
            if ($names === null) {
                return null;
            }
            $parts[] = 'on ' . $names;
        }

        if ($dayOfMonth !== '*' && $dayOfMonth !== '?') {
            if (!$this->isNumber($dayOfMonth)) {
                return null;
            }
            $parts[] = 'on day ' . (int) $dayOfMonth . ' of the month';
        }

        if ($month !== '*') {
            if (!$this->isNumber($month) || (int) $month < 1 || (int) $month > 12) {
                return null;
            }
            $parts[] = 'in ' . self::MONTH_NAMES[(int) $month];
        }

        return implode(', ', $parts);
    }

    /** @return string|null the named weekdays, or null when the field is more than a list of days */
    private function weekdayNames(string $field): ?string
    {
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $days = [];

        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d)-(\d)$/', $part, $matches) === 1) {
                for ($day = (int) $matches[1]; $day <= (int) $matches[2]; $day++) {
                    $days[] = $names[$day % 7] ?? null;
                }

                continue;
            }
            if (!$this->isNumber($part) || (int) $part > 7) {
                return null;
            }
            $days[] = $names[(int) $part % 7];
        }

        $days = array_values(array_unique(array_filter($days)));

        return $days === [] ? null : implode(', ', $days);
    }

    private function isNumber(string $field): bool
    {
        return preg_match('/^\d+$/', $field) === 1;
    }

    /** The N of a step field (every Nth minute or hour), or null when the field is not a plain step. */
    private function step(string $field): ?int
    {
        return preg_match('#^\*/(\d+)$#', $field, $matches) === 1 ? (int) $matches[1] : null;
    }

    // -------------------------------------------------------------------------------------------

    /**
     * A stored UTC stamp, in the timezone the dashboard renders in.
     *
     * Every timestamp on this page passes through here. Printing a stored value directly would put
     * a run three hours away from the deploy it followed on a deployment whose display timezone is
     * not UTC, which is the exact class of bug the Clock exists to prevent.
     */
    private function displayStamp(mixed $stored): ?string
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return Clock::display($stored)->toDateTimeString();
        } catch (\Throwable) {
            // An unparseable stamp is shown as stored rather than dropped: the run really happened,
            // and inventing a time for it would be worse than showing the raw value.
            return is_scalar($stored) ? (string) $stored : null;
        }
    }

    private function minutesSince(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Clock::parse($stored)->diffInMinutes(Clock::now());
        } catch (\Throwable) {
            return null;
        }
    }

    private function minutesUntil(mixed $stored): ?int
    {
        if ($stored === null || (is_string($stored) && trim($stored) === '')) {
            return null;
        }

        try {
            return (int) Clock::now()->diffInMinutes(Clock::parse($stored));
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstLine(mixed $text): ?string
    {
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        return trim(strtok(trim($text), "\n") ?: '') ?: null;
    }

    /**
     * Readings this page draws nowhere.
     *
     * Normally empty. It exists so a collector that grows a reading cannot have it silently
     * disappear: an undrawn measurement is indistinguishable from an unmeasured one.
     *
     * @param  array<string, Metric>  $readings
     * @return array<int, array{metric: string, state: string}>
     */
    private function unrendered(array $readings): array
    {
        $claimed = array_merge(self::HEADLINE, ['cron_installed', 'last_run_at', 'tasks', '__collector']);

        $unrendered = [];
        foreach ($readings as $name => $metric) {
            if (in_array($name, $claimed, true) || !$metric instanceof Metric) {
                continue;
            }

            $unrendered[] = ['metric' => $name, 'state' => $metric->state];
        }

        return $unrendered;
    }
}
