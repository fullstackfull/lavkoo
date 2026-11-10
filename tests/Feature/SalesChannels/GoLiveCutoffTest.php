<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\OperatorActions;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\BuyerDataPurger;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The go-live cut-off: the platform never sends an order bought before the provider went live by itself, and that
 * answer is the same at every hour of the day.
 *
 * This class exists because of a failure these tests would have caught. The cut-off compares two stored instants —
 * the internal order's `created_at` and the provider's `go_live_at` — so for fixed data its answer never changes.
 * The dispatch tests, though, bought their orders at a calendar instant and stamped `go_live_at` from the real
 * clock, and the two crossed at 2026-11-10 09:00 Asia/Dhaka (03:00 UTC). Before it, eleven tests across five
 * classes passed; from it on, every shipment was held `BEFORE_GO_LIVE` and all eleven failed, which read as the
 * hour deciding the result. The hour decided nothing: a fixture had expired.
 *
 * So what is asserted here is both halves of the cut-off and its indifference to the clock — the guard stays, and
 * the suite can no longer tell what time it is.
 */
class GoLiveCutoffTest extends DispatchTestCase
{
    /** Takes the provider live now; `go_live_at` is stamped from the clock at this moment. */
    private function goLive(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true]);
    }

    /** An order bought at the given instant, routed as far as a provider shipment waiting to be sent. */
    private function orderBoughtAt(CarbonImmutable $purchasedAt, string $orderId = '10234567890', string $package = '3012345678'): array
    {
        $mapping = $this->placed(['purchasedAt' => $purchasedAt], orderId: $orderId, package: $package);
        $internalId = (int) $this->dispatcher()->dispatch($mapping)->internalOrderId;
        app(FulfillmentRouter::class)->route($internalId);

        return [$mapping->refresh(), ProviderFulfillment::where('order_id', $internalId)->firstOrFail(), $internalId];
    }

    private function send(ProviderFulfillment $fulfillment, string $reference = 'O-TOROD-1'): void
    {
        $this->torod->submitQueue = [SubmissionResult::accepted($reference, providerInternalId: '55', trackingNumber: 'TRK-' . $reference)];
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
    }

    public function test_the_world_clock_stands_before_the_orders_the_fixtures_buy(): void
    {
        $this->assertTrue(
            now()->lt(CarbonImmutable::parse(self::PURCHASED_AT)),
            'the dispatch world must open the provider before its orders are bought, or every shipment is held BEFORE_GO_LIVE',
        );
    }

    public function test_an_order_bought_after_the_provider_went_live_is_sent(): void
    {
        $this->goLive();
        [, $fulfillment] = $this->orderBoughtAt(CarbonImmutable::parse(self::PURCHASED_AT));

        $this->send($fulfillment);

        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
        $this->assertNull($fulfillment->fresh()->hold_reason);
        $this->assertNotSame([], $this->torod->calls, 'the shipment reached the provider');
    }

    public function test_an_order_bought_before_the_provider_went_live_is_held_and_nothing_is_sent(): void
    {
        [, $fulfillment] = $this->orderBoughtAt(now()->toImmutable()->subDays(3));
        $this->goLive();

        $this->send($fulfillment);

        $this->assertSame(HoldReason::BEFORE_GO_LIVE, $fulfillment->fresh()->hold_reason);
        $this->assertSame(FulfillmentStatus::Held, $fulfillment->fresh()->status);
        $this->assertSame([], $this->torod->calls, 'nothing may go to the provider for an order it never agreed to carry');
    }

    public function test_only_a_person_releases_an_order_bought_before_go_live(): void
    {
        [, $fulfillment] = $this->orderBoughtAt(now()->toImmutable()->subDays(3));
        $this->goLive();
        $this->send($fulfillment);
        $this->assertSame(HoldReason::BEFORE_GO_LIVE, $fulfillment->fresh()->hold_reason);

        // A sweep is not a person: the hold stays, however many times the order comes round again.
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);
        $this->assertSame([], $this->torod->calls);
        $this->assertSame(HoldReason::BEFORE_GO_LIVE, $fulfillment->fresh()->hold_reason);

        $this->torod->submitQueue = [SubmissionResult::accepted('O-RELEASED', providerInternalId: '55', trackingNumber: 'TRK-O-RELEASED')];
        $outcome = app(OperatorActions::class)->release($fulfillment->id);

        $this->assertSame(SubmissionResult::ACCEPTED, $outcome);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->fresh()->status);
    }

    /**
     * The instants the handoff measured, as offsets from the world clock. 22:00Z and 00:20Z fell before the fixture's
     * purchase instant and passed; 05:00Z fell after it and failed. The far offsets stand for any later run.
     *
     * @return iterable<string, array{int}>
     */
    public static function hoursTheSuiteMightRunAt(): iterable
    {
        yield 'at the world clock' => [0];
        yield 'an hour on, at the instant the orders are bought' => [1];
        yield 'three hours on, past the instant that used to break it' => [3];
        yield 'a day on' => [24];
        yield 'a month on' => [24 * 30];
    }

    #[DataProvider('hoursTheSuiteMightRunAt')]
    public function test_the_cut_off_gives_the_same_answer_whatever_the_hour(int $hoursOn): void
    {
        $this->travel($hoursOn)->hours();
        $boughtAfter = now()->toImmutable()->addHour();

        $this->goLive();
        [, $sent] = $this->orderBoughtAt($boughtAfter, '10234567891', '3012345671');
        [, $held] = $this->orderBoughtAt(now()->toImmutable()->subDays(3), '10234567892', '3012345672');

        $this->send($sent);
        $this->send($held, 'O-TOROD-2');

        $this->assertSame([FulfillmentStatus::Submitted, null], [$sent->fresh()->status, $sent->fresh()->hold_reason], 'bought after go-live: sent at any hour');
        $this->assertSame([FulfillmentStatus::Held, HoldReason::BEFORE_GO_LIVE], [$held->fresh()->status, $held->fresh()->hold_reason], 'bought before go-live: held at any hour');
    }

    #[DataProvider('hoursTheSuiteMightRunAt')]
    public function test_a_shipment_the_provider_took_loses_the_buyers_details_at_the_same_age_whatever_the_hour(int $hoursOn): void
    {
        $this->travel($hoursOn)->hours();
        $this->goLive();
        [$mapping, $fulfillment] = $this->orderBoughtAt(now()->toImmutable()->addHour());
        $this->send($fulfillment);
        app(ManagedShipmentMirror::class)->mirror($mapping);

        $this->assertSame(0, app(BuyerDataPurger::class)->run()[BuyerDataPurger::PURGED], 'inside the grace period the details stay');
        $this->assertStringContainsString(self::NAME, $this->shippingAddress($mapping));

        $this->travel(25)->hours();

        $this->assertSame(1, app(BuyerDataPurger::class)->run()[BuyerDataPurger::PURGED]);
        $this->assertStringNotContainsString(self::NAME, $this->shippingAddress($mapping));
        $this->assertNotNull(ChannelOrderDispatch::firstOrFail()->buyer_data_purged_at);
        foreach ([self::NAME, self::PHONE_NATIONAL, self::STREET] as $detail) {
            $this->assertStringNotContainsString($detail, $this->storedOutsideTheOrder() . json_encode(ProviderFulfillment::all()));
        }
    }

    private function shippingAddress(ExternalOrderMapping $mapping): string
    {
        $orderId = ChannelOrderDispatch::where('mapping_id', $mapping->id)->value('internal_order_id') ?? $mapping->order_id;

        return (string) DB::table('orders')->where('id', $orderId)->value('shipping_address_data');
    }
}
