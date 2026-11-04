<?php

namespace App\Console\Commands;

use App\Jobs\DeliverSellerWebhook;
use App\Models\SellerWebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Re-queue deliveries whose next attempt has come due.
 *
 * The schedule lives on the row rather than in the queue's own delayed jobs, so this sweep is what
 * turns it into work. It also means a delivery is never lost to a worker restart: whatever is
 * pending and due gets picked up on the next pass.
 */
class RetrySellerWebhooks extends Command
{
    protected $signature = 'seller:retry-webhooks {--limit=200 : Deliveries per sweep}';

    protected $description = 'Re-queue seller webhook deliveries that are due for another attempt';

    public function handle(): int
    {
        if (!Schema::hasTable('seller_webhook_deliveries')) {
            return self::SUCCESS;
        }

        $due = SellerWebhookDelivery::where('status', SellerWebhookDelivery::STATUS_PENDING)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        foreach ($due as $id) {
            DeliverSellerWebhook::dispatch($id);
        }

        $this->info(sprintf('%d deliverie(s) re-queued.', $due->count()));

        return self::SUCCESS;
    }
}
