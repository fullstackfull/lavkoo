<?php

namespace App\Services\Monitoring;

use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\App;

/**
 * Putting a failed job back on the queue, from the page that shows it failed.
 *
 * Monitoring is otherwise read-only, deliberately: a dashboard that can change things is a
 * dashboard somebody breaks production from. This is the one exception the product asks for, and it
 * is narrow on purpose — a failed job can be sent again or thrown away, and nothing else here can
 * be touched.
 *
 * The argument for the exception is that the alternative is worse. An order confirmation that
 * failed at two in the morning is visible on this page and repairable only from a shell, so the
 * person who can see the problem is never the person who can fix it.
 *
 * Everything goes through Laravel's own failed-job store rather than shelling out to `queue:retry`:
 * the store is the same one the worker writes to, it is already configured for whichever driver
 * this install uses, and running artisan from a web request would inherit the request's memory
 * limit and timeout.
 */
class FailedJobs
{
    public function __construct(
        private readonly FailedJobProviderInterface $failed,
        private readonly QueueFactory $queue,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Put one failed job back on its queue.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function retry(string $uuid): array
    {
        $job = $this->find($uuid);

        if ($job === null) {
            return ['ok' => false, 'reason' => 'that_job_is_no_longer_in_the_failed_list'];
        }

        // Pushed back exactly as it was recorded, onto the connection and queue it came from — a
        // retry that lands on a different queue is a different job.
        $this->queue->connection($job->connection)->pushRaw($job->payload, $job->queue);
        $this->failed->forget($uuid);

        $this->audit->record(
            action: 'monitoring.failed_job_retried',
            subject: ['type' => 'failed_job', 'id' => $uuid],
            after: ['connection' => $job->connection, 'queue' => $job->queue],
        );

        return ['ok' => true];
    }

    /**
     * Drop one failed job without running it again.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function forget(string $uuid): array
    {
        if ($this->find($uuid) === null) {
            return ['ok' => false, 'reason' => 'that_job_is_no_longer_in_the_failed_list'];
        }

        $this->audit->record(
            action: 'monitoring.failed_job_discarded',
            subject: ['type' => 'failed_job', 'id' => $uuid],
        );

        $this->failed->forget($uuid);

        return ['ok' => true];
    }

    /**
     * The recorded failure, or null when it is already gone.
     *
     * Two operators looking at the same page is the ordinary case, so a job that somebody else has
     * just retried is a message rather than an error.
     */
    private function find(string $uuid): ?object
    {
        if (trim($uuid) === '') {
            return null;
        }

        $job = $this->failed->find($uuid);

        return is_object($job) && isset($job->payload) ? $job : null;
    }

    /** Whether this install can retry at all — some failed-job drivers only count. */
    public static function isSupported(): bool
    {
        return App::make(FailedJobProviderInterface::class) instanceof FailedJobProviderInterface
            && method_exists(App::make(FailedJobProviderInterface::class), 'find');
    }
}
