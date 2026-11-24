<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\ServeInItsStorefront;

/**
 * Makes a queued JOB remember which storefront it was dispatched from, and run in that one (A1-14).
 *
 * A job is constructed in the request that dispatches it and run later in a worker, where nothing has bound a storefront: a queue worker
 * runs as a console process, so the catalogue narrows to nothing and every setting a storefront overrides answers the platform's own value.
 * A job queued by a Kuwaiti request was therefore run as no market at all, and a storefront that a job did bind would have been left bound
 * for the job after it. Reading the market inside `handle()` cannot fix either: by then the request is gone.
 *
 * The storefront is recorded at construction, which happens in the dispatching request, and travels in the job's own serialised payload, so
 * it survives the queue, a retry and a worker restart. A country code travels rather than a context object: the worker rebuilds the context
 * from the settings in force when it runs, instead of restoring a copy of the settings of the moment the job was queued.
 *
 * A job dispatched outside any storefront — a console command, a scheduled task, a test — records none and is run unbound, exactly as every
 * job is run today, and so does every job while storefronts are off: the payload is what it is today until the owner switches them on. That is deliberate: a console job belongs to no market, and recording the default storefront for it would be a claim
 * about its market that nobody made.
 *
 * Two notes for whoever applies this next:
 *
 *  - A job that already knows its market from the record it carries — an order, an invoice, a connection — should override
 *    `storefrontToServe()` and read it from that record, the way order notifications do ({@see \App\Services\Storefront\OrderNotificationContext}).
 *    The record is the truth; the request that happened to queue the job is only a guess.
 *  - A job that works rows of many markets at once (the stock-event drain, a search-index rebuild) must NOT use this. It belongs to no one
 *    market, and binding the dispatcher's would narrow what it is supposed to sweep.
 *
 * A job with a `middleware()` of its own overrides the one below, as any class method overrides a trait's; such a job has to return
 * `new ServeInItsStorefront(...)` alongside its own middleware itself.
 *
 * {@see \App\Listeners\Concerns\RunsInTheDispatchingStorefront} does the same for a queued LISTENER, which has to capture the storefront at
 * another moment because Laravel builds a listener without calling its constructor.
 */
trait CarriesItsStorefront
{
    /** The storefront the job was dispatched from, or null when it was dispatched outside any. Public so that the queue serialises it. */
    public ?string $storefrontCountry = null;

    /** Records the dispatching request's storefront. Call it from the job's constructor, which is what runs in that request. */
    protected function carryTheCurrentStorefront(): void
    {
        $this->storefrontCountry = ServeInItsStorefront::countryOfTheDispatchingProcess();
    }

    /** @return array<int, object> the queue middleware Laravel runs `handle()` through */
    public function middleware(): array
    {
        return [new ServeInItsStorefront(country: $this->storefrontToServe())];
    }

    /** The storefront to run this job in. Override it in a job that derives its market from the record it carries. */
    protected function storefrontToServe(): ?string
    {
        return $this->storefrontCountry;
    }
}
