<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\InventoryReservation;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelEvent;
use Illuminate\Console\Command;

class SalesChannelsPruneCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:prune {--days= : keep this many days of what channels sent us (default from config)}';

    protected $description = 'Delete old sales channel API call logs and the raw text of old webhook deliveries (the orders and costs they led to are kept)';

    public function handle(): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: config('sales_channels.logs.retention_days', 60));
        $before = now()->subDays($days);

        $logs = SalesChannelApiLog::where('created_at', '<', $before)->delete();
        // The delivery stays as a row (it is what makes a repeated delivery harmless); the order package it carried does not. Statement postings are kept whole: the fee
        // of an order is netted from them.
        $payloads = SalesChannelEvent::whereIn('source', ['webhook', 'notification'])->where('created_at', '<', $before)->whereNotNull('payload')->update(['payload' => null]);

        $this->info("Deleted {$logs} API log row(s) and cleared {$payloads} stored webhook payload(s), all older than {$days} days.");

        // A released reservation counts for nothing and never comes back; it is kept for a while so that what an order held can still be looked at.
        $reservationDays = (int) config('sales_channels.stock.allocation.released_reservation_days');
        $reservations = InventoryReservation::where('state', InventoryReservation::RELEASED)->where('released_at', '<', now()->subDays($reservationDays))->delete();
        $this->info("Deleted {$reservations} released stock reservation(s) older than {$reservationDays} days.");

        return self::SUCCESS;
    }
}
