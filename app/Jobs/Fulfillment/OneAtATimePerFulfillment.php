<?php

namespace App\Jobs\Fulfillment;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Dispatching, reconciling and cancelling the same shipment must never run side by side. The lock is `shared()`:
 * without it Laravel adds the job's class name to the key, and the three job classes would each hold their own lock
 * on the same shipment — a cancel could then race a send that is still in flight.
 */
trait OneAtATimePerFulfillment
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('provider-fulfillment-' . $this->fulfillmentId))->shared()->dontRelease()->expireAfter(180)];
    }
}
