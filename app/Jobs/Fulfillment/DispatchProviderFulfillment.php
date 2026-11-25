<?php

namespace App\Jobs\Fulfillment;

use App\Jobs\Concerns\CarriesItsStorefront;
use App\Jobs\Concerns\MarketOfTheWork;
use App\Jobs\Middleware\ServeInItsStorefront;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\Fulfillment\Support\PiiRedactor;

/**
 * Sends one provider fulfillment. A job never retries itself: `tries = 1`. Whether the request may be
 * repeated is decided by the fulfillment's own state (retry_wait / reconcile_pending) and the scheduled
 * recovery sweep, because blindly re-running a job whose HTTP call timed out would be a blind re-send.
 */
class DispatchProviderFulfillment implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, KeepsPersonalDataOutOfFailures, OneAtATimePerFulfillment, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $fulfillmentId, public readonly bool $manual = false)
    {
        $this->carryTheCurrentStorefront();
    }

    public function handle(ProviderOrderDispatcher $dispatcher): void
    {
        $this->withoutPersonalDataInFailures(fn () => $dispatcher->dispatch(fulfillmentId: $this->fulfillmentId, manual: $this->manual));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Provider fulfillment dispatch job crashed; recovery will reconcile it.', [
            'fulfillment_id' => $this->fulfillmentId,
            'error' => PiiRedactor::scrub($exception->getMessage()),
        ]);
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
