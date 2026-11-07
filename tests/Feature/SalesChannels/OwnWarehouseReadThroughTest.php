<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\LocationStockPage;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use App\Services\SalesChannels\Inventory\PoolFigures;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Inventory\StockAvailability;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * The own warehouse read from the storefront's own stock instead of from typed balances (behind SALES_CHANNELS_OWN_WAREHOUSE_SOURCE, off by default): read through, never copied,
 * never written, and a unit that a shipped channel order took off the shelf keeps counting until the storefront's stock has been lowered for it.
 */
class OwnWarehouseReadThroughTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private SalesChannelAccount $account;

    private InventoryLocation $own;

    private LocationStockReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true, 'sales_channels.inventory.own_warehouse_source' => 'platform']);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
        $this->account = $this->trendyolAccount();
        $this->own = $this->ownLocation();
        $this->own->forceFill(['source_of_truth' => InventoryLocation::TRUTH_PLATFORM])->save();
        $this->reader = app(LocationStockReader::class);
    }

    private function read(int $productId, string $variantKey = ''): StockAvailability
    {
        return $this->reader->availability($this->own->fresh(), $productId, $variantKey);
    }

    // ---- what is read ------------------------------------------------------------------------------------------------------------------

    public function test_a_product_without_variants_is_its_current_stock_confirmed_at_the_time_it_was_read(): void
    {
        $this->storeProduct(1, provider: null, stock: 7);

        $figure = $this->read(1);

        $this->assertSame([7, StockAvailability::CONFIRMED, 'platform_stock'], [$figure->available, $figure->quality, $figure->source]);
        $this->assertTrue($figure->syncedAt->equalTo(now()), 'the figure is as old as the read');
        $this->assertTrue($figure->isPublishable());
    }

    public function test_a_negative_stock_the_store_allows_is_read_as_zero(): void
    {
        $this->storeProduct(1, provider: null, stock: -4);

        $this->assertSame(0, $this->read(1)->available);
    }

    public function test_a_variant_is_its_own_quantity_and_the_whole_product_of_a_variant_product_is_not_known(): void
    {
        $this->storeProduct(1, provider: null, stock: 12, variation: json_encode([['type' => 'Red', 'price' => 10, 'qty' => 5], ['type' => 'Blue', 'price' => 10, 'qty' => -2]]));

        $this->assertSame(5, $this->read(1, 'Red')->available);
        $this->assertSame(0, $this->read(1, 'Blue')->available);
        $this->assertSame([null, StockAvailability::UNKNOWN], [$this->read(1)->available, $this->read(1)->quality], 'one figure for a product sold per variant cannot be spread over its variants');
        $this->assertNull($this->read(1, 'Green')->available, 'a variant the product does not have');
    }

    public function test_what_the_store_does_not_count_in_units_is_not_known(): void
    {
        $this->storeProduct(1, provider: null, stock: 999999999);
        $this->storeProduct(2, provider: null, stock: 5);
        DB::table('products')->where('id', 2)->update(['product_type' => 'digital']);
        $this->storeProduct(3, provider: 'torod_point', stock: 9);

        $this->assertSame([null, null, null, null], [$this->read(1)->available, $this->read(2)->available, $this->read(3)->available, $this->read(404)->available], 'unlimited stock, a digital product, a product a provider holds, a product that is not there');
        $this->assertSame(StockAvailability::UNKNOWN, $this->read(3)->quality);
    }

    public function test_the_reader_never_writes_anything(): void
    {
        $this->storeProduct(1, provider: null, stock: 7, variation: json_encode([['type' => 'Red', 'price' => 10, 'qty' => 5]]));
        $this->storeProduct(2, provider: null, stock: 3);
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });
        $before = [DB::table('products')->orderBy('id')->get()->toArray(), InventoryLocationBalance::count(), InventoryLocation::count()];

        $this->read(1, 'Red');
        $this->read(2);
        app(LocationStockPage::class)->for($this->own->fresh(), null, 25);
        app(PoolNet::class)->for($this->own->fresh(), 2);
        $this->reader->snapshot($this->own->fresh());

        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => preg_match('/^(insert|update|delete|replace|alter|drop|create)\b/', $sql) === 1)), 'nothing is written');
        $this->assertEquals($before, [DB::table('products')->orderBy('id')->get()->toArray(), InventoryLocationBalance::count(), InventoryLocation::count()]);
    }

    // ---- the flag --------------------------------------------------------------------------------------------------------------------------

    public function test_with_the_flag_off_the_location_is_read_as_it_always_was_even_if_its_source_was_changed(): void
    {
        config(['sales_channels.inventory.own_warehouse_source' => 'manual']);
        $this->storeProduct(1, provider: null, stock: 7);
        InventoryLocationBalance::create(['location_id' => $this->own->id, 'product_id' => 1, 'variant_key' => '', 'sku' => 'S1', 'available' => 3, 'quality' => 'manual', 'source_synced_at' => now()]);

        $figure = $this->read(1);

        $this->assertSame([3, StockAvailability::MANUAL, 'balance'], [$figure->available, $figure->quality, $figure->source], 'the typed balance, not the storefront\'s stock');
    }

    public function test_with_the_flag_on_a_location_whose_source_was_not_changed_is_still_read_from_its_balances(): void
    {
        $this->own->forceFill(['source_of_truth' => InventoryLocation::TRUTH_MANUAL])->save();
        $this->storeProduct(1, provider: null, stock: 7);

        $this->assertNull($this->read(1)->available, 'the row is flipped by an explicit command, never because the flag is on');
    }

    public function test_nothing_is_offered_from_the_storefronts_own_stock_until_the_owner_decides_even_with_the_flag_on(): void
    {
        $this->storeProduct(1, provider: null, stock: 7);
        $this->stockListing($this->account, 1, $this->own, 'BAR-1');
        Http::fake();

        $result = app(StockPricePublisher::class)->publishStock($this->account);

        $this->assertSame([PoolFigures::STOREFRONT_OWNED => 1], $result['stats']['skipped']);
        Http::assertNothingSent();
        $net = app(PoolNet::class)->for($this->own->fresh(), 1);
        $this->assertSame([7, PoolFigures::STOREFRONT_OWNED], [$net->net(), $net->refusal], 'the screen can still say what is there');
    }

    // ---- what a shipped channel order keeps counting -------------------------------------------------------------------------------------------

    private function committedHold(array $extra = []): InventoryReservation
    {
        $this->storeProduct(1, provider: null, stock: 10);
        $hold = app(ReservationService::class)->reserve('channel-order:1:1', $this->own->id, 1, '', 2);
        app(ReservationService::class)->commit('channel-order:1:1');
        $this->travel(1)->hours();
        $hold->refresh()->forceFill($extra)->save();

        return $hold;
    }

    public function test_a_committed_hold_keeps_counting_against_the_storefronts_stock_until_it_has_been_taken_off_it(): void
    {
        $hold = $this->committedHold();

        $this->assertSame(2, $this->read(1)->inFlight, 'the storefront\'s stock still shows the 2 units that left the shelf: a read taken after they were committed does not mean they are off it');
        $this->assertSame(8, app(PoolNet::class)->for($this->own->fresh(), 1)->net());

        $hold->forceFill(['applied_to_platform_at' => now()])->save();
        $this->assertSame(2, $this->read(1)->inFlight, 'with the switch off, which is the only supported state, a mark nobody is meant to write is not trusted');

        config(['sales_channels.inventory.apply_channel_orders_to_platform_stock' => true]);
        $this->assertSame(0, $this->read(1)->inFlight, 'once the storefront\'s stock has been lowered for them they are in the figure: no double deduction');
        $this->assertSame(10, app(PoolNet::class)->for($this->own->fresh(), 1)->net());
        $this->assertSame([], app(ReservationService::class)->inFlightByChannel($this->own->id, 1, '', now(), untilApplied: true));
    }

    public function test_a_reserved_hold_counts_whatever_the_source(): void
    {
        $this->storeProduct(1, provider: null, stock: 10);
        app(ReservationService::class)->reserve('channel-order:1:1', $this->own->id, 1, '', 3);

        $this->assertSame(3, $this->read(1)->inFlight);
    }

    public function test_the_old_rule_still_holds_for_a_location_read_from_balances(): void
    {
        $this->own->forceFill(['source_of_truth' => InventoryLocation::TRUTH_MANUAL])->save();
        InventoryLocationBalance::create(['location_id' => $this->own->id, 'product_id' => 1, 'variant_key' => '', 'sku' => 'S1', 'available' => 10, 'quality' => 'manual', 'source_synced_at' => now()]);
        app(ReservationService::class)->reserve('channel-order:1:1', $this->own->id, 1, '', 2);
        app(ReservationService::class)->commit('channel-order:1:1');
        $this->travel(1)->hours();
        InventoryLocationBalance::query()->update(['source_synced_at' => now()]);

        $this->assertSame(0, $this->read(1)->inFlight, 'a count taken after the units were committed already shows them gone');
    }

    // ---- the screen ----------------------------------------------------------------------------------------------------------------------------------

    public function test_the_stock_page_of_the_location_lists_the_stores_products_and_their_variants_a_page_at_a_time(): void
    {
        $this->storeProduct(1, provider: null, stock: 7);
        $this->storeProduct(2, provider: null, stock: 0, variation: json_encode([['type' => 'Red', 'price' => 10, 'qty' => 5], ['type' => 'Blue', 'price' => 10, 'qty' => 1]]));
        $this->storeProduct(3, provider: 'torod_point', stock: 9);
        foreach (range(10, 14) as $id) {
            $this->storeProduct($id, provider: null, stock: $id);
        }

        $first = app(LocationStockPage::class)->for($this->own->fresh(), null, 3);
        $rows = collect($first['rows']->items());

        $this->assertSame(7, $first['skus'], 'the products of the store that no provider holds: 1, 2 and 10 to 14');
        $this->assertSame(['SKU1', 'SKU2', 'SKU2', 'SKU10'], $rows->pluck('sku')->all());
        $this->assertSame([7, 5, 1, 10], $rows->map(fn (array $row): ?int => $row['availability']->available)->all(), 'a variant product is one row per variant');
        $this->assertSame(['', 'Red', 'Blue', ''], $rows->pluck('variant_key')->all());
        $found = app(LocationStockPage::class)->for($this->own->fresh(), 'SKU12', 25);
        $this->assertSame([12], collect($found['rows']->items())->map(fn (array $row): ?int => $row['availability']->available)->all());
    }

    // ---- the command that switches the location ------------------------------------------------------------------------------------------------------

    public function test_nothing_switches_the_location_by_itself(): void
    {
        $fresh = InventoryLocation::where('code', 'own-warehouse')->firstOrFail()->forceFill(['source_of_truth' => 'manual']);
        $fresh->save();

        app(InventoryLocationService::class)->ensureDefaults();
        $this->artisan('channels:status')->assertSuccessful();

        $this->assertSame('manual', $fresh->fresh()->source_of_truth, 'the flag is on, and the own warehouse still reads what it read');
    }

    public function test_the_command_is_a_dry_run_until_it_is_told_to_apply_then_audits_and_is_safe_to_repeat(): void
    {
        $this->buildAuditLog();
        $this->own->forceFill(['source_of_truth' => 'manual'])->save();
        $this->storeProduct(1, provider: null, stock: 7);
        InventoryLocationBalance::create(['location_id' => $this->own->id, 'product_id' => 1, 'variant_key' => '', 'sku' => 'S1', 'available' => 3, 'quality' => 'manual', 'source_synced_at' => now()]);

        $this->assertSame(0, Artisan::call('channels:stock-use-platform-for-own-warehouse'));
        $output = Artisan::output();
        foreach (['from "manual" to "platform"', '1 product(s) of the store would be read', '1 balance(s) typed for it would stop being read', 'Dry run: nothing was written'] as $said) {
            $this->assertStringContainsString($said, $output);
        }
        $this->assertSame('manual', $this->own->fresh()->source_of_truth);
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->artisan('channels:stock-use-platform-for-own-warehouse', ['--apply' => true])->expectsOutputToContain('Done')->assertSuccessful();
        $this->assertSame('platform', $this->own->fresh()->source_of_truth);
        $audit = DB::table('audit_logs')->where('action', 'sales_channel.own_warehouse_source_changed')->firstOrFail();
        $this->assertSame(['manual', 'platform'], [json_decode($audit->before, true)['source_of_truth'], json_decode($audit->after, true)['source_of_truth']]);
        $this->assertSame(7, $this->read(1)->available);
        $this->assertSame(7, (int) DB::table('products')->where('id', 1)->value('current_stock'), 'nothing was written to a product');

        $this->artisan('channels:stock-use-platform-for-own-warehouse', ['--apply' => true])->expectsOutputToContain('already reads the storefront')->assertSuccessful();
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_the_command_refuses_while_the_switch_is_off(): void
    {
        config(['sales_channels.inventory.own_warehouse_source' => 'manual']);
        $this->own->forceFill(['source_of_truth' => 'manual'])->save();

        $this->artisan('channels:stock-use-platform-for-own-warehouse', ['--apply' => true])->expectsOutputToContain('is not "platform"')->assertFailed();

        $this->assertSame('manual', $this->own->fresh()->source_of_truth);
    }
}
