<?php

namespace Tests\Feature\Storefront;

use App\Jobs\Middleware\ServeInItsStorefront;
use App\Jobs\RunSellerBulkJob;
use App\Listeners\AddFundToWalletListener;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Tests\Support\Queue\RecordsTheStorefrontItRanIn;
use Tests\TestCase;

/**
 * A1-14: a queued job and a queued listener are run in the storefront they were dispatched from, and two of different countries in one
 * worker do not share one.
 *
 * A worker is a console process. Nothing binds a storefront in it, so until this a job queued by a Kuwaiti request was run as no market at
 * all — the platform's own currency, company and settings — and a storefront a job did bind would have been left bound for the job after
 * it. Both halves are proved here: what the job carries, and what the next job sees.
 */
class QueuedWorkCarriesItsStorefrontTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gcc.storefronts.enabled' => true]);
        config(['gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.countries.AE.active' => true]);
        RecordsTheStorefrontItRanIn::forget();
    }

    protected function tearDown(): void
    {
        RecordsTheStorefrontItRanIn::forget();
        app()->forgetInstance(StorefrontContext::class);

        parent::tearDown();
    }

    private function storefront(string $country): StorefrontContext
    {
        return StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: $country);
    }

    /** Dispatches the job the way a worker reaches it: rebuilt from its serialised payload, with no storefront bound. */
    private function workTheQueue(object $job): void
    {
        $this->assertFalse(app()->bound(StorefrontContext::class), 'the worker holds no storefront of its own');

        dispatch(unserialize(serialize($job)));
    }

    public function test_a_job_is_run_in_the_storefront_it_was_dispatched_from(): void
    {
        $job = $this->storefront('KW')->serve(fn (): object => new RecordsTheStorefrontItRanIn(name: 'kuwaiti'));

        $this->workTheQueue($job);

        $this->assertSame(['KW'], RecordsTheStorefrontItRanIn::storefrontsSeen());
    }

    public function test_the_storefront_travels_in_the_payload_rather_than_in_the_worker(): void
    {
        $job = $this->storefront('AE')->serve(fn (): object => new RecordsTheStorefrontItRanIn());

        $this->assertSame('AE', unserialize(serialize($job))->storefrontCountry, 'a retry and a worker restart read the same payload');
    }

    public function test_two_jobs_of_two_countries_in_one_worker_do_not_share_a_storefront(): void
    {
        $kuwaiti = $this->storefront('KW')->serve(fn (): object => new RecordsTheStorefrontItRanIn(name: 'kuwaiti'));
        $emirati = $this->storefront('AE')->serve(fn (): object => new RecordsTheStorefrontItRanIn(name: 'emirati'));
        $console = new RecordsTheStorefrontItRanIn(name: 'console');

        $this->workTheQueue($kuwaiti);
        $this->workTheQueue($emirati);
        $this->workTheQueue($console);

        $this->assertSame(['KW', 'AE', null], RecordsTheStorefrontItRanIn::storefrontsSeen());
        $this->assertFalse(app()->bound(StorefrontContext::class), 'the worker is left as it was found');
    }

    public function test_a_job_dispatched_outside_any_storefront_is_run_unbound(): void
    {
        $this->workTheQueue(new RecordsTheStorefrontItRanIn(name: 'console'));

        $this->assertSame([['job' => 'console', 'country' => null, 'bound' => false]], RecordsTheStorefrontItRanIn::$runs);
    }

    /** A recovery or a console command that is itself serving a storefront gets it back, the same way a request's own context is restored. */
    public function test_the_storefront_the_process_was_serving_is_put_back(): void
    {
        $job = $this->storefront('KW')->serve(fn (): object => new RecordsTheStorefrontItRanIn());

        $this->storefront('AE')->serve(function () use ($job): void {
            dispatch(unserialize(serialize($job)));

            $this->assertSame('AE', app(StorefrontContext::class)->country, 'the job did not take the process with it');
        });

        $this->assertSame(['KW'], RecordsTheStorefrontItRanIn::storefrontsSeen());
    }

    /** A payload written before the country was recorded, or one holding anything that is not a country, is run exactly as it is run today. */
    public function test_a_payload_naming_no_country_is_run_unbound(): void
    {
        foreach ([null, '', 'not-a-country'] as $recorded) {
            $seen = (new ServeInItsStorefront(country: $recorded))->handle(
                new RecordsTheStorefrontItRanIn(),
                fn (): ?string => app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null,
            );

            $this->assertNull($seen, "a payload holding " . var_export($recorded, true) . " binds nothing");
        }
    }

    /** While storefronts are off, a job's payload is what it is today: the request binds a context all the same, and nothing is recorded from it. */
    public function test_nothing_is_recorded_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $job = $this->storefront('KW')->serve(fn (): object => new RecordsTheStorefrontItRanIn());

        $this->assertNull($job->storefrontCountry);
        $this->assertNull((new RunSellerBulkJob(bulkJobId: 1))->storefrontCountry);
        $this->assertNull((new AddFundToWalletListener())->middleware()[0]->handle(
            $job,
            fn (): ?string => app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null,
        ));
    }

    public function test_the_seller_bulk_job_carries_the_storefront_of_the_seller_who_asked_for_it(): void
    {
        $queuedByKuwait = $this->storefront('KW')->serve(fn (): object => new RunSellerBulkJob(bulkJobId: 1));

        $this->assertSame('KW', $queuedByKuwait->storefrontCountry);
        $this->assertNull((new RunSellerBulkJob(bulkJobId: 1))->storefrontCountry, 'a bulk run started from the console belongs to no market');
    }

    /**
     * A queued listener keeps it in the middleware the dispatcher asks it for, because Laravel builds a listener without its constructor and
     * serialises only the event. That middleware is built while the request is still being served, so the country in it is the request's own.
     */
    public function test_a_queued_listener_is_run_in_the_storefront_the_event_was_raised_in(): void
    {
        $middleware = $this->storefront('KW')->serve(
            fn (): array => (new AddFundToWalletListener())->middleware((object) ['email' => 'a@b.test']),
        );

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(ServeInItsStorefront::class, $middleware[0]);

        $seen = $middleware[0]->handle(
            new AddFundToWalletListener(),
            fn (): ?string => app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null,
        );

        $this->assertSame('KW', $seen, 'the mail is written as the market the customer topped up on');
    }

    public function test_a_listener_of_a_console_event_is_run_unbound(): void
    {
        $middleware = (new AddFundToWalletListener())->middleware((object) ['email' => 'a@b.test']);

        $seen = $middleware[0]->handle(
            new AddFundToWalletListener(),
            fn (): ?string => app()->bound(StorefrontContext::class) ? app(StorefrontContext::class)->country : null,
        );

        $this->assertNull($seen);
    }
}
