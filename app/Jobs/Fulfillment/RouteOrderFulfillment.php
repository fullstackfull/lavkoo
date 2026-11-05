<?php

namespace App\Jobs\Fulfillment;

use App\Services\Fulfillment\FulfillmentRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Re-evaluates one order after its status or payment changed. Idempotent: routing an order twice creates nothing twice. */
class RouteOrderFulfillment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(FulfillmentRouter $router): void
    {
        $router->route($this->orderId);
    }
}
