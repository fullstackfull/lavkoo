<?php

namespace Tests\Feature\Concurrency;

use App\Models\ExternalOrderMapping;
use App\Models\ExternalOrderLine;
use App\Models\InventoryReservation;
use App\Services\SalesChannels\Inventory\PoolNet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\Concurrency\ConcurrentRunner;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * THE SAME noon FBPI order arriving from several places at the same instant.
 *
 * This is not a hypothetical. noon's own page says it sends a webhook whenever the marketplace creates an order, and `fbpi-orders/list` returns that same order to the next
 * sweep. So two or three processes really do map and ingest one order within milliseconds of each other, and nothing in noon's API makes that safe: there is no order
 * version, no ETag, no "if-not-exists". Whether it ends as ONE order holding ONE set of units, or as two orders holding the stock twice, is decided entirely here.
 *
 * A double hold is the expensive failure: the units are gone from the shared pool, so the storefront and every other channel are told there is less stock than there is,
 * and nothing ever gives it back.
 *
 * Needs a real MySQL or MariaDB — SQLite in memory is private to one process, so there is nothing to race.
 */
class NoonOrderConcurrencyTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 71;

    private const UNITS = 3;

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

    public function test_one_noon_order_read_by_several_processes_at_once_is_one_order_holding_one_set_of_units(): void
    {
        $account = $this->noonAccount();
        $this->storeProduct(self::PRODUCT, 'torod_point', 500);
        $this->providerFigure(self::PRODUCT, 400, now()->subMinutes(30));
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'SKU-1');

        // Five processes, one order: the webhook, two sweeps and two retries, all at the same instant.
        $reports = ConcurrentRunner::run('noon.ingest_same_order', array_fill(0, 5, [
            'account' => $account->id, 'order_id' => 'NO-RACE-1', 'sku' => 'SKU-1', 'units' => self::UNITS,
        ]));

        $this->assertSame([], $this->problemsOf($reports), 'no process may fail and none may deadlock');
        $this->assertSame(1, ExternalOrderMapping::where('external_order_id', 'NO-RACE-1')->count(), 'one order');
        $this->assertSame(1, InventoryReservation::count(), 'one hold, not five');
        $this->assertSame(400 - self::UNITS, app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net(), 'the units leave the pool exactly once');
        $this->assertSame(1, count($reports) - count(array_filter($reports, static fn (array $report): bool => ($report['result']['outcome'] ?? '') !== 'created')), 'exactly one process created it');
    }

    public function test_the_units_of_a_noon_order_become_one_line_per_sku_however_many_processes_map_it(): void
    {
        // noon publishes no quantity: the order carries three `items[]` entries of one SKU, and the mapper turns them into one line of three. Several processes doing that
        // at once must not leave three lines of one, nor one line of nine.
        $account = $this->noonAccount();
        $this->storeProduct(self::PRODUCT, 'torod_point', 500);
        $this->providerFigure(self::PRODUCT, 400, now()->subMinutes(30));
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'SKU-1');

        $reports = ConcurrentRunner::run('noon.ingest_same_order', array_fill(0, 4, [
            'account' => $account->id, 'order_id' => 'NO-RACE-2', 'sku' => 'SKU-1', 'units' => self::UNITS,
        ]));

        $this->assertSame([], $this->problemsOf($reports));
        $mapping = ExternalOrderMapping::where('external_order_id', 'NO-RACE-2')->firstOrFail();
        $lines = ExternalOrderLine::where('mapping_id', $mapping->id)->get();
        $this->assertCount(1, $lines, 'one line, because the three units are one SKU');
        $this->assertSame(self::UNITS, (int) $lines->first()->quantity);
    }

    public function test_different_noon_orders_read_at_once_neither_deadlock_nor_lose_a_hold(): void
    {
        // The other half of the sweep: a page of noon orders read by two processes that overlap. Two writers both finding an order missing must not deadlock on the gap
        // where it will be.
        $account = $this->noonAccount();
        $this->storeProduct(self::PRODUCT, 'torod_point', 500);
        $this->providerFigure(self::PRODUCT, 400, now()->subMinutes(30));
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'SKU-1');

        $writers = [];
        foreach (range(1, 8) as $number) {
            // Each order twice, from two processes, interleaved.
            $writers[] = ['operation' => 'noon.ingest_same_order', 'arguments' => ['account' => $account->id, 'order_id' => 'NO-PAGE-' . $number, 'sku' => 'SKU-1', 'units' => 1]];
            $writers[] = ['operation' => 'noon.ingest_same_order', 'arguments' => ['account' => $account->id, 'order_id' => 'NO-PAGE-' . $number, 'sku' => 'SKU-1', 'units' => 1]];
        }

        $reports = ConcurrentRunner::run('noon.page', $writers);

        $this->assertSame([], $this->problemsOf($reports), 'no process may fail and none may deadlock');
        $this->assertSame(8, ExternalOrderMapping::count(), 'eight orders, each ingested twice');
        $this->assertSame(8, InventoryReservation::count(), 'eight holds');
        $this->assertSame(400 - 8, app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net());
    }

    /**
     * @param list<array{ok: bool, result?: mixed, error?: string, message?: string}> $reports
     * @return list<string>
     */
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
}
