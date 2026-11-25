<?php

namespace App\Jobs;

use App\Jobs\Concerns\CarriesItsStorefront;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one built message to one address.
 *
 * It carries the market of the request that queued it ({@see CarriesItsStorefront}), because a mailable renders in the worker: its subject,
 * its amounts and its links are read when `send()` runs, not when the job was made, so a message queued by a Kuwaiti request would
 * otherwise be written in the platform's own currency and language. The RECORD is not read here, as it is for a shipment, because this job
 * carries no record — only an address and an already-built mailable.
 *
 * **Nothing in this repository dispatches it.** The queued listeners send their mail through `EmailTemplateTrait::sendingMail()`, so this
 * class is the dormant half of the mail path: it is kept wired rather than left market-blind so that the first caller does not have to know
 * about any of this, and it is recorded as unreachable in the handoff of M-504 rather than quietly deleted.
 */
class SendEmailJob implements ShouldQueue
{
    use CarriesItsStorefront, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly mixed $mailTo, private readonly mixed $data)
    {
        $this->carryTheCurrentStorefront();
    }

    public function handle(): void
    {
        Mail::to($this->mailTo)->send($this->data);
    }
}
