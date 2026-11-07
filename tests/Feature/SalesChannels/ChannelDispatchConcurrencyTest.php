<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ChannelOrderDispatch;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrentRunner;

/** Several processes at the same instant against a real MySQL or MariaDB: the claim and the materializer are what keep "once" true. Skipped on SQLite, which is private to one process. */
class ChannelDispatchConcurrencyTest extends DispatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
    }

    /** @param list<array<string, mixed>> $reports */
    private function outcomes(array $reports): array
    {
        return array_count_values(array_map(static fn (array $report): string => $report['ok'] ? $report['result']['state'] : 'error:' . $report['error'] . ' ' . ($report['message'] ?? ''), $reports));
    }

    public function test_eight_processes_dispatching_the_same_order_at_once_make_exactly_one_internal_order(): void
    {
        $mapping = $this->placed();

        $reports = ConcurrentRunner::run('dispatch_channel_order', array_fill(0, 8, ['mapping_id' => $mapping->id]));

        $outcomes = $this->outcomes($reports);
        $this->assertSame(1, $outcomes['materialized'] ?? 0, json_encode($outcomes));
        $this->assertSame(8, ($outcomes['materialized'] ?? 0) + ($outcomes['busy'] ?? 0) + ($outcomes['already_done'] ?? 0), 'every other process stood back or found the order made: ' . json_encode($outcomes));
        $this->assertSame([1, 1, 1, 1], [DB::table('orders')->count(), DB::table('order_details')->count(), ProviderFulfillmentItem::count(), ChannelOrderDispatch::count()]);
        $this->assertSame(1, ChannelOrderDispatch::firstOrFail()->attempts);
        $this->assertTrue(ConcurrentRunner::overlapped($reports), 'the processes really ran at the same time');
    }

    public function test_eight_different_orders_dispatched_at_once_each_get_exactly_one_internal_order(): void
    {
        $mappings = array_map(fn (int $number): int => $this->placed(orderId: '1023456789' . $number, package: '3012345678' . $number)->id, range(1, 8));

        $reports = ConcurrentRunner::run('dispatch_channel_order', array_map(static fn (int $id): array => ['mapping_id' => $id], $mappings));

        $outcomes = $this->outcomes($reports);
        $this->assertSame(['materialized' => 8], $outcomes, 'no deadlock or duplicate key got through: ' . json_encode(array_column($reports, 'result')));
        $this->assertSame([8, 8, 8], [DB::table('orders')->count(), ChannelOrderDispatch::whereNotNull('internal_order_id')->count(), count(array_unique(array_column(array_column($reports, 'result'), 'order_id')))]);
        $this->assertSame(8, ProviderFulfillmentItem::count());
    }

    public function test_the_same_accepted_shipment_mirrored_by_several_processes_at_once_is_linked_once(): void
    {
        $mapping = $this->placed();
        $orderId = (int) $this->dispatcher()->dispatch($mapping)->internalOrderId;
        app(FulfillmentRouter::class)->route($orderId);
        $this->torod->submitQueue = [SubmissionResult::accepted('O-TOROD-1', providerInternalId: '55', trackingNumber: 'TRK-TOROD-1')];
        $this->enableProvider(ProviderCode::TorodPoint, ['dry_run' => false, 'auto_dispatch' => true]);
        app(ProviderOrderDispatcher::class)->dispatch(ProviderFulfillment::where('order_id', $orderId)->firstOrFail()->id);

        $reports = ConcurrentRunner::run('mirror_managed_shipment', array_fill(0, 6, ['mapping_id' => $mapping->id]));

        $results = array_column($reports, 'result');
        $this->assertSame([1, 5], [count(array_keys($results, ManagedShipmentMirror::LINKED, true)), count(array_keys($results, ManagedShipmentMirror::UNTOUCHED, true))], json_encode($reports));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sales_channel.managed_shipment_linked')->count());
    }
}
