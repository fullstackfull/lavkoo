<?php

namespace App\Console\Commands;

use App\Models\SellerBulkJob;
use App\Services\Marketplace\Bulk\SellerBulkJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Runs bulk jobs the queue never picked up.
 *
 * Bulk work is dispatched to the queue, which is right: a seller changing four hundred prices should
 * not hold a request open. But it makes the feature depend on a worker actually running, and a
 * deployment without one would leave every bulk job sitting at `queued` for ever while the app shows
 * a seller a change that is silently never going to happen. That is precisely the failure this whole
 * feature exists to prevent.
 *
 * So the scheduler sweeps for jobs that have waited longer than a worker would plausibly take and
 * runs them here. Where a worker does exist this finds nothing, because the job is finished long
 * before the grace period is up.
 */
class RunStuckSellerBulkJobs extends Command
{
    protected $signature = 'seller:run-stuck-bulk-jobs {--minutes=2 : How long a job may wait before this runs it}
                                                       {--limit=5 : How many to run in one sweep}';

    protected $description = 'Run seller bulk jobs the queue has not picked up';

    public function handle(SellerBulkJobService $bulkJobs): int
    {
        if (!Schema::hasTable('seller_bulk_jobs')) {
            return self::SUCCESS;
        }

        $waited = now()->subMinutes(max(1, (int) $this->option('minutes')));

        $stuck = SellerBulkJob::where('status', SellerBulkJob::STATUS_QUEUED)
            ->where('created_at', '<=', $waited)
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($stuck as $job) {
            $this->info("Running bulk job {$job->id} ({$job->type}) the queue did not pick up.");
            $bulkJobs->run($job);
        }

        return self::SUCCESS;
    }
}
