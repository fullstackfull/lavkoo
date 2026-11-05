<?php

namespace App\Jobs\Fulfillment;

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
    use Dispatchable, InteractsWithQueue, OneAtATimePerFulfillment, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $fulfillmentId, public readonly bool $manual = false)
    {
    }

    public function handle(ProviderOrderDispatcher $dispatcher): void
    {
        $dispatcher->dispatch(fulfillmentId: $this->fulfillmentId, manual: $this->manual);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Provider fulfillment dispatch job crashed; recovery will reconcile it.', [
            'fulfillment_id' => $this->fulfillmentId,
            'error' => PiiRedactor::scrub($exception->getMessage()),
        ]);
    }
}
