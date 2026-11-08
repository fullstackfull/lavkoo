<?php

namespace App\Jobs\SalesChannels;

use App\Services\SalesChannels\Inventory\Outbox\StockEventDrain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Works the outbox as soon as something is written to it, instead of waiting for the next scheduled run.
 *
 * It carries NO event: it looks for whatever is due. That is deliberate. A job that named its rows would have to be dispatched inside the transaction that wrote them (and so
 * could run against a database that had not committed yet) or after it (and so could be lost in the gap). Naming nothing, the job is only a nudge: if it never arrives, or
 * arrives twice, or arrives before the commit lands, the rows are still there and the next nudge — or the scheduled `channels:stock-events` — works them. The TABLE is the
 * guarantee; the job is the speed.
 *
 * `tries = 1`: a job never retries itself. Whether an event may be worked again is the event's own business (its attempts, its next time, and the owner's limit), because a
 * blind re-run of a job whose publish call timed out would be a blind re-send to a channel.
 *
 * One at a time: two drains at once would claim different batches and each hand its pools to the publisher, which takes the account's stock lease — so the second would find
 * the lease held and leave its pools for later. Correct, but wasteful; the lock keeps it to one.
 */
class DrainStockEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $limit = 200)
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sales-channels:stock-events'))->dontRelease()->expireAfter(600)];
    }

    public function handle(StockEventDrain $drain): void
    {
        $drain->drain($this->limit);
    }

    public function failed(Throwable $exception): void
    {
        // Only the kind of failure: what a channel says about a product can repeat the whole payload back. The events themselves are still in the table.
        Log::warning('The stock-event drain job crashed; the events stay for the next run.', ['error' => $exception::class]);
    }
}
