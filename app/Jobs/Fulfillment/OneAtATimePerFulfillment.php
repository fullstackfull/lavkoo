<?php

namespace App\Jobs\Fulfillment;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Dispatching, reconciling and cancelling the same shipment must never run side by side. The lock is `shared()`:
 * without it Laravel adds the job's class name to the key, and the three job classes would each hold their own lock
 * on the same shipment — a cancel could then race a send that is still in flight.
 *
 * It hands back the one middleware rather than a `middleware()` list, because these jobs now need a second one — the market of the shipment
 * ({@see \App\Jobs\Concerns\CarriesItsStorefront}) — and two traits cannot both answer `middleware()`: PHP refuses that at class load, which
 * is a fatal error and not a failing test. So each job composes its own list and puts the lock first, where
 * `CancellationTest::test_dispatch_reconcile_and_cancel_jobs_share_one_lock_per_shipment` reads it.
 */
trait OneAtATimePerFulfillment
{
    protected function lockOnThisShipment(): WithoutOverlapping
    {
        return (new WithoutOverlapping('provider-fulfillment-' . $this->fulfillmentId))->shared()->dontRelease()->expireAfter(180);
    }
}
