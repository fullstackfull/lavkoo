<?php

namespace App\Listeners\Concerns;

use App\Jobs\Middleware\ServeInItsStorefront;

/**
 * Makes a queued LISTENER run in the storefront the event was dispatched from (A1-14).
 *
 * The leak and the cure are the ones {@see \App\Jobs\Concerns\CarriesItsStorefront} describes: a worker is a console process with no
 * storefront bound, so a mail queued by a Kuwaiti request was written as no market at all — the platform's own currency, company and
 * amounts — and a storefront left bound would have narrowed the next event's.
 *
 * The storefront is captured here rather than in a constructor because a queued listener is not a job object: Laravel builds the listener
 * with `newInstanceWithoutConstructor()`, asks it for its queue options, and serialises only the EVENT into a `CallQueuedListener`. The
 * listener instance itself never reaches the worker, so a property set in its constructor would never arrive — and its constructor is not
 * even called. `middleware()`, though, IS called on that instance while the dispatching request is still being served, and what it returns
 * is serialised with the job. So the country is read at that moment, and it is the request's own.
 *
 * Apply it to a listener whose event belongs to the market of the request that raised it: somebody registering, verifying, resetting their
 * own password, topping up their own wallet, asking to be told about a restock. Do NOT apply it where the raising request belongs to
 * somebody else than the recipient — an administrator changing a customer's status, a maintenance notice sent to everybody — because the
 * market of the person pressing the button is not the market of the person being written to. A listener about an order needs neither: the
 * order records its own storefront, and {@see \App\Services\Storefront\OrderNotificationContext} reads it.
 */
trait RunsInTheDispatchingStorefront
{
    /**
     * @param mixed ...$event the event, as Laravel's dispatcher passes it when it asks a listener for its middleware
     *
     * @return array<int, object>
     */
    public function middleware(mixed ...$event): array
    {
        return [new ServeInItsStorefront(country: ServeInItsStorefront::countryOfTheDispatchingProcess())];
    }
}
