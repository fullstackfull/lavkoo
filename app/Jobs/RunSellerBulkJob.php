<?php

namespace App\Jobs;

use App\Models\SellerBulkJob;
use App\Services\Marketplace\Bulk\SellerBulkJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one bulk operation off the request thread.
 *
 * Only the job's id travels on the queue, not the job model and not the principal: the runner reads
 * the current state of both when it starts, so a shop suspended or a permission revoked while the
 * work waited is honoured rather than frozen at the moment of queueing.
 *
 * Not retried. A retry would re-apply the rows that already succeeded — harmless for `set`, wrong
 * for `increase`, which would raise the same prices twice. A failed run leaves a receipt saying how
 * far it got, and the seller decides what to do with the remainder.
 */
class RunSellerBulkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(private readonly int $bulkJobId)
    {
    }

    public function handle(SellerBulkJobService $service): void
    {
        $job = SellerBulkJob::find($this->bulkJobId);

        if (!$job || $job->isFinished()) {
            return;
        }

        $service->run($job);
    }

    /** The receipt has to say the run died, rather than sitting at `processing` forever. */
    public function failed(Throwable $exception): void
    {
        SellerBulkJob::where('id', $this->bulkJobId)
            ->whereIn('status', SellerBulkJob::OPEN_STATUSES)
            ->update([
                'status' => SellerBulkJob::STATUS_FAILED,
                'error' => $exception->getMessage(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
