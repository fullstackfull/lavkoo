<?php

namespace App\Jobs;

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
 */
class DeliverSellerWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $deliveryId)
    {
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
