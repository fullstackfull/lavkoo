<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Services\SalesChannels\Dispatch\ChannelOrderDispatcher;
use App\Services\SalesChannels\Dispatch\DispatchOutcome;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;

/** The scheduled commands: what they do for an account on automatic dispatch, and that everything else is left alone. */
class DispatchCommandsTest extends DispatchTestCase
{
    private function automatic(): void
    {
        $this->account->forceFill(['settings' => ['dispatch_mode' => 'auto'] + (array) $this->account->settings])->save();
    }

    public function test_with_a_dispatch_switch_off_the_command_does_nothing_and_says_so(): void
    {
        $this->automatic();
        $this->placed();
        config(['sales_channels.buyer_data_transfer_enabled' => false]);

        $this->artisan('channels:dispatch')->expectsOutputToContain('switched off')->assertSuccessful();

        $this->assertSame([0, 0], [DB::table('orders')->count(), $this->recipientReads()]);
    }

    public function test_an_account_left_on_manual_dispatch_is_never_dispatched_by_the_schedule(): void
    {
        $this->placed();

        $this->artisan('channels:dispatch')->expectsOutputToContain('No order is waiting')->assertSuccessful();

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_an_account_on_automatic_dispatch_gets_its_routed_orders_dispatched_once(): void
    {
        $this->automatic();
        $mapping = $this->placed();

        $this->artisan('channels:dispatch')->expectsOutputToContain('materialized 1')->assertSuccessful();
        $this->artisan('channels:dispatch')->expectsOutputToContain('No order is waiting')->assertSuccessful();

        $this->assertSame([1, $mapping->id], [DB::table('orders')->count(), ChannelOrderDispatch::firstOrFail()->mapping_id]);
    }

    public function test_an_order_waiting_for_its_next_attempt_is_not_asked_about_again_before_its_time(): void
    {
        $this->automatic();
        $this->recipientAnswers(['shipmentAddress' => ['phone' => '', 'fullName' => 'X', 'address1' => 'Street', 'city' => 'Riyadh', 'countryCode' => 'SA']]);
        $this->placed();

        $this->artisan('channels:dispatch')->expectsOutputToContain('blocked 1')->assertSuccessful();
        $reads = $this->recipientReads();
        $this->artisan('channels:dispatch')->expectsOutputToContain('No order is waiting')->assertSuccessful();

        $this->assertSame($reads, $this->recipientReads());

        $this->travel(6)->minutes();
        $this->artisan('channels:dispatch')->expectsOutputToContain('blocked 1')->assertSuccessful();
        $this->assertSame($reads + 1, $this->recipientReads());
    }

    public function test_an_order_whose_dispatch_throws_is_recorded_and_the_rest_of_the_sweep_still_gets_its_turn(): void
    {
        $this->automatic();
        $first = $this->placed();
        $second = $this->placed(orderId: '10234567891', package: '3012345679');
        $asked = [];
        $dispatcher = Mockery::mock(ChannelOrderDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturnUsing(function (ExternalOrderMapping $mapping) use (&$asked, $first): DispatchOutcome {
            $asked[] = (int) $mapping->id;

            return (int) $mapping->id === (int) $first->id ? throw new RuntimeException('the channel fell over') : DispatchOutcome::blocked('later');
        });
        $this->instance(ChannelOrderDispatcher::class, $dispatcher);

        $this->artisan('channels:dispatch')->expectsOutputToContain('errored 1, blocked 1')->assertSuccessful();

        $this->assertSame([(int) $first->id, (int) $second->id], $asked, 'the second order is dispatched although the first threw');
    }

    public function test_the_sync_command_reports_what_it_did(): void
    {
        $this->artisan('channels:dispatch-sync')->expectsOutputToContain('Dispatch sync: linked 0')->assertSuccessful();
    }
}
