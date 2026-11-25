<?php

namespace Tests\Feature\Monitoring;

use App\Models\ChannelStockEvent;
use App\Models\InventoryReservation;
use App\Models\ProviderFulfillmentItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * `monitoring:reconcile-stock` compares every hold on stock, every change in the stock outbox and every provider's own count with what
 * made them, and reports what cannot be justified without changing any of it (M2-V6).
 *
 * The cases are written around the findings a person would act on, and each is paired with the row that must NOT be reported: a dead
 * outbox row WITH a failure code beside the one without, a hold of an open order beside the hold of a cancelled one. A reconciler that
 * reports everything is as useless as one that reports nothing.
 */
class StockReconciliationTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const PROVIDER = 'torod_point';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSalesChannelSchema();
    }

    public function test_with_nothing_to_find_it_says_so_and_proves_it_wrote_nothing(): void
    {
        $report = $this->reconcile();

        $this->assertSame(0, $report['exit'], 'a database with nothing wrong must not block a backfill');
        $this->assertSame('pass', $this->statusOf($report, 'nothing was written'));
        $this->assertTrue($report['read_only_proof']['session_locked'], 'the session must be locked read-only for the whole run');
        $this->assertSame(0, $report['read_only_proof']['write_statements']);
        $this->assertGreaterThan(0, $report['read_only_proof']['statements'], 'a run that read nothing proves nothing');
        $this->assertSame('pass', $this->statusOf($report, 'holds with nothing behind them'));
        $this->assertSame('pass', $this->statusOf($report, 'storefront offers more than the provider has'));
    }

    public function test_a_hold_that_names_neither_an_order_nor_a_channel_order_is_reported(): void
    {
        $this->product(id: 70, stock: 5);
        $this->hold(product: 70, quantity: 3, state: InventoryReservation::RESERVED, orderId: null);

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'holds with nothing behind them'));
        $this->assertSame(1, $this->facts($report, 'holds with nothing behind them')['rows']);
        $this->assertSame(1, $this->facts($report, 'holds by state')['holding']);
        $this->assertSame(1, $report['exit'], 'a hold nothing justifies must block a backfill');
    }

    public function test_a_hold_whose_order_is_finished_is_reported_and_a_hold_of_an_open_order_is_not(): void
    {
        $this->product(id: 71, stock: 5);
        $this->order(id: 910, status: 'canceled');
        $this->order(id: 911, status: 'pending');
        $this->hold(product: 71, quantity: 2, state: InventoryReservation::RESERVED, orderId: 910);
        $this->hold(product: 71, quantity: 2, state: InventoryReservation::RESERVED, orderId: 911);

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'holds the order no longer justifies'));
        $this->assertSame(1, $this->facts($report, 'holds the order no longer justifies')['rows'], 'only the cancelled order\'s hold is stuck');
        $this->assertSame('pass', $this->statusOf($report, 'holds whose order is gone'));
    }

    public function test_a_hold_whose_order_was_deleted_is_reported_and_a_released_one_is_not(): void
    {
        $this->product(id: 72, stock: 5);
        $this->hold(product: 72, quantity: 2, state: InventoryReservation::RESERVED, orderId: 920);
        $this->hold(product: 72, quantity: 2, state: InventoryReservation::RELEASED, orderId: 921);

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'holds whose order is gone'));
        $this->assertSame(1, $this->facts($report, 'holds whose order is gone')['rows'], 'a released hold holds nothing, so an order that is gone is no longer a problem');
    }

    public function test_a_dead_outbox_row_without_a_reason_is_reported_and_one_with_a_reason_is_not(): void
    {
        $this->outboxRow(state: ChannelStockEvent::DEAD, failureCode: null);
        $this->outboxRow(state: ChannelStockEvent::DEAD, failureCode: 'listing_not_found');

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'dead outbox rows with no reason'));
        $this->assertSame(1, $this->facts($report, 'dead outbox rows with no reason')['rows']);
        $this->assertSame(2, $this->facts($report, 'outbox by state')['by_state'][ChannelStockEvent::DEAD]);
    }

    public function test_an_outbox_row_marked_worked_without_an_outcome_is_reported(): void
    {
        $this->outboxRow(state: ChannelStockEvent::WORKED, failureCode: null, outcome: null);
        $this->outboxRow(state: ChannelStockEvent::WORKED, failureCode: null, outcome: ChannelStockEvent::SENT);

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'worked outbox rows with no outcome'));
        $this->assertSame(1, $this->facts($report, 'worked outbox rows with no outcome')['rows']);
    }

    public function test_a_claim_nobody_finished_is_a_warning_and_a_fresh_claim_is_not(): void
    {
        $this->outboxRow(state: ChannelStockEvent::CLAIMED, failureCode: null, claimedAt: now()->subDay());
        $this->outboxRow(state: ChannelStockEvent::CLAIMED, failureCode: null, claimedAt: now()->subMinute());

        $report = $this->reconcile();

        $this->assertSame('warn', $this->statusOf($report, 'outbox rows claimed and never worked'));
        $this->assertSame(1, $this->facts($report, 'outbox rows claimed and never worked')['rows']);
        $this->assertSame(0, $report['exit'], 'a stale claim is worth looking at and does not block a backfill');
    }

    public function test_a_storefront_that_offers_more_than_the_provider_has_is_reported_with_the_units(): void
    {
        $this->product(id: 80, stock: 12, provider: self::PROVIDER);
        $this->mapping(product: 80, providerStock: 9);

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'storefront offers more than the provider has'));
        $facts = $this->facts($report, 'storefront offers more than the provider has');
        $this->assertSame(1, $facts['products']);
        $this->assertSame(3, $facts['units']);
        $this->assertSame(80, $facts['rows'][0]['product_id']);
        $this->assertSame(9, $facts['rows'][0]['provider_stock']);
        $this->assertSame(12, $facts['rows'][0]['offered']);
        $this->assertSame(1, $report['exit']);
    }

    public function test_a_storefront_that_offers_less_than_the_provider_has_is_stated_and_is_not_a_failure(): void
    {
        $this->product(id: 81, stock: 4, provider: self::PROVIDER);
        $this->mapping(product: 81, providerStock: 9);

        $report = $this->reconcile();

        $this->assertSame('pass', $this->statusOf($report, 'storefront offers more than the provider has'));
        $this->assertSame('info', $this->statusOf($report, 'storefront offers less than the provider has'));
        $this->assertSame(1, $this->facts($report, 'storefront offers less than the provider has')['products']);
        $this->assertSame(5, $this->facts($report, 'storefront offers less than the provider has')['units']);
        $this->assertSame(0, $report['exit']);
    }

    /**
     * The point of asking the writer for the promised units rather than restating its equation: an order the provider has not taken on
     * yet lowers what the provider has left, so a figure that reconciled becomes one that does not. The storefront's own stock is
     * untouched between the two runs -- only the promise is new.
     */
    public function test_an_order_the_provider_has_not_taken_on_lowers_what_is_left_to_offer(): void
    {
        $this->product(id: 82, stock: 9, provider: self::PROVIDER);
        $this->mapping(product: 82, providerStock: 9);

        $this->assertSame('pass', $this->statusOf($this->reconcile(), 'storefront offers more than the provider has'));

        $this->order(id: 930, status: 'pending');
        $this->promise(order: 930, product: 82, quantity: 4);

        $report = $this->reconcile();

        $this->assertSame('fail', $this->statusOf($report, 'storefront offers more than the provider has'));
        $facts = $this->facts($report, 'storefront offers more than the provider has');
        $this->assertSame(4, $facts['rows'][0]['promised'], 'the promised units are the ones the fulfillment service itself counts');
        $this->assertSame(4, $facts['units'], 'nine offered against nine less four promised is four units too many');
    }

    public function test_a_mapping_whose_product_belongs_to_another_provider_is_counted_as_not_compared(): void
    {
        $this->product(id: 83, stock: 99, provider: 'm5azn');
        $this->mapping(product: 83, providerStock: 1);

        $report = $this->reconcile();

        $this->assertSame('pass', $this->statusOf($report, 'storefront offers more than the provider has'));
        $facts = $this->facts($report, 'provider figures compared');
        $this->assertSame(0, $facts['compared']);
        $this->assertSame(1, $facts['mappings'], 'the mapping is counted even though it was not compared');
        $this->assertSame(['the product is not assigned to this provider' => 1], $facts['skipped']);
    }

    public function test_a_table_that_is_not_there_is_reported_as_not_compared_and_never_as_nothing_found(): void
    {
        Schema::drop('channel_stock_events');

        $report = $this->reconcile();

        $this->assertSame('info', $this->statusOf($report, 'outbox not compared'));
        $this->assertNull($this->statusOf($report, 'dead outbox rows with no reason'), 'a table that is not there must not be reported as having no findings');
        $this->assertSame(0, $report['exit']);

        (require database_path('migrations/2026_11_13_100000_create_channel_stock_events_table.php'))->up();
    }

    /**
     * @return array{exit: int, checks: list<array<string, mixed>>, read_only_proof: array<string, mixed>}
     */
    private function reconcile(): array
    {
        $exit = Artisan::call('monitoring:reconcile-stock', ['--json' => true]);
        $output = Artisan::output();
        $report = json_decode($output, true);

        $this->assertIsArray($report, 'the command did not print a JSON report: ' . $output);

        return ['exit' => $exit, 'checks' => $report['checks'], 'read_only_proof' => $report['read_only_proof']];
    }

    /** @param array{checks: list<array<string, mixed>>} $report */
    private function statusOf(array $report, string $name): ?string
    {
        foreach ($report['checks'] as $check) {
            if ($check['name'] === $name) {
                return $check['status'];
            }
        }

        return null;
    }

    /**
     * @param array{checks: list<array<string, mixed>>} $report
     * @return array<string, mixed>
     */
    private function facts(array $report, string $name): array
    {
        foreach ($report['checks'] as $check) {
            if ($check['name'] === $name) {
                return $check['facts'] ?? [];
            }
        }

        $this->fail("the report holds no check named '{$name}'");
    }

    private function product(int $id, int $stock, ?string $provider = null): void
    {
        DB::table('products')->insert([
            'id' => $id,
            'name' => 'Product ' . $id,
            'current_stock' => $stock,
            'fulfillment_provider' => $provider,
            'variation' => '[]',
        ]);
    }

    private function mapping(int $product, int $providerStock, string $variantKey = ''): void
    {
        DB::table('provider_product_mappings')->insert([
            'provider' => self::PROVIDER,
            'product_id' => $product,
            'variant_key' => $variantKey,
            'external_id' => 'ext-' . $product,
            'provider_stock' => $providerStock,
            'provider_stock_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $id, string $status): void
    {
        DB::table('orders')->insert(['id' => $id, 'order_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** One order line the provider has not taken on yet: what `InventorySyncService` counts as promised. */
    private function promise(int $order, int $product, int $quantity): void
    {
        DB::table('provider_fulfillment_items')->insert([
            'order_id' => $order,
            'order_detail_id' => $order * 10 + $product,
            'product_id' => $product,
            'variant_key' => '',
            'provider' => self::PROVIDER,
            'name' => 'line',
            'quantity' => $quantity,
            'status' => ProviderFulfillmentItem::STATUS_UNROUTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hold(int $product, int $quantity, string $state, ?int $orderId): void
    {
        DB::table('inventory_reservations')->insert([
            'location_id' => 1,
            'product_id' => $product,
            'variant_key' => '',
            'quantity' => $quantity,
            'reservation_key' => 'hold-' . $product . '-' . ($orderId ?? 'none') . '-' . $state . '-' . DB::table('inventory_reservations')->count(),
            'state' => $state,
            'order_id' => $orderId,
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function outboxRow(string $state, ?string $failureCode, ?string $outcome = null, mixed $claimedAt = null): void
    {
        DB::table('channel_stock_events')->insert([
            'location_id' => 1,
            'product_id' => 90,
            'variant_key' => '',
            'reason' => ChannelStockEvent::MANUAL,
            'state' => $state,
            'failure_code' => $failureCode,
            'outcome' => $outcome,
            'claimed_at' => $claimedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
