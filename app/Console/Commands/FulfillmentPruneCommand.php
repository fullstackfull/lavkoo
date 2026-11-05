<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Models\ProviderApiLog;
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

        $this->info("Deleted {$logs} API log row(s), cleared {$payloads} stored provider payload(s) and deleted {$failedJobs} failed job(s), all older than {$days} days.");

        return self::SUCCESS;
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
