<?php

namespace Tests\Feature\Concurrency;

use App\Models\ChannelShipmentSend;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Services\SalesChannels\Inventory\PoolNet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
        $this->buildTheFakeNoonWarehouse();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_noon_shipments');
        parent::tearDown();
    }

    /**
     * noon's own side of a shipment, as a table the worker processes share.
     *
     * The workers are separate processes, so a `Http::fake` in the test process means nothing to them and there is no server to
     * point them at. What they do share is this database -- so `shipment/create` inserts here and `shipment/get` reads here, and
     * a SECOND shipment of one set of goods becomes a second ROW. No unique key: the fixture must show the duplicate, not
     * prevent it, or the test would be proving MySQL works rather than that this code does.
     */
    private function buildTheFakeNoonWarehouse(): void
    {
        Schema::dropIfExists('test_noon_shipments');
        Schema::create('test_noon_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 191)->index();
            $table->string('order_id', 191);
            $table->unsignedInteger('created_by');
        });
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

    // ---- shipping the same goods, from several processes at once -------------------------------------------------

    public function test_the_same_units_shipped_by_several_processes_at_once_reach_noon_as_one_shipment(): void
    {
        // THE DEFECT THIS WHOLE PIECE EXISTS FOR, under real processes. Each worker derives the same shipment reference, asks
        // noon, and is told noon holds no such shipment -- and if that answer were the whole defence, every one of them would
        // then create one. Cancelling a noon shipment cancels every item in it, so the second van is not undoable.
        $account = $this->noonAccount();

        $reports = ConcurrentRunner::run('noon.ship_same_units', array_fill(0, 5, [
            'account' => $account->id, 'order_id' => 'NO-SHIP-1', 'units' => ['NO-SHIP-1-I1', 'NO-SHIP-1-I2'],
        ]));

        $this->assertSame([], $this->problemsOf($reports), 'no process may fail');
        $this->assertSame(1, DB::table('test_noon_shipments')->count(), 'ONE shipment reached noon, whatever five processes tried');
        $this->assertSame(1, $this->workersWhere($reports, static fn (array $result): bool => ($result['sent'] ?? false) === true), 'exactly one process sent it');
        $this->assertSame(0, $this->workersWhere($reports, static fn (array $result): bool => ($result['needs_review'] ?? false) === true), 'and none of the others needs a person: they were simply not the one');
        $this->assertSame(1, ChannelShipmentSend::where('external_order_id', 'NO-SHIP-1')->count(), 'one claimed row, which is what decided it');
    }

    public function test_different_units_of_one_order_shipped_at_once_are_still_separate_shipments(): void
    {
        // The claim must not over-serialise: shipping other units of the same order really IS another shipment, and noon has to
        // be told about each. Two processes both inserting a reference nothing holds yet must also not deadlock on the gap.
        $account = $this->noonAccount();

        $writers = [];
        foreach (range(1, 6) as $unit) {
            // Each unit shipped by two processes at once: six shipments, twelve attempts.
            $writers[] = ['operation' => 'noon.ship_same_units', 'arguments' => ['account' => $account->id, 'order_id' => 'NO-SHIP-2', 'units' => ['NO-SHIP-2-I' . $unit]]];
            $writers[] = ['operation' => 'noon.ship_same_units', 'arguments' => ['account' => $account->id, 'order_id' => 'NO-SHIP-2', 'units' => ['NO-SHIP-2-I' . $unit]]];
        }

        $reports = ConcurrentRunner::run('noon.page', $writers);

        $this->assertSame([], $this->problemsOf($reports), 'no process may fail and none may deadlock');
        $this->assertSame(6, DB::table('test_noon_shipments')->count(), 'six shipments, one per set of units');
        $this->assertSame(6, DB::table('test_noon_shipments')->distinct()->count('reference'), 'and six distinct references');
        $this->assertSame(6, ChannelShipmentSend::where('external_order_id', 'NO-SHIP-2')->count());
    }

    /**
     * @param list<array{ok: bool, result?: mixed}> $reports
     * @param callable(array<string, mixed>): bool $matches
     */
    private function workersWhere(array $reports, callable $matches): int
    {
        return count(array_filter($reports, static fn (array $report): bool => is_array($report['result'] ?? null) && $matches($report['result'])));
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
