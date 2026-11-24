<?php

namespace App\Jobs\Middleware;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Closure;

/**
 * Runs a queued job in the storefront it was dispatched from, and puts back whatever the worker had bound before it (A1-14).
 *
 * A worker runs one job after another in one process. Nothing bound a storefront for a job, so a job dispatched from a Kuwaiti request
 * rendered its mail in whatever storefront the worker was left in -- the Saudi default on a fresh worker, or the market of the job before
 * it. The leak runs both ways: a job that does bind one would narrow every job after it.
 *
 * The storefront travels in the job's own payload (see `CarriesItsStorefront`), so it survives the queue, a retry and a worker restart,
 * and it is a country code rather than a context object: a serialised object would carry the configuration of the moment it was dispatched.
 * A job that was dispatched outside any storefront carries none and is run exactly as it is run today, unbound.
 */
class ServeInItsStorefront
{
    public function __construct(private readonly ?string $country)
    {
    }

    /**
     * The storefront the process dispatching this job is serving, which is what a job or a listener records to be run in later.
     *
     * Null while storefronts are off, so that nothing is recorded and no payload changes until the owner switches them on: a job queued
     * while they were off is run unbound, exactly as every job is run today. Null too when nothing is bound at all -- a console command, a
     * scheduled task, a test -- because work started outside a market belongs to no market, and recording the default storefront for it
     * would be a claim nobody made.
     */
    public static function countryOfTheDispatchingProcess(): ?string
    {
        if (!StorefrontResolver::fromConfig()->isEnabled()) {
            return null;
        }

        return app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null;
    }

    public function handle(object $job, Closure $next): mixed
    {
        $context = StorefrontResolver::fromConfig()->contextOfRecordedCountry($this->country);

        return $context === null ? $next($job) : $context->serve(fn (): mixed => $next($job));
    }
}
