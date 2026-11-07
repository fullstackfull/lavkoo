<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\DispatchState;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\BuyerDataPurger;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Support\Facades\DB;

/** The buyer's details live only as long as the shipment needs them. */
class BuyerDataPurgerTest extends DispatchTestCase
{
    private function purger(): BuyerDataPurger
    {
        return app(BuyerDataPurger::class);
    }

    /** @return array{0: ExternalOrderMapping, 1: ProviderFulfillment} a dispatched order with its (unsent) provider shipment, a payload preview and an error text that echo the buyer */
    private function routed(): array
    {
        $mapping = $this->placed();
        $orderId = (int) $this->dispatcher()->dispatch($mapping)->internalOrderId;
        app(FulfillmentRouter::class)->route($orderId);
        $fulfillment = ProviderFulfillment::where('order_id', $orderId)->firstOrFail();
        $fulfillment->forceFill(['meta' => ['payload_preview' => ['receiverName' => self::NAME], 'kept' => 'yes'], 'last_error_message' => 'Bad address ' . self::STREET])->save();

        return [$mapping->refresh(), $fulfillment->refresh()];
    }

    private function accepted(ProviderFulfillment $fulfillment, ExternalOrderMapping $mapping): void
    {
        $this->torod->submitQueue = [SubmissionResult::accepted('O-TOROD-1', providerInternalId: '55', trackingNumber: 'TRK-TOROD-1')];
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true]);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        app(ManagedShipmentMirror::class)->mirror($mapping);
    }

    private function address(ExternalOrderMapping $mapping): string
    {
        return (string) DB::table('orders')->where('id', $mapping->order_id ?? ChannelOrderDispatch::firstOrFail()->internal_order_id)->value('shipping_address_data');
    }

    public function test_an_accepted_shipment_keeps_the_details_for_the_grace_period_then_loses_them_everywhere(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $this->accepted($fulfillment, $mapping);

        $this->assertSame([BuyerDataPurger::PURGED => 0, BuyerDataPurger::RESET => 0, BuyerDataPurger::SKIPPED => 0], $this->purger()->run(), 'not yet');
        $this->assertStringContainsString(self::NAME, $this->address($mapping));

        $this->travel(25)->hours();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::PURGED]);

        $stored = json_decode($this->address($mapping), true);
        $this->assertSame(['city' => 'Riyadh', 'country' => 'SA', 'redacted' => true], $stored);
        $fulfillment->refresh();
        $this->assertSame([['kept' => 'yes'], null], [$fulfillment->meta, $fulfillment->last_error_message]);
        $this->assertNotNull(ChannelOrderDispatch::firstOrFail()->buyer_data_purged_at);
        foreach ([self::NAME, self::PHONE_NATIONAL, self::STREET] as $detail) {
            $this->assertStringNotContainsString($detail, $this->storedOutsideTheOrder() . json_encode(ProviderFulfillment::all()));
        }
        $this->assertSame(0, array_sum($this->purger()->run()), 'and once only');
        $this->assertSame(ProviderCode::TorodPoint->value, $mapping->refresh()->provider, 'the shipment itself is untouched');
    }

    public function test_a_rehearsal_that_has_run_its_time_wipes_the_details_closes_the_order_and_lets_the_channel_order_be_dispatched_again(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $firstOrderId = (int) $mapping->order_id;

        $this->travel(73)->hours();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::RESET]);

        $this->assertSame(['canceled', '{"city":"Riyadh","country":"SA","redacted":true}'], [DB::table('orders')->where('id', $firstOrderId)->value('order_status'), (string) DB::table('orders')->where('id', $firstOrderId)->value('shipping_address_data')]);
        $row = ChannelOrderDispatch::firstOrFail();
        $this->assertSame([null, DispatchState::Blocked->value, 'buyer_data_expired', 0], [$mapping->refresh()->order_id, $row->state, $row->reason_code, $row->attempts]);
        $this->assertSame(InventoryReservation::RESERVED, InventoryReservation::where('external_order_mapping_id', $mapping->id)->value('state'), 'the units are promised again');
        $this->assertSame(1, app(FulfillmentRecovery::class)->cancelForClosedOrders(), 'the platform drops the rehearsal shipment by its own rules');

        $second = $this->dispatcher()->dispatch($mapping->refresh());
        $this->assertSame('materialized', $second->state);
        $this->assertNotSame($firstOrderId, $second->internalOrderId);
        $this->assertStringContainsString(self::NAME, (string) DB::table('orders')->where('id', $second->internalOrderId)->value('shipping_address_data'), 'the buyer was read afresh');
    }

    public function test_a_shipment_whose_send_outcome_is_unknown_is_never_touched_and_one_the_provider_holds_is_wiped_in_place_never_reset(): void
    {
        [$mapping, $fulfillment] = $this->routed();
        $this->travel(80)->hours();

        $fulfillment->forceFill(['status' => FulfillmentStatus::ReconcilePending])->save();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::SKIPPED]);
        $fulfillment->forceFill(['status' => FulfillmentStatus::Dispatching])->save();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::SKIPPED]);
        $this->assertStringContainsString(self::NAME, $this->address($mapping));
        $fulfillment->forceFill(['status' => FulfillmentStatus::Submitted])->save();
        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::PURGED]);

        $this->assertNotNull($mapping->refresh()->order_id, 'the provider holds the shipment: the order stays what it is');
        $this->assertStringNotContainsString(self::NAME, $this->address($mapping));
    }

    public function test_a_cancelled_order_is_purged_after_the_grace_period_too(): void
    {
        [$mapping] = $this->routed();
        ChannelOrderDispatch::query()->update(['state' => DispatchState::Cancelled->value, 'closed_at' => now()]);

        $this->travel(25)->hours();

        $this->assertSame(1, $this->purger()->run()[BuyerDataPurger::PURGED]);
        $this->assertStringNotContainsString(self::NAME, $this->address($mapping));
    }

    public function test_the_purge_command_runs_with_every_dispatch_switch_off(): void
    {
        [$mapping] = $this->routed();
        config(['sales_channels.managed_dispatch_enabled' => false, 'sales_channels.buyer_data_transfer_enabled' => false]);
        $this->travel(80)->hours();

        $this->artisan('channels:purge-buyer-data')->expectsOutputToContain('reset 1')->assertSuccessful();

        $this->assertNull($mapping->refresh()->order_id);
    }
}
