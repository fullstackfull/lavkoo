<?php

namespace App\Jobs;

use App\Jobs\Concerns\CarriesItsStorefront;
use App\Models\SellerWebhookDelivery;
use App\Services\Marketplace\SellerWebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One attempt to deliver one webhook.
 *
 * Only the delivery's id travels on the queue. The retry schedule lives on the row rather than in
 * the queue's own backoff, because the seller has to be able to see it: "next attempt in eight
 * minutes" is something they can read on the screen, and a queue's internal timer is not.
 *
 * A single try per job for the same reason — the sweep re-queues what is still due, so the state a
 * seller reads is the state that governs.
 *
 * It carries the market of the request that queued it ({@see CarriesItsStorefront}) and reads no record for one. The delivery row names a
 * seller rather than a country, and a seller is not country-owned on this platform; the payload it POSTs was built by whoever queued it, so
 * nothing market-dependent is rendered in the worker. What the market is for here is the dispatcher's own reads — the endpoint, the
 * signing secret and the attempt log — and the retry sweep that re-queues a due delivery is a console run that belongs to no market, which
 * is why the answer is null for every delivery the sweep picks up.
 */
class DeliverSellerWebhook implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $deliveryId)
    {
        $this->carryTheCurrentStorefront();
    }

    public function handle(SellerWebhookDispatcher $dispatcher): void
    {
        $delivery = SellerWebhookDelivery::find($this->deliveryId);

        if (!$delivery || $delivery->status !== SellerWebhookDelivery::STATUS_PENDING) {
            return;
        }

        $dispatcher->attempt($delivery);
    }
}
