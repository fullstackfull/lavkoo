<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\DispatchState;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ChannelOrderMaterializer;
use App\Services\SalesChannels\Dispatch\DispatchOutcome;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\UnreachableChannelConnector;

/** A Trendyol order that a rule sends to Torod Point, from the channel to the provider's door, with the platform's own fulfilment (router, dispatcher, a scripted provider) doing the part it owns. */
class ChannelDispatchTest extends DispatchTestCase
{
    // ---- the gate ----------------------------------------------------------------------------------------

    public function test_with_everything_open_one_dispatch_makes_one_internal_order_with_no_customer_and_no_stock_taken(): void
    {
        $mapping = $this->placed();

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $order = DB::table('orders')->where('id', $outcome->internalOrderId)->first();
        $this->assertSame([0, 0, 'paid', 'confirmed', 'marketplace_prepaid', 'default_type'], [(int) $order->customer_id, (int) $order->is_guest, $order->payment_status, $order->order_status, $order->payment_method, $order->order_type]);
        $this->assertEqualsWithDelta(200.0, (float) $order->order_amount, 0.01);
        $this->assertSame('Channel order 10234567890', $order->order_note);
        $detail = DB::table('order_details')->where('order_id', $outcome->internalOrderId)->get();
        $this->assertCount(1, $detail);
        $this->assertSame([2, 0], [(int) $detail[0]->qty, (int) $detail[0]->is_stock_decreased]);
        $this->assertSame(50, (int) DB::table('products')->where('id', 10)->value('current_stock'), 'No stock is taken off the store');
        $mapping->refresh();
        $this->assertSame([$outcome->internalOrderId, 'managed', 'torod_point'], [$mapping->order_id, $mapping->execution_policy, $mapping->provider]);
        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame([DispatchState::Materialized->value, 1, $outcome->internalOrderId], [$row->state, $row->attempts, $row->internal_order_id]);
        $this->assertNull($row->claim_token);
    }

