<?php

namespace App\Services\SalesChannels\Inventory\Outbox;

use App\Jobs\SalesChannels\DrainStockEvents;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Asks for the outbox to be worked now, rather than at the next scheduled run.
 *
 * It is a NUDGE and nothing more. The table is the guarantee; this is the speed. So every way it can go wrong is harmless: a nudge lost in a restart, a queue nobody is
 * working, a nudge that arrives before the transaction commits — in each case the rows are still there and `channels:stock-events` works them on its next pass.
 *
 * Three things it is careful about:
 *
 *  - AFTER THE COMMIT, always (`afterCommit`). A job that started inside the transaction would read the database as it was and find nothing to do.
 *  - NOT SIXTY JOBS A MINUTE. An order import that changes four hundred orders would otherwise ask four hundred times for a drain that takes two hundred events at a go. One
 *    nudge per `nudge_seconds` is enough, because a drain takes everything that is due, not the one row that nudged it. The rest is left to the drain itself and to the
 *    schedule.
 *  - NEVER INSIDE THE REQUEST. On a host whose queue connection is `sync` there is no worker: Laravel runs a dispatched job there and then. A channel order arriving by
 *    webhook would then work the whole outbox — divide pools and push quantities to a marketplace — inside that webhook's own request, which would make answering the channel
 *    depend on the channel answering us, and would turn one slow marketplace into a queue of timing-out webhooks. Nothing is nudged on such a host; the minute schedule does
 *    the work, and only the seconds are lost.
 */
class StockEventNudge
{
    private const RECENTLY_ASKED = 'sales_channels:stock-events:nudged';

    public function soon(): void
    {
        if (!(bool) config('sales_channels.stock.outbox.nudge_enabled', true)) {
            return;
        }
        if ($this->wouldRunInsideThisRequest()) {
            return;
        }
        $seconds = max(0, (int) config('sales_channels.stock.outbox.nudge_seconds', 5));
        if ($seconds > 0 && !Cache::add(self::RECENTLY_ASKED, true, $seconds)) {
            // One was asked for a moment ago; it will take this change too.
            return;
        }

        DrainStockEvents::dispatch()->afterCommit();
    }

    /**
     * Would a dispatched job run here and now rather than in a worker? It is asked of the CONNECTION, not of the configured name, so that a test which collects jobs
     * (`Queue::fake()`) still sees the nudge while a host with a `sync` queue is left alone.
     */
    private function wouldRunInsideThisRequest(): bool
    {
        try {
            return Queue::connection() instanceof SyncQueue;
        } catch (Throwable) {
            // No queue to speak of: the schedule does the work.
            return true;
        }
    }
}
