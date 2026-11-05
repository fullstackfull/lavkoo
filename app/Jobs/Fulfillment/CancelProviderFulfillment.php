<?php

namespace App\Jobs\Fulfillment;

use App\Services\Fulfillment\FulfillmentCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class CancelProviderFulfillment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $fulfillmentId)
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('provider-fulfillment-' . $this->fulfillmentId))->dontRelease()->expireAfter(180)];
    }

    public function handle(FulfillmentCancellation $cancellation): void
    {
        $cancellation->cancel($this->fulfillmentId);
    }
}
