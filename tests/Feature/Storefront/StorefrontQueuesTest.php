<?php

namespace Tests\Feature\Storefront;

use App\Jobs\RunSellerBulkJob;
use App\Listeners\AddFundToWalletListener;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontQueues;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A1-14, the operational half: each market's work can be worked, paced or paused on its own, because it waits on a queue of its own.
 *
 * One queue for six countries is one worker pool for six countries: a flood of one market's mail delays another's, and there is no way to
 * stop one market without stopping all of it. A queue per market hands both to the framework — `queue:work --queue=storefront-kw` and
 * `queue:pause storefront-kw` — so nothing here reimplements pausing; what is proved is that the work lands on the right queue, that the
 * default storefront keeps today's, and that an operator can see which markets are being worked.
 */
class StorefrontQueuesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.queues.per_market' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(QueueFactory::class)->resume((string) config('queue.default'), 'storefront-kw');
        app()->forgetInstance(StorefrontContext::class);

        parent::tearDown();
    }

    private function storefront(string $country): StorefrontContext
    {
        return StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: $country);
    }

    public function test_a_job_of_another_market_waits_on_that_markets_queue(): void
    {
        $job = $this->storefront('KW')->serve(fn (): object => new RunSellerBulkJob(bulkJobId: 1));

        $this->assertSame('storefront-kw', $job->queue);
    }

    /** The queue a job of the live market waits on does not move, so a worker that is already running keeps working it. */
    public function test_a_job_of_the_default_storefront_keeps_todays_queue(): void
    {
        $job = $this->storefront('SA')->serve(fn (): object => new RunSellerBulkJob(bulkJobId: 1));

        $this->assertNull($job->queue);
        $this->assertNull(StorefrontQueues::forMarket('SA'));
    }

    public function test_nothing_moves_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.queues.per_market' => false]);

        $job = $this->storefront('KW')->serve(fn (): object => new RunSellerBulkJob(bulkJobId: 1));

        $this->assertNull($job->queue);
        $this->assertSame([], StorefrontQueues::all());
    }

    public function test_nothing_moves_while_storefronts_are_off(): void
    {
        config(['gcc.storefronts.enabled' => false]);

        $this->assertNull(StorefrontQueues::forMarket('KW'));
        $this->assertFalse(StorefrontQueues::isEnabled());
    }

    public function test_a_queued_listener_waits_on_the_queue_of_the_market_the_event_was_raised_in(): void
    {
        $queue = $this->storefront('KW')->serve(
            fn (): ?string => (new AddFundToWalletListener())->viaQueue((object) ['email' => 'a@b.test']),
        );

        $this->assertSame('storefront-kw', $queue);
        $this->assertNull((new AddFundToWalletListener())->viaQueue(), 'an event raised outside any market waits where it waits today');
    }

    public function test_work_that_carries_no_market_waits_where_it_waits_today(): void
    {
        $this->assertNull((new RunSellerBulkJob(bulkJobId: 1))->queue);
        $this->assertNull(StorefrontQueues::forMarket(null));
        $this->assertNull(StorefrontQueues::forMarket(''));
    }

    public function test_every_configured_market_other_than_the_default_one_has_a_queue(): void
    {
        $this->assertSame(
            ['AE' => 'storefront-ae', 'KW' => 'storefront-kw', 'QA' => 'storefront-qa', 'BH' => 'storefront-bh', 'OM' => 'storefront-om'],
            StorefrontQueues::all(),
        );
    }

    /** The one thing an operator cannot see from outside: whether a market's queue is being worked at all. */
    public function test_the_report_names_each_markets_queue_and_says_which_is_paused(): void
    {
        app(QueueFactory::class)->pause((string) config('queue.default'), 'storefront-kw');

        $report = json_decode($this->artisanOutputOf(['--json' => true]), associative: true);

        $this->assertTrue($report['per_market_queues']);
        $this->assertSame('SA', $report['default_storefront']);
        $this->assertSame(
            ['country' => 'KW', 'queue' => 'storefront-kw', 'paused' => true, 'waiting' => null, 'failed' => null],
            collect($report['markets'])->firstWhere('country', 'KW'),
        );
        $this->assertFalse(collect($report['markets'])->firstWhere('country', 'AE')['paused'], 'pausing one market pauses one market');
    }

    public function test_the_report_prints_the_command_that_works_a_market_and_the_one_that_pauses_it(): void
    {
        $printed = $this->artisanOutputOf();

        $this->assertStringContainsString('php artisan queue:work sync --queue=storefront-kw', $printed);
        $this->assertStringContainsString('php artisan queue:pause storefront-', $printed);
        $this->assertStringContainsString('PAUSED', $this->pausedReport(), 'an operator reading the table sees which market is stopped');
    }

    private function pausedReport(): string
    {
        app(QueueFactory::class)->pause((string) config('queue.default'), 'storefront-kw');

        return $this->artisanOutputOf();
    }

    public function test_the_report_says_so_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.queues.per_market' => false]);

        $this->artisan('gcc:storefront-queues')
            ->expectsOutputToContain('Per-market queues are off')
            ->expectsOutputToContain('No other market has a queue of its own.')
            ->assertSuccessful();
    }

    /** @param array<string, mixed> $options */
    private function artisanOutputOf(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('gcc:storefront-queues', $options));

        return Artisan::output();
    }
}
