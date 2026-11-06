<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use App\Services\SalesChannels\Sync\IngestResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

class ChannelOrderIngestorTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;

    private ChannelOrderIngestor $ingestor;

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->ingestor = app(ChannelOrderIngestor::class);
        $this->account = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'enabled' => true]);
    }

    private function link(string $listingKey, int $productId, string $variant = ''): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create(['account_id' => $this->account->id, 'listing_key' => $listingKey, 'external_barcode' => $listingKey, 'product_id' => $productId, 'variant_key' => $variant, 'status' => SalesChannelProductMapping::MAPPED]);
    }

    public function test_reading_the_same_order_twice_is_one_order(): void
    {
        $first = $this->ingestor->ingest($this->account, $this->remoteOrder());
        $second = $this->ingestor->ingest($this->account, $this->remoteOrder());

        $this->assertSame(IngestResult::CREATED, $first->outcome);
        $this->assertSame(IngestResult::UNCHANGED, $second->outcome);
        $this->assertSame(1, ExternalOrderMapping::count());
        $this->assertSame(1, ExternalOrderLine::count());
    }

    public function test_a_changed_order_keeps_what_the_application_recorded_in_its_snapshot(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Processing));
        $mapping = ExternalOrderMapping::firstOrFail();
        $mapping->forceFill(['snapshot' => $mapping->snapshot + [
            'channel_notified' => ['tracking' => 'TRK-1', 'at' => '2026-11-10T10:05:00+00:00'],
            'channel_notify_error' => ['error' => 'unknown carrier', 'at' => '2026-11-10T10:00:00+00:00'],
            'shipment_link' => ['previous' => ['provider' => null, 'carrier' => 'X'], 'by' => 1],
        ]])->save();
        $before = $mapping->fresh()->snapshot;

        $result = $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Shipped, updatedAt: '2026-11-10 11:00:00', overrides: ['isCod' => true]));

        $after = $result->mapping->snapshot;
        $this->assertSame(IngestResult::UPDATED, $result->outcome);
        $this->assertSame([$before['channel_notified'], $before['channel_notify_error'], $before['shipment_link']], [$after['channel_notified'], $after['channel_notify_error'], $after['shipment_link']]);
        $this->assertTrue($after['is_cod'], 'What the channel says is still refreshed.');
    }

    public function test_what_a_return_gave_back_survives_the_order_being_read_again_and_is_not_a_change_of_the_lines(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Delivered));
        $line = ExternalOrderLine::firstOrFail();
        $line->forceFill(['quantity_returned' => 1, 'snapshot' => ($line->snapshot ?? []) + ['returned_by_claim' => ['claim-1' => 1]]])->save();

        $result = $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Delivered, updatedAt: '2026-11-10 12:00:00', overrides: ['paymentMethod' => 'CreditCard']));

        $this->assertSame(['claim-1' => 1], $line->fresh()->snapshot['returned_by_claim']);
        $this->assertNotContains('lines_changed', $result->changes, 'Our own record on the line is not a change at the channel.');
    }

    public function test_ingesting_creates_no_internal_order_no_shipment_and_no_product(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder());

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, ProviderFulfillment::count());
        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_packages_of_one_order_are_separate_records(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder('ORD-1', '9001'));
        $this->ingestor->ingest($this->account, $this->remoteOrder('ORD-1', '9002'));

        $this->assertSame(2, ExternalOrderMapping::where('external_order_id', 'ORD-1')->count());
    }

    public function test_the_same_order_id_in_two_accounts_is_two_orders(): void
    {
        $other = SalesChannelAccount::create(['channel' => 'trendyol', 'name' => 'Second', 'enabled' => true]);

        $this->ingestor->ingest($this->account, $this->remoteOrder());
        $this->ingestor->ingest($other, $this->remoteOrder());

        $this->assertSame(2, ExternalOrderMapping::count());
    }

    public function test_an_older_version_of_an_order_never_overwrites_a_newer_one(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Shipped, updatedAt: '2026-11-10 12:00:00'));

        $late = $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::New, updatedAt: '2026-11-10 10:00:00'));

        $this->assertSame(IngestResult::STALE, $late->outcome);
        $this->assertSame('shipped', ExternalOrderMapping::first()->status);
    }

    public function test_a_status_that_would_move_an_order_backwards_is_ignored_but_a_cancellation_is_real(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Shipped, updatedAt: '2026-11-10 10:00:00'));

        $back = $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Processing, updatedAt: '2026-11-10 11:00:00', lines: [$this->remoteLine('L1', 'SKU-1', 'BAR-1', 2, '120')]));
        $this->assertTrue($back->has('status_regression_ignored'));
        $this->assertSame('shipped', ExternalOrderMapping::first()->status);

        $cancel = $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Cancelled, updatedAt: '2026-11-10 12:00:00'));
        $this->assertTrue($cancel->has('cancelled'));
        $this->assertSame('cancelled', ExternalOrderMapping::first()->status);
        $this->assertNotNull(ExternalOrderMapping::first()->cancelled_at);
    }

    public function test_a_change_in_amount_or_lines_is_reported_and_after_routing_it_waits_for_a_person(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder());
        ExternalOrderMapping::first()->forceFill(['execution_policy' => 'managed'])->save();

        $changed = $this->ingestor->ingest($this->account, $this->remoteOrder(updatedAt: '2026-11-10 11:00:00', lines: [$this->remoteLine('L1', 'SKU-1', 'BAR-1', 3, '150')]));

        $this->assertTrue($changed->has('amount_changed'));
        $this->assertTrue($changed->has('lines_changed'));
        $this->assertSame('modified_after_routing', ExternalOrderMapping::first()->review_reason);
        $this->assertSame(3, ExternalOrderLine::first()->quantity);
    }

    public function test_cancelling_an_order_that_already_has_a_shipment_flags_it_instead_of_acting_alone(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder());
        ExternalOrderMapping::first()->forceFill(['order_id' => 5001, 'execution_policy' => 'managed'])->save();

        $this->ingestor->ingest($this->account, $this->remoteOrder(status: ChannelOrderStatus::Cancelled, updatedAt: '2026-11-10 12:00:00'));

        $this->assertSame('cancelled_after_dispatch', ExternalOrderMapping::first()->review_reason);
    }

    public function test_lines_are_linked_to_products_by_the_listings_a_person_linked_and_the_rest_wait(): void
    {
        $this->link('BAR-1', 77);
        $order = $this->remoteOrder(lines: [$this->remoteLine('L1', 'SKU-1', 'BAR-1', 1, '50'), $this->remoteLine('L2', 'SKU-2', 'BAR-2', 1, '60')]);

        $result = $this->ingestor->ingest($this->account, $order);

        $this->assertSame([77, null], ExternalOrderLine::orderBy('id')->pluck('product_id')->all());
        $this->assertSame('unmapped_lines', $result->mapping->review_reason);

        $this->link('BAR-2', 78);
        $again = $this->ingestor->ingest($this->account, $order);
        $this->assertSame(IngestResult::UNCHANGED, $again->outcome);
        $this->assertNull(ExternalOrderMapping::first()->review_reason, 'Linking the last listing releases the order for routing.');
        $this->assertSame([77, 78], ExternalOrderLine::orderBy('id')->pluck('product_id')->all());
    }

    public function test_a_listing_that_is_only_suggested_is_not_a_link(): void
    {
        SalesChannelProductMapping::create(['account_id' => $this->account->id, 'listing_key' => 'BAR-1', 'product_id' => null, 'suggested_product_id' => 77, 'status' => SalesChannelProductMapping::SUGGESTED]);

        $this->ingestor->ingest($this->account, $this->remoteOrder());

        $this->assertNull(ExternalOrderLine::first()->product_id);
    }

    public function test_the_stored_copy_has_no_personal_details(): void
    {
        $order = $this->remoteOrder(overrides: ['snapshot' => ['customerEmail' => 'buyer@example.test', 'shipmentAddress' => ['phone' => '0555555555', 'fullAddress' => 'Somewhere 1'], 'orderNumber' => 'ORD-1']]);

        $this->ingestor->ingest($this->account, $order);

        $stored = json_encode(DB::table('external_order_mappings')->first());
        $this->assertStringNotContainsString('buyer@example.test', $stored);
        $this->assertStringNotContainsString('0555555555', $stored);
        $this->assertStringNotContainsString('Somewhere 1', $stored);
        $this->assertStringContainsString('Riyadh', $stored, 'The city needed to price a delivery is kept.');
    }

    public function test_a_split_package_is_marked_for_review_and_is_never_fulfilled(): void
    {
        $result = $this->ingestor->ingest($this->account, $this->remoteOrder('ORD-1', '9001', ChannelOrderStatus::Cancelled, overrides: ['externalStatus' => 'UnPacked']));

        $this->assertSame('split_replaced', $result->mapping->review_reason);
        $this->assertFalse($result->mapping->orderStatus()->allowsFulfilment());
    }

    public function test_cancelled_units_are_kept_on_the_line_and_do_not_count_as_active(): void
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder(lines: [$this->remoteLine('L1', 'SKU-1', 'BAR-1', 3, '90', cancelled: 1)]));

        $line = ExternalOrderLine::first();
        $this->assertSame(3, $line->quantity);
        $this->assertSame(2, $line->activeQuantity());
    }
}
