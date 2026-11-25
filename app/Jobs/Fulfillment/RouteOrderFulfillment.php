<?php

namespace App\Jobs\Fulfillment;

use App\Jobs\Concerns\CarriesItsStorefront;
use App\Jobs\Concerns\MarketOfTheWork;
use App\Services\Fulfillment\FulfillmentRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Re-evaluates one order after its status or payment changed. Idempotent: routing an order twice creates nothing twice. */
class RouteOrderFulfillment implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, KeepsPersonalDataOutOfFailures, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId)
    {
        $this->carryTheCurrentStorefront();
    }

    public function handle(FulfillmentRouter $router): void
    {
        $this->withoutPersonalDataInFailures(fn () => $router->route($this->orderId));
    }
    /**
     * The market the order was sold in, and the dispatching request's only when the order records none. Routing is queued from the checkout
     * hook, from a status change in a panel and from the recovery sweep: the order knows its market in all three, and a panel knows none.
     */
    protected function storefrontToServe(): ?string
    {
        return MarketOfTheWork::ofOrder(orderId: $this->orderId) ?? $this->storefrontCountry;
    }
}
