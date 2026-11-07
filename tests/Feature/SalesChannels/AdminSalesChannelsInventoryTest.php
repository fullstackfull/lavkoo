<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\ProviderProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** What each place says it holds: Amazon FBA beside Torod Point and M5AZN, never mixed with them, each figure with how far it can be trusted. The page only reads. */
class AdminSalesChannelsInventoryTest extends AdminSalesChannelsTestCase
{
    private function stock(array $query = []): string
    {
        return $this->get(route('admin.sales-channels.inventory.index', $query))->assertOk()->getContent();
    }

    private function fbaLocation(): InventoryLocation
    {
        return app(InventoryLocationService::class)->ensureFbaLocation($this->amazonAccount());
    }

    private function balance(InventoryLocation $location, string $sku, int $available, array $with = []): InventoryLocationBalance
    {
        return InventoryLocationBalance::create($with + [
            'location_id' => $location->id, 'sku' => $sku, 'variant_key' => '', 'available' => $available, 'reserved' => 2, 'unfulfillable' => 1, 'inbound' => 5, 'total' => $available + 8,
            'quality' => InventoryLocationBalance::CONFIRMED, 'source_synced_at' => now(),
        ]);
    }

    private function torodStock(int $productId, string $sku, int $stock, StockSource $source = StockSource::ProviderSync): ProviderProductMapping
    {
        return ProviderProductMapping::create([
            'provider' => 'torod_point', 'product_id' => $productId, 'variant_key' => '', 'external_id' => 'ext-' . $productId, 'external_sku' => $sku, 'status' => MappingStatus::Approved,
            'stock_source' => $source, 'provider_stock' => $stock, 'provider_stock_synced_at' => now(),
        ]);
    }

    private function locationOf(string $type): InventoryLocation
    {
        return InventoryLocation::where('type', $type)->firstOrFail();
    }

