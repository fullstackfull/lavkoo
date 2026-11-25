<?php

namespace App\Jobs\Fulfillment;

use App\Jobs\Concerns\CarriesItsStorefront;
use App\Jobs\Concerns\MarketOfTheWork;
use App\Jobs\Middleware\ServeInItsStorefront;
use App\Services\Fulfillment\FulfillmentCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CancelProviderFulfillment implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, KeepsPersonalDataOutOfFailures, OneAtATimePerFulfillment, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $fulfillmentId, public readonly bool $manual = false)
    {
        $this->carryTheCurrentStorefront();
    }

    public function handle(FulfillmentCancellation $cancellation): void
    {
        $this->withoutPersonalDataInFailures(fn () => $cancellation->cancel(fulfillmentId: $this->fulfillmentId, manual: $this->manual));
    }

    /**
     * The lock and the market, composed here because a job's own `middleware()` replaces the trait's: the shared shipment lock first, where
     * the cancellation test reads it, and then the market the shipment's ORDER was sold in.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [$this->lockOnThisShipment(), new ServeInItsStorefront(country: $this->storefrontToServe())];
    }

    /**
     * The market of the order this shipment belongs to, and the dispatching request's only when the record cannot be read: a shipment is
     * queued by the fulfilment machinery, by the recovery sweep or from a panel, none of which is serving the shipment's market.
     */
    protected function storefrontToServe(): ?string
    {
        return MarketOfTheWork::ofFulfillment(fulfillmentId: $this->fulfillmentId) ?? $this->storefrontCountry;
    }
}
