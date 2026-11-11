<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * An order that left in MORE THAN ONE BOX, and what the channel is told about each.
 *
 * `FulfillmentRouter` groups a customer's items BY PROVIDER, so an order with some items at Torod Point and some at M5AZN becomes two `provider_fulfillments` with two
 * tracking numbers. Only one of them is mirrored onto the channel order's own `tracking_number`.
 *
 * Before this work the notifier sent EVERY active line of the order under that one mirrored number. Amazon was therefore told that units which were still in the other
 * provider's box had shipped, under a tracking number that would never cover them — and the buyer was shown one parcel for an order that was really two. The second box was
 * then never confirmed at all, because the record kept one tracking number for the whole order and the second parcel looked like a repeat of the first.
 *
 * Every assertion here is about that: one confirmation per box, each naming only its own units, each remembered on its own.
 */
class SplitShipmentConfirmationTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const CONFIRM = self::SP . '/orders/v0/orders/*';
    private const TOROD_PRODUCT = 81;
    private const M5AZN_PRODUCT = 82;
    private const ORDER = 5100;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->amazonAccount();
        $this->storeProduct(self::TOROD_PRODUCT, provider: 'torod_point');
        $this->storeProduct(self::M5AZN_PRODUCT, provider: 'm5azn');
    }

    public function test_each_parcel_is_confirmed_with_only_the_units_that_are_in_it(): void
    {
        $this->fake();
        $mapping = $this->orderInTwoBoxes();

        $outcome = app(ChannelShipmentNotifier::class)->notify($mapping);

        $this->assertSame(ChannelShipmentNotifier::SENT, $outcome['outcome']);
        $confirmations = $this->confirmations();
        $this->assertCount(2, $confirmations, 'two boxes, two confirmations: one of them used to be silently dropped');

        $byTracking = [];
        foreach ($confirmations as $body) {
            $byTracking[$body['packageDetail']['trackingNumber']] = $body['packageDetail']['orderItems'];
        }
        ksort($byTracking);

        $this->assertSame(['TRK-M5', 'TRK-TP'], array_keys($byTracking));
        $this->assertSame([['orderItemId' => 'LINE-M', 'quantity' => 1]], $byTracking['TRK-M5'], 'the M5AZN box holds its own line and nothing else');
        $this->assertSame([['orderItemId' => 'LINE-T', 'quantity' => 2]], $byTracking['TRK-TP'], 'and the Torod box holds its own');
    }

    public function test_no_confirmation_claims_a_unit_that_is_in_the_other_box(): void
    {
        $this->fake();

        app(ChannelShipmentNotifier::class)->notify($this->orderInTwoBoxes());

        foreach ($this->confirmations() as $body) {
            $lines = array_column($body['packageDetail']['orderItems'], 'orderItemId');
            $this->assertCount(1, $lines, 'a box that names both lines is the defect this test exists for: ' . json_encode($lines));
        }
        // Three units were sold in all; across the two confirmations exactly three are claimed, once each.
        $claimed = 0;
        foreach ($this->confirmations() as $body) {
            $claimed += array_sum(array_column($body['packageDetail']['orderItems'], 'quantity'));
        }
        $this->assertSame(3, $claimed, 'every unit confirmed exactly once: not twice, and none left out');
    }

    public function test_running_again_confirms_neither_box_a_second_time(): void
    {
        $this->fake();
        $mapping = $this->orderInTwoBoxes();
        app(ChannelShipmentNotifier::class)->notify($mapping);
        $this->assertCount(2, $this->confirmations());

        $again = app(ChannelShipmentNotifier::class)->notify($mapping->refresh()->load('lines', 'account'));

        $this->assertSame([ChannelShipmentNotifier::SKIPPED, 'already_notified'], [$again['outcome'], $again['reason']]);
        $this->assertCount(2, $this->confirmations(), 'still two: a second pass sends nothing');
    }

    public function test_the_record_names_each_parcel_so_the_second_box_is_not_mistaken_for_a_repeat_of_the_first(): void
    {
        $this->fake();
        $mapping = $this->orderInTwoBoxes();

        app(ChannelShipmentNotifier::class)->notify($mapping);

        $parcels = (array) ($mapping->refresh()->snapshot['channel_notified']['parcels'] ?? []);
        $this->assertCount(2, $parcels, 'one entry per parcel, not one tracking number for the whole order');
        $this->assertSame('TRK-TP', $parcels[self::ORDER . '-TP1'] ?? null);
        $this->assertSame('TRK-M5', $parcels[self::ORDER . '-MZ1'] ?? null);
    }

    public function test_a_box_the_channel_refuses_does_not_stop_the_other_being_confirmed(): void
    {
        // Amazon takes the first confirmation and refuses the second.
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'token_type' => 'bearer', 'expires_in' => 3600]),
            self::CONFIRM => Http::sequence()
                ->push('', 204)
                ->push(['errors' => [['message' => 'Invalid order item']]], 400),
        ]);
        $mapping = $this->orderInTwoBoxes();

        $outcome = app(ChannelShipmentNotifier::class)->notify($mapping);

        $this->assertSame(ChannelShipmentNotifier::FAILED, $outcome['outcome'], 'something still has to happen, so the pass is a failure and not a success');
        $this->assertCount(2, $this->confirmations(), 'both were attempted: one bad box does not stop the next');
        $mapping->refresh();
        $this->assertCount(1, (array) ($mapping->snapshot['channel_notified']['parcels'] ?? []), 'the one that worked is recorded');
        $this->assertCount(1, (array) ($mapping->snapshot['channel_notify_error']['parcels'] ?? []), 'and the one that did not is recorded against itself');
    }

    public function test_the_refused_box_is_tried_again_while_the_confirmed_one_is_left_alone(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'token_type' => 'bearer', 'expires_in' => 3600]),
            self::CONFIRM => Http::sequence()->push('', 204)->push(['errors' => []], 400)->push('', 204),
        ]);
        $mapping = $this->orderInTwoBoxes();
        app(ChannelShipmentNotifier::class)->notify($mapping);

        $outcome = app(ChannelShipmentNotifier::class)->notify($mapping->refresh()->load('lines', 'account'));

        $this->assertSame(ChannelShipmentNotifier::SENT, $outcome['outcome']);
        $this->assertCount(3, $this->confirmations(), 'the confirmed box was not asked again; only the refused one was');
        $this->assertCount(2, (array) ($mapping->refresh()->snapshot['channel_notified']['parcels'] ?? []));
    }

    public function test_an_order_with_one_provider_is_still_one_confirmation(): void
    {
        $this->fake();
        $mapping = $this->orderInOneBox();

        $outcome = app(ChannelShipmentNotifier::class)->notify($mapping);

        $this->assertSame(ChannelShipmentNotifier::SENT, $outcome['outcome']);
        $this->assertCount(1, $this->confirmations(), 'nothing about the single-parcel case changed');
        $this->assertSame([['orderItemId' => 'LINE-T', 'quantity' => 2]], $this->confirmations()[0]['packageDetail']['orderItems']);
    }

    public function test_a_parcel_the_provider_has_not_accepted_is_not_confirmed(): void
    {
        $this->fake();
        $mapping = $this->orderInTwoBoxes(m5aznStatus: FulfillmentStatus::Pending);

        app(ChannelShipmentNotifier::class)->notify($mapping);

        $confirmations = $this->confirmations();
        $this->assertCount(1, $confirmations, 'a shipment the provider may never take is nothing to tell a marketplace about');
        $this->assertSame('TRK-TP', $confirmations[0]['packageDetail']['trackingNumber']);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function fake(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|token', 'token_type' => 'bearer', 'expires_in' => 3600]),
            self::CONFIRM => Http::response('', 204),
        ]);
    }

    /** @return list<array<string, mixed>> the body of every confirmShipment POST, in order */
    private function confirmations(): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/shipmentConfirmation'))
            ->map(static fn (array $pair): array => (array) $pair[0]->data())
            ->values()
            ->all();
    }

    /** Two units of a Torod product and one of an M5AZN product, in two accepted parcels with their own tracking numbers. */
    private function orderInTwoBoxes(FulfillmentStatus $m5aznStatus = FulfillmentStatus::Submitted): ExternalOrderMapping
    {
        $mapping = $this->channelOrderWithTwoLines();

        $this->parcel(self::ORDER . '-TP1', 'torod_point', 1, FulfillmentStatus::Submitted, 'TRK-TP', [[self::TOROD_PRODUCT, 2, 9001]]);
        $this->parcel(self::ORDER . '-MZ1', 'm5azn', 1, $m5aznStatus, 'TRK-M5', [[self::M5AZN_PRODUCT, 1, 9002]]);

        return $mapping->refresh()->load('lines', 'account');
    }

    private function orderInOneBox(): ExternalOrderMapping
    {
        $mapping = $this->channelOrderWithTwoLines(onlyTorod: true);
        $this->parcel(self::ORDER . '-TP1', 'torod_point', 1, FulfillmentStatus::Submitted, 'TRK-TP', [[self::TOROD_PRODUCT, 2, 9001]]);

        return $mapping->refresh()->load('lines', 'account');
    }

    private function channelOrderWithTwoLines(bool $onlyTorod = false): ExternalOrderMapping
    {
        DB::table('orders')->insert(['id' => self::ORDER, 'customer_id' => 1, 'order_status' => 'confirmed', 'payment_status' => 'paid', 'order_amount' => 30, 'created_at' => now(), 'updated_at' => now()]);

        $mapping = ExternalOrderMapping::create([
            'account_id' => $this->account->id,
            'sales_channel' => 'amazon',
            'external_order_id' => 'AMZ-5100',
            'external_package_id' => '',
            'status' => 'new',
            'order_id' => self::ORDER,
            'provider' => 'torod_point',
            'carrier' => 'SMSA',
            'shipment_link_state' => ExternalOrderMapping::LINK_LINKED,
            'shipment_reference' => 'TOR-1',
            'tracking_number' => 'TRK-TP',
            'shipped_at' => now(),
        ]);

        $lines = [['LINE-T', self::TOROD_PRODUCT, 2]];
        if (!$onlyTorod) {
            $lines[] = ['LINE-M', self::M5AZN_PRODUCT, 1];
        }
        foreach ($lines as [$lineId, $productId, $quantity]) {
            DB::table('external_order_lines')->insert([
                'mapping_id' => $mapping->id, 'external_line_id' => $lineId, 'product_id' => $productId, 'variant_key' => '',
                'title' => 'Product ' . $productId, 'quantity' => $quantity, 'unit_price' => 10, 'line_total' => 10 * $quantity,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $mapping;
    }

    /** @param list<array{0: int, 1: int, 2: int}> $items product id, quantity, order_detail_id */
    private function parcel(string $reference, string $provider, int $generation, FulfillmentStatus $status, string $tracking, array $items): void
    {
        $fulfillmentId = DB::table('provider_fulfillments')->insertGetId([
            'reference' => $reference, 'order_id' => self::ORDER, 'provider' => $provider, 'generation' => $generation,
            'idempotency_key' => sha1($reference), 'status' => $status->value, 'tracking_number' => $tracking,
            'submitted_at' => now(), 'shipped_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($items as [$productId, $quantity, $detailId]) {
            DB::table('order_details')->insert([
                'id' => $detailId, 'order_id' => self::ORDER, 'product_id' => $productId, 'qty' => $quantity, 'price' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('provider_fulfillment_items')->insert([
                'provider_fulfillment_id' => $fulfillmentId, 'order_id' => self::ORDER, 'order_detail_id' => $detailId, 'product_id' => $productId,
                'variant_key' => '', 'provider' => $provider, 'name' => 'Product ' . $productId, 'quantity' => $quantity,
                'status' => 'routed', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
