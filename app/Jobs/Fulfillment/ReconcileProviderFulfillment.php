<?php

namespace App\Jobs\Fulfillment;

use App\Services\Fulfillment\ReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileProviderFulfillment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, KeepsPersonalDataOutOfFailures, OneAtATimePerFulfillment, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $fulfillmentId)
    {
    }

    public function handle(ReconciliationService $reconciliation): void
    {
        $this->withoutPersonalDataInFailures(fn () => $reconciliation->reconcile($this->fulfillmentId));
    }
}
