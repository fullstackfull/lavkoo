<?php

namespace App\Jobs\Fulfillment;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $eventId)
    {
    }

    public function handle(WebhookEventProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
