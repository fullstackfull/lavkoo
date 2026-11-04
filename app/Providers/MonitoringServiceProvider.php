<?php

namespace App\Providers;

use App\Services\Monitoring\EventLog;
use App\Services\Monitoring\Ingest\BucketWriter;
use App\Services\Monitoring\Ingest\DependencyRecorder;
use App\Services\Monitoring\Ingest\MetricSink;
use App\Services\Monitoring\Ingest\RequestContext;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\Redactor;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Wires monitoring into the framework's own events.
 *
 * Everything here is a listener on something Laravel already emits, which is deliberate: the
 * alternative is editing dozens of call sites to add instrumentation, and instrumentation that has
 * to be remembered is instrumentation that gets forgotten in the one new payment gateway that
 * matters.
 *
 * Every listener is individually guarded. Monitoring is never allowed to be the reason a scheduled
 * task, a queued job or an HTTP call fails.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Redactor::class, fn () => Redactor::make());
        $this->app->singleton(MetricSink::class);
        $this->app->singleton(BucketWriter::class);
    }

    public function boot(): void
    {
        if (!config('monitoring.enabled', true)) {
            return;
        }

        $this->recordScheduledTasks();
        $this->recordQueuedJobs();
        $this->recordOutboundCalls();
        $this->tagLogsWithCorrelationId();
    }

    /**
     * The real scheduler heartbeat.
     *
     * One row per task per run, with its duration and its outcome. This is what makes "which
     * scheduled task stopped working" answerable — the previous single `scheduler_last_run_at`
     * timestamp could only say whether cron itself had fired, and stayed green while a task inside
     * the schedule failed every night for a week.
     */
    private function recordScheduledTasks(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $this->safely(function () use ($event) {
                $id = $this->monitoring()->table('monitoring_scheduled_runs')->insertGetId([
                    'task' => $this->taskName($event->task),
                    'expression' => Str::limit((string) $event->task->expression, 38, ''),
                    'status' => 'running',
                    'started_at' => Clock::stamp(),
                    'expected_next_at' => $this->nextDue((string) $event->task->expression),
                ]);
                // Carried on the event's own task object so the finish listener can find the row
                // without a second lookup by name — two tasks can share a name across schedules.
                $event->task->monitoringRunId = $id;
            });
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $this->safely(function () use ($event) {
                $exitCode = $event->task->exitCode ?? 0;
                $this->finishRun($event->task, $exitCode === 0 ? 'success' : 'failed', $event->runtime, [
                    'output' => $this->taskOutput($event->task),
                    'error' => $exitCode === 0 ? null : 'Exited with code ' . $exitCode,
                ]);
            });
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $this->safely(function () use ($event) {
                $this->finishRun($event->task, 'failed', null, [
                    'error' => app(Redactor::class)->text(Str::limit($event->exception->getMessage(), 900, '')),
                ]);
                $name = $this->taskName($event->task);
                app(EventLog::class)->record(
                    type: EventLog::SCHEDULER,
                    severity: EventLog::CRITICAL,
                    title: 'Scheduled task failed: ' . $name,
                    key: $name,
                    description: app(Redactor::class)->text(Str::limit($event->exception->getMessage(), 500, '')),
                    context: ['exception' => class_basename($event->exception)],
                );
            });
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            $this->safely(fn () => $this->finishRun($event->task, 'skipped', null, []));
        });
    }

    /**
     * Queue throughput and failures, measured where the worker actually runs.
     *
     * Counting pending rows in the jobs table says how much work is waiting; only the worker knows
     * how long a job took and whether it succeeded, which is what a throughput or a p95 runtime
     * needs.
     */
    private function recordQueuedJobs(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $this->safely(function () use ($event) {
                $event->job->monitoringStartedAt = microtime(true);
            });
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            $this->safely(function () use ($event) {
                $startedAt = $event->job->monitoringStartedAt ?? null;
                if ($startedAt === null) {
                    return;
                }
                $this->recordJobOutcome($event->job->getQueue() ?: 'default', $event->job->resolveName(), (microtime(true) - $startedAt) * 1000, false);
            });
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->safely(function () use ($event) {
                $startedAt = $event->job->monitoringStartedAt ?? null;
                $this->recordJobOutcome(
                    $event->job->getQueue() ?: 'default',
                    $event->job->resolveName(),
                    $startedAt === null ? 0.0 : (microtime(true) - $startedAt) * 1000,
                    true,
                );
            });
        });
    }

    private function recordJobOutcome(string $queue, string $jobClass, float $durationMs, bool $failed): void
    {
        $sink = app(MetricSink::class);
        $bucket = BucketWriter::SERIES_PREFIX . 'queue.processed|' . Str::limit($queue, 90, '');

        $sink->increment($bucket, 'n');
        $sink->increment($bucket, 'sum', (int) round($durationMs));
        if ($failed) {
            $sink->increment(BucketWriter::SERIES_PREFIX . 'queue.failed|' . Str::limit($queue, 90, ''), 'n');
        }
        $sink->flush(intdiv(time(), 60) * 60);
    }

    /**
     * Outbound calls, measured where the client already is.
     *
     * One registration on Laravel's HTTP client instead of an edit at each of its 23 call sites —
     * and, more to the point, one registration that a fifteenth payment gateway is covered by
     * without anybody remembering to instrument it. It is the raw Guzzle middleware on purpose: the
     * request-only and response-only forms cannot see each other, and a duration needs both ends of
     * the same call.
     *
     * It leaves most of this shop unmeasured — the curl gateways, the vendor SDKs and the mailer
     * all have their own transport — so the recorder announces which transport it covers as well as
     * what it saw, and the integrations page can tell a service nobody called from a service
     * nothing can measure.
     */
    private function recordOutboundCalls(): void
    {
        $this->safely(function () {
            $recorder = $this->app->make(DependencyRecorder::class);

            Http::globalMiddleware(fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler, $recorder) {
                $startedAt = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($recorder, $request, $startedAt) {
                        $recorder->record(request: $request, response: $response, failure: null, startedAt: $startedAt);

                        return $response;
                    },
                    function (mixed $reason) use ($recorder, $request, $startedAt) {
                        // A connection that never answered has no response; one that answered badly
                        // enough to be thrown still carries its status, and losing it would file a
                        // 502 as an unexplained failure.
                        $recorder->record(
                            request: $request,
                            response: $reason instanceof RequestException ? $reason->getResponse() : null,
                            failure: $reason instanceof \Throwable ? $reason : null,
                            startedAt: $startedAt,
                        );

                        return Create::rejectionFor($reason);
                    },
                );
            });

            $recorder->announceTransport();
        });
    }

    /**
     * Put the correlation id on every log line written during a request.
     *
     * This is what turns the log viewer from a wall of text into something you can pivot: one
     * request id, and every line it produced — across the controller, the jobs it dispatched and
     * the gateway call it made — comes back together.
     */
    private function tagLogsWithCorrelationId(): void
    {
        $this->safely(function () {
            Log::shareContext([]);   // ensure the context stack exists before anything writes

            $this->app->booted(function () {
                $this->safely(function () {
                    if (!$this->app->bound(RequestContext::class)) {
                        return;
                    }
                    $context = $this->app->make(RequestContext::class);
                    Log::shareContext([
                        'correlation_id' => $context->correlationId,
                        'request_id' => $context->requestId,
                    ]);
                });
            });
        });
    }

    private function finishRun(object $task, string $status, ?float $runtime, array $extra): void
    {
        $id = $task->monitoringRunId ?? null;
        $update = array_filter([
            'status' => $status,
            'duration_ms' => $runtime !== null ? (int) round($runtime * 1000) : null,
            'finished_at' => Clock::stamp(),
            'output' => isset($extra['output']) ? Str::limit((string) $extra['output'], 2000, '') : null,
            'error' => $extra['error'] ?? null,
        ], static fn ($value) => $value !== null);

        if ($id !== null) {
            $this->monitoring()->table('monitoring_scheduled_runs')->where('id', $id)->update($update);

            return;
        }

        // No starting event was seen (a task registered after boot, or a listener that missed it):
        // record the finish anyway rather than losing the fact that it ran.
        $this->monitoring()->table('monitoring_scheduled_runs')->insert(array_merge([
            'task' => $this->taskName($task),
            'expression' => Str::limit((string) $task->expression, 38, ''),
            'started_at' => Clock::stamp(),
        ], $update));
    }

    private function taskName(object $task): string
    {
        $command = (string) ($task->command ?? '');
        if ($command !== '') {
            $command = preg_replace("/^.*'artisan'\s*/", '', $command) ?? $command;

            return Str::limit(trim(str_replace("'", '', $command)), 185, '');
        }

        return Str::limit((string) ($task->description ?: 'closure@' . substr(sha1((string) $task->expression), 0, 8)), 185, '');
    }

    private function taskOutput(object $task): ?string
    {
        $path = $task->output ?? null;
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return null;
        }

        // Task output can contain anything the command printed, including values from the shop.
        return app(Redactor::class)->text(Str::limit((string) @file_get_contents($path), 2000, ''));
    }

    private function nextDue(string $expression): ?string
    {
        try {
            return Clock::stamp(Carbon::instance((new \Cron\CronExpression($expression))->getNextRunDate(Carbon::now(config('app.timezone', 'UTC')))));
        } catch (\Throwable) {
            return null;
        }
    }

    private function monitoring(): \Illuminate\Database\Connection
    {
        return DB::connection(config('monitoring.connection', 'monitoring'));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Monitoring never breaks the thing it is monitoring.
        }
    }
}
