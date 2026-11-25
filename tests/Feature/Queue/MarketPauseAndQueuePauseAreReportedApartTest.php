<?php

namespace Tests\Feature\Queue;

use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ActivatesStorefront;
use Tests\TestCase;

/**
 * `gcc:storefront-queues` tells an operator which of the TWO pauses a market is under (M-888, GCC-12).
 *
 * They are different things and neither implies the other. Pausing a market's QUEUE stops its work being reserved. Pausing the MARKET stops
 * it SELLING and deliberately leaves its earlier obligations running: a stopped market still refunds, still answers a gateway callback and
 * still tells a customer where a parcel is. So a market can be stopped while its queue is open, which is correct and looks exactly like a
 * stop that has not taken effect, and a queue can be paused while the market is still selling, which looks exactly like a market that is
 * closed. An operator who cannot see both at once will read one as the other.
 *
 * The queue half of the report, and the names of the queues, are pinned by `StorefrontQueuesTest`. This is only the half that was missing.
 */
class MarketPauseAndQueuePauseAreReportedApartTest extends TestCase
{
    use ActivatesStorefront;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.queues.per_market' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);

        $this->activateStorefrontFor('KW', 'AE');
    }

    public function test_the_report_says_which_markets_are_selling_and_which_are_stopped_and_in_which_mode(): void
    {
        app(StorefrontLifecycle::class)->pause(
            country: 'KW',
            mode: StorefrontLifecycle::MAINTENANCE,
            by: 'The operator on duty',
            reason: 'A regulator asked for the market to be stopped',
        );

        $selling = $this->reportAsJson()['selling'];

        $this->assertSame(['state' => StorefrontLifecycle::PAUSED, 'mode' => StorefrontLifecycle::MAINTENANCE], $selling['KW']);
        $this->assertSame(['state' => StorefrontLifecycle::ACTIVE, 'mode' => null], $selling['AE'], 'stopping one market stops one market');
    }

    /** The default storefront has no queue of its own and can still be stopped, so it is in the report all the same. */
    public function test_the_default_storefront_is_in_the_report_although_it_has_no_queue_of_its_own(): void
    {
        $report = $this->reportAsJson();

        $this->assertArrayHasKey('SA', $report['selling']);
        $this->assertSame(StorefrontLifecycle::ACTIVE, $report['selling']['SA']['state']);
        $this->assertNull(
            collect($report['markets'])->firstWhere('country', 'SA'),
            'the default storefront keeps the queue every job waits on today',
        );
    }

    /**
     * The two states are printed side by side and named apart. A market stopped from selling whose queue is open is the case an operator is
     * most likely to misread, so that is the one the table has to show plainly.
     */
    public function test_a_market_stopped_from_selling_whose_queue_is_open_is_printed_as_both(): void
    {
        app(StorefrontLifecycle::class)->pause(
            country: 'KW',
            mode: StorefrontLifecycle::BROWSE_ONLY,
            by: 'The operator on duty',
            reason: 'Stock is being counted',
        );

        $printed = $this->reportAsText();

        $this->assertMatchesRegularExpression(
            '/KW\s*\|\s*storefront-kw\s*\|\s*open\s*\|\s*' . StorefrontLifecycle::PAUSED . ' \(' . StorefrontLifecycle::BROWSE_ONLY . '\)/',
            $printed,
            'the queue is open while the market is stopped, and the table says so on one line',
        );
        $this->assertStringContainsString('Stopping a market SELLING is the storefront column', $printed);
    }

    /** The other way round: a paused queue is not a closed market, and the table must not read as though it were. */
    public function test_a_paused_queue_does_not_make_the_market_read_as_stopped(): void
    {
        app(QueueFactory::class)->pause((string) config('queue.default'), 'storefront-kw');

        $report = $this->reportAsJson();

        $this->assertTrue(collect($report['markets'])->firstWhere('country', 'KW')['paused']);
        $this->assertSame(StorefrontLifecycle::ACTIVE, $report['selling']['KW']['state'], 'pausing a queue does not stop a market selling');
    }

    /** @return array<string, mixed> */
    private function reportAsJson(): array
    {
        $this->assertSame(0, Artisan::call('gcc:storefront-queues', ['--json' => true]));

        return json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function reportAsText(): string
    {
        $this->assertSame(0, Artisan::call('gcc:storefront-queues'));

        return Artisan::output();
    }
}
