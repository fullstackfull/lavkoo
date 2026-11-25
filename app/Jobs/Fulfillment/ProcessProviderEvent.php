<?php

namespace App\Jobs\Fulfillment;

use App\Jobs\Concerns\CarriesItsStorefront;
use App\Jobs\Concerns\MarketOfTheWork;
use App\Services\Fulfillment\WebhookEventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies one stored inbound event. Safe to run twice: the processor refuses an event it already
 * finished, and the state machine refuses a status that is stale or terminal.
 */
class ProcessProviderEvent implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, KeepsPersonalDataOutOfFailures, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $eventId)
    {
        $this->carryTheCurrentStorefront();
    }

    public function handle(WebhookEventProcessor $processor): void
    {
        $this->withoutPersonalDataInFailures(fn () => $processor->process($this->eventId));
    }
    /**
     * The market of the order the event is about. This job is the clearest case for reading the record: it is queued from
     * `api/fulfillment-webhooks`, which is served across every country on purpose, so the dispatching request can say nothing about the
     * market and the storefront it carries is always null. The event names the shipment, the shipment the order, the order the market.
     */
    protected function storefrontToServe(): ?string
    {
        return MarketOfTheWork::ofFulfillmentEvent(eventId: $this->eventId) ?? $this->storefrontCountry;
    }
}