    public function test_the_recipient_is_written_where_the_provider_builders_read_it_and_nowhere_else(): void
    {
        $outcome = $this->dispatcher()->dispatch($this->placed());

        $address = json_decode((string) DB::table('orders')->where('id', $outcome->internalOrderId)->value('shipping_address_data'), true);
        $this->assertSame(['contact_person_name' => self::NAME, 'phone' => self::PHONE_NATIONAL, 'address' => self::STREET, 'city' => 'Riyadh', 'state' => 'Riyadh Region', 'country' => 'SA', 'email' => '', 'zip' => null], $address);
        $everywhereElse = $this->storedOutsideTheOrder();
        foreach ([self::NAME, self::PHONE_NATIONAL, '500000000', self::STREET, 'buyer@'] as $detail) {
            $this->assertStringNotContainsString($detail, $everywhereElse, "'{$detail}' was stored outside the internal order");
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function closedGates(): array
    {
        return [
            'managed dispatch off' => [['sales_channels.managed_dispatch_enabled' => false], 'managed_dispatch_off'],
            'buyer data switch off' => [['sales_channels.buyer_data_transfer_enabled' => false], 'buyer_data_transfer_off'],
        ];
    }

    #[DataProvider('closedGates')]
    public function test_a_closed_switch_stops_everything_before_the_buyer_is_read_and_before_anything_is_written(array $config, string $reason): void
    {
        $mapping = $this->placed();
        config($config);

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame([DispatchOutcome::BLOCKED, true], [$outcome->state, in_array($reason, $outcome->blockers, true)]);
        $this->assertSame([0, 0, 0], [$this->recipientReads(), DB::table('orders')->count(), ChannelOrderDispatch::count()]);
    }

    public function test_an_account_that_has_not_declared_who_creates_its_shipments_never_gets_any(): void
    {
        $this->account->forceFill(['settings' => ['shipment_creation' => 'unset'] + (array) $this->account->settings])->save();
        $mapping = $this->placed();

        $this->assertContains('shipment_creation_not_declared', $this->dispatcher()->dispatch($mapping)->blockers);

        $this->account->forceFill(['settings' => ['shipment_creation' => 'provider_direct'] + (array) $this->account->settings])->save();
        $this->assertContains('shipments_are_created_by_the_provider_directly', $this->dispatcher()->dispatch($mapping->refresh())->blockers);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_without_the_masters_approval_or_the_dispatch_capability_the_order_waits(): void
    {
        $approvals = (array) $this->account->capability_approvals;
        unset($approvals['buyer_data_transfer']);
        $this->account->forceFill(['capability_approvals' => $approvals])->save();
        $mapping = $this->placed();

        $this->assertContains('buyer_data_approval_missing', $this->dispatcher()->dispatch($mapping)->blockers);

        $this->account->forceFill(['capabilities' => array_values(array_diff((array) $this->account->capabilities, ['fulfillment_dispatch']))])->save();
        $this->assertContains('dispatch_capability_off', $this->dispatcher()->dispatch($mapping->refresh())->blockers);
    }

    public function test_what_the_channel_ships_itself_or_cash_on_delivery_or_another_country_is_never_dispatched(): void
    {
        $this->assertContains('fulfilment_not_ours', $this->dispatcher()->dispatch($this->placed(['fulfillmentMode' => FulfillmentMode::Fba, 'fulfillmentChannelRaw' => 'AFN'], 'FBA-1', ''))->blockers);
        $this->assertContains('destination_not_supported', $this->dispatcher()->dispatch($this->placed(['destination' => ['city' => 'Dubai', 'country' => 'AE']], 'UAE-1', 'P2'))->blockers);
        $this->assertSame([0, 0], [DB::table('orders')->count(), $this->recipientReads()]);
    }

    public function test_a_line_without_an_approved_provider_mapping_or_owned_by_another_provider_is_refused(): void
    {
        $mapping = $this->placed();
        ProviderProductMapping::query()->update(['status' => MappingStatus::Pending]);
        $this->assertContains('provider_mapping_not_approved', $this->dispatcher()->dispatch($mapping)->blockers);

        ProviderProductMapping::query()->update(['status' => MappingStatus::Approved]);
        DB::table('products')->where('id', 10)->update(['fulfillment_provider' => 'm5azn']);
        $this->assertContains('product_owned_by_other_provider', $this->dispatcher()->dispatch($mapping->refresh())->blockers);
    }

    public function test_a_shipment_that_is_already_known_is_never_given_a_second_one(): void
    {
        $mapping = $this->placed();
        $mapping->forceFill(['shipment_link_state' => ExternalOrderMapping::LINK_LINKED, 'shipment_reference' => 'TOR-1', 'provider' => 'torod_point'])->save();

        $outcome = $this->dispatcher()->dispatch($mapping->refresh());

        $this->assertContains('shipment_already_known', $outcome->blockers);
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- the buyer's details ----------------------------------------------------------------------------

    public function test_a_recipient_that_cannot_be_shipped_to_waits_with_a_reason_and_comes_back_later(): void
    {
        $this->recipientAnswers(['shipmentAddress' => ['phone' => '', 'fullName' => 'X', 'address1' => 'Street', 'city' => 'Riyadh', 'countryCode' => 'SA']]);
        $mapping = $this->placed();

        $first = $this->dispatcher()->dispatch($mapping, 'auto');

        $this->assertSame('recipient_incomplete:phone_missing', $first->reason);
        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame([DispatchState::Blocked->value, 1], [$row->state, $row->attempts]);
        $this->assertTrue($row->next_attempt_at->between(now()->addMinutes(4), now()->addMinutes(6)));
        $this->assertSame(0, DB::table('orders')->count());

        $this->assertSame(DispatchOutcome::WAITING, $this->dispatcher()->dispatch($mapping->refresh(), 'auto')->state, 'the scheduled run honours the waiting time');
        $this->assertSame(1, $this->recipientReads(), 'and does not read the buyer again meanwhile');
    }

    public function test_a_person_asking_overrides_the_waiting_time_and_a_fixed_recipient_goes_through(): void
    {
        $this->recipientAnswers(['shipmentAddress' => ['phone' => '', 'fullName' => 'X', 'address1' => 'Street', 'city' => 'Riyadh', 'countryCode' => 'SA']]);
        $mapping = $this->placed();
        $this->dispatcher()->dispatch($mapping, 'auto');
        $this->recipientAnswers();

        $outcome = $this->dispatcher()->dispatch($mapping->refresh(), 'manual', 7);

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $this->assertSame([2, 7], [ChannelOrderDispatch::firstOrFail()->attempts, ChannelOrderDispatch::firstOrFail()->requested_by]);
    }

    public function test_after_the_last_allowed_attempt_the_scheduled_run_stops_asking_the_channel(): void
    {
        $this->recipientAnswers(['shipmentAddress' => ['phone' => '', 'fullName' => 'X', 'address1' => 'Street', 'city' => 'Riyadh', 'countryCode' => 'SA']]);
        $mapping = $this->placed();

        foreach (range(1, 3) as $attempt) {
            $this->dispatcher()->dispatch($mapping->refresh(), 'auto');
            ChannelOrderDispatch::query()->update(['next_attempt_at' => null]);
        }
        $reads = $this->recipientReads();

        $this->assertSame('attempts_exhausted', $this->dispatcher()->dispatch($mapping->refresh(), 'auto')->reason);
        $this->assertSame($reads, $this->recipientReads());
        $this->assertNull(ChannelOrderDispatch::firstOrFail()->next_attempt_at);
    }

    public function test_a_package_trendyol_ships_itself_is_blocked_for_good_not_retried(): void
    {
        $this->recipientAnswers(['is4P' => true]);

        $outcome = $this->dispatcher()->dispatch($this->placed());

        $this->assertSame('not_shipped_by_the_seller', $outcome->reason);
        $this->assertNull(ChannelOrderDispatch::firstOrFail()->next_attempt_at);
    }

    public function test_when_the_master_switches_buyer_data_off_in_the_middle_the_read_is_refused_and_the_claim_is_given_back(): void
    {
        $mapping = $this->placed();
        $this->account->forceFill(['capability_approvals' => collect((array) $this->account->capability_approvals)->except('buyer_data_transfer')->all()])->save();
        // The gate already said yes for this run (it read the account before); the transport reads the account again and refuses.
        $stale = SalesChannelAccount::find($this->account->id);
        $stale->forceFill(['capability_approvals' => ['buyer_data_transfer' => ['by' => 1], 'fulfillment_dispatch' => ['by' => 1]]]);
        $mapping->setRelation('account', $stale);

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame(DispatchOutcome::BLOCKED, $outcome->state);
        $this->assertStringStartsWith('channel_', (string) $outcome->reason);
        $this->assertNull(ChannelOrderDispatch::firstOrFail()->claim_token);
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- the channel cannot be reached -------------------------------------------------------------------

    private function unreachableChannel(\Throwable $throwable): ExternalOrderMapping
    {
        $mapping = $this->placed();
        UnreachableChannelConnector::throwing($throwable);
        config(['sales_channels.connectors.trendyol' => UnreachableChannelConnector::class]);

        return $mapping;
    }

    public function test_a_rate_limited_channel_gets_the_claim_straight_back_with_its_own_wait_and_spends_no_attempt(): void
    {
        $mapping = $this->unreachableChannel(new ChannelRateLimitedException(90));

        $outcome = $this->dispatcher()->dispatch($mapping, 'auto');

        $this->assertSame([DispatchOutcome::WAITING, 'channel_rate_limited'], [$outcome->state, $outcome->reason]);
        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame([DispatchState::Blocked->value, 'channel_rate_limited', 0], [$row->state, $row->reason_code, $row->attempts], 'the order never had its turn, so no attempt was spent');
        $this->assertNull($row->claim_token, 'the claim is not left held for the whole claim window');
        $this->assertTrue($row->next_attempt_at->between(now()->addSeconds(85), now()->addSeconds(95)), 'it waits exactly as long as the channel asked');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_rate_limit_cannot_use_up_an_orders_attempts_and_the_order_still_goes_through_afterwards(): void
    {
        $mapping = $this->unreachableChannel(new ChannelRateLimitedException(1));
        foreach (range(1, 6) as $ignored) {
            $this->dispatcher()->dispatch($mapping->refresh(), 'auto');
            ChannelOrderDispatch::query()->update(['next_attempt_at' => null]);
        }
        $this->assertSame(0, ChannelOrderDispatch::firstOrFail()->attempts);

        config(['sales_channels.connectors.trendyol' => \App\Services\SalesChannels\Trendyol\TrendyolConnector::class]);
        $outcome = $this->dispatcher()->dispatch($mapping->refresh(), 'auto');

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state, 'six rate limits must not exhaust the five attempts an order has');
    }

    public function test_a_failure_nobody_named_releases_the_claim_as_retryable_and_says_nothing_about_the_buyer(): void
    {
        $mapping = $this->unreachableChannel(new RuntimeException('Buyer Test Buyer at 1 Test Street could not be read'));

        $outcome = $this->dispatcher()->dispatch($mapping, 'auto');

        $this->assertSame([DispatchOutcome::BLOCKED, 'recipient_read_failed'], [$outcome->state, $outcome->reason]);
        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame([DispatchState::Blocked->value, 'recipient_read_failed', 1], [$row->state, $row->reason_code, $row->attempts]);
        $this->assertNull($row->claim_token);
        $this->assertNotNull($row->next_attempt_at, 'a failure of the read is tried again');
        $this->assertStringNotContainsString(self::NAME, $this->storedOutsideTheOrder(), 'what the failure said about the buyer is not stored');
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- once and only once ------------------------------------------------------------------------------

    public function test_dispatching_again_finds_the_order_already_made_and_reads_nothing(): void
    {
        $mapping = $this->placed();
        $first = $this->dispatcher()->dispatch($mapping);
        $reads = $this->recipientReads();

        $second = $this->dispatcher()->dispatch($mapping->refresh());

        $this->assertSame([DispatchOutcome::ALREADY_DONE, $first->internalOrderId], [$second->state, $second->internalOrderId]);
        $this->assertSame([1, 1, $reads], [DB::table('orders')->count(), DB::table('order_details')->count(), $this->recipientReads()]);
    }

    public function test_an_attempt_that_is_still_running_makes_a_second_one_stand_back(): void
    {
        $mapping = $this->placed();
        ChannelOrderDispatch::create(['mapping_id' => $mapping->id, 'account_id' => $mapping->account_id, 'state' => 'claimed', 'claim_token' => 'someone-else', 'claimed_at' => now()->subMinutes(2), 'attempts' => 1]);

        $this->assertSame(DispatchOutcome::BUSY, $this->dispatcher()->dispatch($mapping)->state);
        $this->assertSame([0, 0], [DB::table('orders')->count(), $this->recipientReads()]);
    }

    public function test_an_attempt_that_never_finished_is_given_up_after_its_time_and_the_order_is_made_once(): void
    {
        $mapping = $this->placed();
        ChannelOrderDispatch::create(['mapping_id' => $mapping->id, 'account_id' => $mapping->account_id, 'state' => 'claimed', 'claim_token' => 'crashed', 'claimed_at' => now()->subMinutes(11), 'attempts' => 1]);

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame(DispatchOutcome::MATERIALIZED, $outcome->state);
        $this->assertSame([1, 2], [DB::table('orders')->count(), ChannelOrderDispatch::firstOrFail()->attempts]);
    }

    public function test_a_failure_in_the_middle_of_making_the_order_leaves_nothing_behind(): void
    {
        $this->app->bind(\App\Services\Fulfillment\OrderItemOwnershipSnapshotter::class, fn () => new class(app(\App\Services\Fulfillment\Support\ProviderCurrency::class)) extends \App\Services\Fulfillment\OrderItemOwnershipSnapshotter {
            public function capture(int $orderId, int $orderDetailId, array $product, ?string $variant, int $quantity, float $unitPrice, float $netAmount, float $lineTax, string $taxModel): ?\App\Models\ProviderFulfillmentItem
            {
                throw new \RuntimeException('the snapshot could not be written');
            }
        });
        $mapping = $this->placed();

        try {
            $this->dispatcher()->dispatch($mapping);
            $this->fail('The failure should have surfaced.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([0, 0, 0], [DB::table('orders')->count(), DB::table('order_details')->count(), \App\Models\ProviderFulfillmentItem::count()]);
        $this->assertNull($mapping->refresh()->order_id);
        $this->assertSame(DispatchState::Claimed->value, ChannelOrderDispatch::firstOrFail()->state, 'the claim stays until it times out, so no second run piles on');
    }

    public function test_no_order_is_made_when_the_audit_trail_cannot_be_written(): void
    {
        $mapping = $this->placed();
        Schema::drop('audit_logs');

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame('audit_not_written', $outcome->reason);
        $this->assertSame(0, DB::table('orders')->count());
    }

    // ---- the platform's own fulfilment takes over --------------------------------------------------------

    public function test_the_platforms_router_and_dispatcher_then_hold_the_shipment_in_rehearsal_and_send_it_once_when_allowed(): void
    {
        $mapping = $this->placed();
        $orderId = (int) $this->dispatcher()->dispatch($mapping)->internalOrderId;

        $created = app(FulfillmentRouter::class)->route($orderId);

        $this->assertCount(1, $created);
        $fulfillment = ProviderFulfillment::where('order_id', $orderId)->firstOrFail();
        $this->assertSame([ProviderCode::TorodPoint->value, 1], [$fulfillment->provider, $fulfillment->items()->count()]);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-TOROD-1', providerInternalId: '55', trackingNumber: 'TRK-TOROD-1')];

        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true]);
        $outcome = app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame([FulfillmentStatus::Submitted, 'O-TOROD-1'], [$fulfillment->fresh()->status, $fulfillment->fresh()->provider_reference]);
        $this->assertSame(1, count($this->torod->submitted));
        $this->assertSame(1, ProviderFulfillment::count());
    }

    public function test_a_column_the_platforms_table_insists_on_is_found_before_anything_is_written(): void
    {
        Schema::create('probe_orders', static function (Blueprint $table): void {
            $table->id();
            $table->string('must_have');
            $table->string('has_default')->default('x');
            $table->string('may_be_empty')->nullable();
        });
        $materializer = app(ChannelOrderMaterializer::class);

        $this->assertSame(['must_have'], $materializer->unfilledRequiredColumns('probe_orders', ['has_default' => 'y']));
        $this->assertSame([], $materializer->unfilledRequiredColumns('probe_orders', ['must_have' => 'z']));
    }

    public function test_only_the_buyer_registered_operations_read_the_recipient(): void
    {
        $this->assertTrue(ChannelOperations::readsBuyerData('trendyol.order_recipient'));
        $this->assertFalse(ChannelOperations::readsBuyerData('trendyol.orders_list'));
    }
}