    public function test_the_default_locations_exist_after_the_first_visit_and_each_one_is_a_tab(): void
    {
        $this->assertSame(0, InventoryLocation::count());

        $html = $this->stock();
        $this->stock();

        $this->assertSame(3, InventoryLocation::count(), 'Torod Point, M5AZN and the own warehouse; the second visit adds nothing.');
        foreach (['Torod Point warehouse', 'M5AZN supplier stock', 'Own warehouse'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertStringContainsString(e(translate('Stock_by_Location')), $html);
    }

    public function test_amazon_fba_stock_is_shown_on_its_own_with_its_extra_figures_and_never_among_torods(): void
    {
        $fba = $this->fbaLocation();
        $linked = $this->product('Vitamin C 1000mg', 'VITC-1000');
        $this->balance($fba, 'FBA-SKU-1', 14, ['product_id' => $linked]);
        $this->balance($fba, 'FBA-SKU-2', 3);
        $this->stock();
        $this->torodStock($this->product('Torod only product', 'TOROD-ONLY'), 'TOROD-SKU-9', 40);
        $torod = $this->locationOf(InventoryLocation::TYPE_TOROD);

        $onFba = $this->stock(['location' => $fba->id]);
        $onTorod = $this->stock(['location' => $torod->id]);

        foreach (['FBA-SKU-1', 'FBA-SKU-2', 'Vitamin C 1000mg', e(translate('not_linked_to_a_product')), e(translate('unfulfillable')), e(translate('inbound')), e(translate('location_type_amazon_fba'))] as $shown) {
            $this->assertStringContainsString($shown, $onFba);
        }
        $this->assertStringNotContainsString('TOROD-SKU-9', $onFba, 'Torod Point stock is not part of what FBA holds.');
        $this->assertStringContainsString('TOROD-SKU-9', $onTorod);
        $this->assertStringNotContainsString('FBA-SKU-1', $onTorod, 'FBA stock is not part of what Torod Point holds.');
        $this->assertStringNotContainsString(e(translate('unfulfillable')), $onTorod, 'A provider reports one figure; the extra columns are Amazon\'s.');
    }

    public function test_the_store_and_the_channels_are_not_offered_fba_stock_and_the_page_says_so(): void
    {
        $fba = $this->fbaLocation();
        $this->balance($fba, 'FBA-SKU-1', 14);

        $html = $this->stock(['location' => $fba->id]);

        $this->assertFalse($fba->fresh()->storefront_sellable);
        $this->assertSame([], $fba->fresh()->publishable_channels);
        $this->assertStringContainsString(e(translate('none_of_them')), $html);
        $this->assertStringContainsString(e(translate('stock_by_location_note')), $html);
    }

    public function test_every_figure_shows_how_far_it_can_be_trusted(): void
    {
        $fba = $this->fbaLocation();
        $this->balance($fba, 'FRESH-1', 10);
        $this->balance($fba, 'OLD-1', 10, ['source_synced_at' => now()->subDays(2)]);
        $this->balance($fba, 'TYPED-1', 4, ['quality' => InventoryLocationBalance::MANUAL]);
        $this->stock();
        $torod = $this->locationOf(InventoryLocation::TYPE_TOROD);
        $this->torodStock($this->product('Verified', 'VER-1'), 'TOROD-VERIFIED', 40);
        $this->torodStock($this->product('Unverified', 'UNV-1'), 'TOROD-UNVERIFIED', 7, StockSource::None);

        $onFba = $this->stock(['location' => $fba->id]);
        $onTorod = $this->stock(['location' => $torod->id]);

        $this->assertStringContainsString(e(translate('stock_quality_confirmed')), $onFba);
        $this->assertStringContainsString(e(translate('stock_quality_stale')), $onFba, 'A figure not refreshed for two days is stale, not confirmed.');
        $this->assertStringContainsString(e(translate('stock_quality_manual')), $onFba);
        $this->assertStringContainsString(e(translate('stock_quality_confirmed')), $onTorod);
        $this->assertStringContainsString(e(translate('stock_quality_unknown')), $onTorod, 'A figure the provider integration does not vouch for is never shown as confirmed.');
    }

    public function test_the_sku_filter_narrows_the_list_and_the_empty_states_say_what_to_do(): void
    {
        $fba = $this->fbaLocation();
        $this->balance($fba, 'ALPHA-1', 5);
        $this->balance($fba, 'BETA-2', 6);
        $this->stock();
        $m5azn = $this->locationOf(InventoryLocation::TYPE_M5AZN);

        $filtered = $this->stock(['location' => $fba->id, 'sku' => 'ALPHA']);
        $none = $this->stock(['location' => $fba->id, 'sku' => 'NOPE']);
        $empty = $this->stock(['location' => $m5azn->id]);

        $this->assertStringContainsString('ALPHA-1', $filtered);
        $this->assertStringNotContainsString('BETA-2', $filtered);
        $this->assertStringContainsString(e(translate('no_stock_figure_matches')), $none);
        $this->assertStringContainsString(e(translate('no_stock_figures_yet')), $empty);
    }

    public function test_a_location_that_does_not_exist_falls_back_to_the_first_one_and_a_long_list_is_paged(): void
    {
        $fba = $this->fbaLocation();
        foreach (range(1, 55) as $number) {
            $this->balance($fba, sprintf('SKU-%03d', $number), $number);
        }

        $page = $this->stock(['location' => $fba->id]);
        $second = $this->stock(['location' => $fba->id, 'page' => 2]);
        $fallback = $this->stock(['location' => 999999]);

        $this->assertStringContainsString('SKU-001', $page);
        $this->assertStringNotContainsString('SKU-055', $page);
        $this->assertStringContainsString('SKU-055', $second);
        $this->assertStringContainsString('Amazon FBA', $this->stock(), 'The first location by type is the Amazon one.');
        $this->assertNotSame('', trim(strip_tags($fallback)));
    }

    public function test_the_page_only_reads(): void
    {
        $fba = $this->fbaLocation();
        $this->balance($fba, 'FBA-SKU-1', 14);
        $this->stock();
        $this->torodStock($this->product(), 'TOROD-SKU-9', 40);
        $before = [InventoryLocationBalance::query()->orderBy('id')->get()->toArray(), ProviderProductMapping::query()->orderBy('id')->get()->toArray(), DB::table('inventory_locations')->count()];

        foreach (InventoryLocation::pluck('id') as $id) {
            $this->stock(['location' => $id]);
        }

        $this->assertSame($before, [InventoryLocationBalance::query()->orderBy('id')->get()->toArray(), ProviderProductMapping::query()->orderBy('id')->get()->toArray(), DB::table('inventory_locations')->count()]);
        Http::assertNothingSent();
    }
}
