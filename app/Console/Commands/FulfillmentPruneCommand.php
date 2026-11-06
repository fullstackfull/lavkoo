<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Models\ProviderApiLog;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FulfillmentPruneCommand extends Command
{
    use SkipsUntilMigrated;

    protected $signature = 'fulfillment:prune {--days= : keep this many days of what a provider sent us (default from config)}';

    protected $description = 'Delete old provider API call logs, and what providers sent that is no longer needed (the fulfillment history itself is kept)';

    public function handle(): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: config('fulfillment.logs.retention_days', 60));
        $before = now()->subDays($days);

        $logs = ProviderApiLog::where('created_at', '<', $before)->delete();
        // The status history stays; the raw text a provider sent with each event (a courier's note, a customer's name) does not.
        $payloads = ProviderFulfillmentEvent::where('received_at', '<', $before)->whereNotNull('payload')->update(['payload' => null]);
        $failedJobs = $this->pruneFailedJobs($before);
        $requests = $this->pruneForgottenDryRuns($before);

        $this->info("Deleted {$logs} API log row(s), cleared {$payloads} stored provider payload(s), {$requests} recorded dry-run request(s) and deleted {$failedJobs} failed job(s), all older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * A request a dry run recorded (receiver, address, note) does not outlive the retention, whatever became of the shipment: a dry run
     * nobody released, and a shipment that left that state some way that should have taken it along.
     */
    private function pruneForgottenDryRuns(\DateTimeInterface $before): int
    {
        $recorded = array_flip(['payload_preview', 'payload_hash']);
        $cleared = 0;

        ProviderFulfillment::where('updated_at', '<', $before)
            ->where(static fn ($query) => $query->whereNotNull('meta->payload_preview')->orWhereNotNull('meta->payload_hash'))
            ->chunkById(100, function ($shipments) use ($recorded, &$cleared): void {
                foreach ($shipments as $shipment) {
                    $meta = (array) $shipment->meta;
                    if (array_intersect_key($meta, $recorded) === []) {
                        continue;
                    }

                    $shipment->timestamps = false;
                    $shipment->forceFill(['meta' => array_diff_key($meta, $recorded)])->saveQuietly();
                    $cleared++;
                }
            });

        return $cleared;
    }

    /** Only the fulfillment queue's: the platform's other failed jobs are not ours to delete. */
    private function pruneFailedJobs(\DateTimeInterface $before): int
    {
        if (!Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')->where('queue', (string) config('fulfillment.queue.name', 'fulfillment'))->where('failed_at', '<', $before)->delete();
    }
}
