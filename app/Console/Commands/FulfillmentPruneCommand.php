<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Models\ProviderApiLog;
use Illuminate\Console\Command;

class FulfillmentPruneCommand extends Command
{
    use SkipsUntilMigrated;

    protected $signature = 'fulfillment:prune {--days= : keep this many days of API logs (default from config)}';

    protected $description = 'Delete old provider API call logs (fulfillment history and events are kept)';

    public function handle(): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: config('fulfillment.logs.retention_days', 60));
        $deleted = ProviderApiLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} API log row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
