<?php

namespace App\Jobs\Fulfillment;

use App\Services\Fulfillment\FulfillmentCancellation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CancelProviderFulfillment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, KeepsPersonalDataOutOfFailures, OneAtATimePerFulfillment, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $fulfillmentId, public readonly bool $manual = false)
    {
    }

    public function handle(FulfillmentCancellation $cancellation): void
    {
        $this->withoutPersonalDataInFailures(fn () => $cancellation->cancel(fulfillmentId: $this->fulfillmentId, manual: $this->manual));
    }
}
