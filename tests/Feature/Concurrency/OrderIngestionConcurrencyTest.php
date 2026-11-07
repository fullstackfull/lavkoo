<?php

namespace Tests\Feature\Concurrency;

use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Services\SalesChannels\Inventory\PoolNet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * Many DIFFERENT new orders read by several processes at once (a poller's page, a webhook, a second poller) while the pool they hold units of is divided. Each order is one mapping and
 * one hold, none is lost, and none of the processes fails: two writers that both find an order missing must not deadlock on the gap where it will be. Needs a real engine.
 */
class OrderIngestionConcurrencyTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private const ORDERS_PER_WRITER = 15;

    private const UNITS_PER_ORDER = 2;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ConcurrentRunner::available()) {
            $this->markTestSkipped('Needs a real MySQL or MariaDB: SQLite in memory is private to one process.');
        }
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Cache::flush();
    }

    /** @param list<array{ok: bool, result?: mixed, error?: string, message?: string}> $reports @return list<string> */
    private function problemsOf(array $reports): array
    {
        $problems = [];
        foreach ($reports as $index => $report) {
            if (!$report['ok']) {
                $problems[] = "worker {$index}: " . ($report['error'] ?? '') . ' ' . ($report['message'] ?? '');
            }
            foreach (($report['result']['other'] ?? []) as $line) {
                $problems[] = "worker {$index}: {$line}";
            }
        }

        return $problems;
    }

    public function test_many_different_new_orders_read_together_while_the_pool_is_divided_neither_deadlock_nor_lose_a_hold(): void
    {
        $first = $this->trendyolAccount(sellerId: '1234567');
        $second = $this->trendyolAccount(sellerId: '7654321');
        $this->storeProduct(self::PRODUCT, 'torod_point', 500);
        $this->providerFigure(self::PRODUCT, 400, now()->subMinutes(30));
        $this->stockListing($first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $location = $this->torodLocation()->id;
        $writers = [];
        foreach ([['p1', $first, 'BAR-A'], ['p2', $first, 'BAR-A'], ['p3', $second, 'BAR-B'], ['p4', $second, 'BAR-B']] as [$prefix, $account, $listingKey]) {
            $writers[] = ['account' => $account->id, 'listing_key' => $listingKey, 'prefix' => $prefix, 'count' => self::ORDERS_PER_WRITER, 'quantity' => self::UNITS_PER_ORDER];
        }

        $alone = ConcurrentRunner::run('stock.ingest_many', $writers);
        $withDivisions = ConcurrentRunner::run('stock.mixed', [
            ...array_map(static fn (array $writer): array => ['operation' => 'stock.ingest_many', 'arguments' => ['prefix' => 'q' . substr($writer['prefix'], 1)] + $writer], $writers),
            ...array_fill(0, 2, ['operation' => 'stock.allocate_repeatedly', 'arguments' => ['location' => $location, 'product' => self::PRODUCT, 'times' => 60, 'pause_microseconds' => 2000]]),
        ]);

        $this->assertSame([], $this->problemsOf([...$alone, ...$withDivisions]), 'no process may fail');
        $orders = 2 * count($writers) * self::ORDERS_PER_WRITER;
        $this->assertSame($orders, ExternalOrderMapping::count());
        $this->assertSame($orders, InventoryReservation::count());
        $this->assertSame(400 - self::UNITS_PER_ORDER * $orders, app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net());
    }
}
