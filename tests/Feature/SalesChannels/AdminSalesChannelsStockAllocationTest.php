<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryPool;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\Allocation\PoolSettings;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsStockPools;

/** The stock and hub screens say what is promised, what may still be offered, and why a pool is not offered. They only read. */
class AdminSalesChannelsStockAllocationTest extends AdminSalesChannelsTestCase
{
    use BuildsRemoteOrders;
    use BuildsStockPools;

    private const PRODUCT = 50;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
        app(InventoryLocationService::class)->ensureDefaults();
        $this->storeProduct(self::PRODUCT);
    }

    private function stockOf(int $locationId): string
    {
        return $this->get(route('admin.sales-channels.inventory.index', ['location' => $locationId]))->assertOk()->getContent();
    }

    /** @param list<int> $figures the cells of one row: available, held by open channel orders, held by store orders, net */
    private function assertRowShows(array $figures, string $html): void
    {
        $cells = implode('\s*', array_map(static fn (int $figure): string => '<td class="fs-12 k-table__num">' . $figure . '(?:\s*<div[^>]*>.*?<\/div>)*\s*<\/td>', $figures));

        $this->assertMatchesRegularExpression('/' . $cells . '/s', $html);
    }

    public function test_the_stock_screen_shows_what_store_orders_hold_and_the_net_to_offer(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 3);
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->ingestOrder($account, 'ORD-1', 'BAR-1', 2);

        $html = $this->stockOf($this->torodLocation()->id);

        $this->assertRowShows([20, 2, 3, 15], $html);
        $this->assertStringContainsString(e(translate('held_by_store_orders')), $html);
        $this->assertStringContainsString(e(translate('net_to_offer')), $html);
        $this->assertStringContainsString(e(translate('stock_net_note')), $html);
        Http::assertNothingSent();
    }

    public function test_a_pool_promised_beyond_its_figure_is_flagged(): void
    {
        $this->providerFigure(self::PRODUCT, 3, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 2);
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->ingestOrder($account, 'ORD-1', 'BAR-1', 2);

        $html = $this->stockOf($this->torodLocation()->id);

        $this->assertRowShows([3, 2, 2, 0], $html);
        $this->assertStringContainsString(e(translate('promised_beyond_the_figure')) . ': 1', $html);
    }

    public function test_a_pool_that_is_refused_says_why(): void
    {
        $this->storeProduct(60, 'torod_point', 10, json_encode([['type' => 'Red', 'price' => 10, 'qty' => 5]]));
        $this->providerFigure(60, 8, now()->subHour());

        $html = $this->stockOf($this->torodLocation()->id);

        $this->assertStringContainsString(e(translate('publish_skip_variant_key_required')), $html);
    }

    // ---- the listing form ------------------------------------------------------------------------------------------------------------------

    private function backTo(): static
    {
        return $this->from(route('admin.sales-channels.products.index'));
    }

    private function linkedListing(?SalesChannelAccount $account = null, string $key = 'BAR-1', array $overrides = []): SalesChannelProductMapping
    {
        return $this->stockListing($account ?? $this->trendyolAccount(), self::PRODUCT, $this->torodLocation(), $key, $overrides + ['inventory_publish' => false]);
    }

    /** @return array<string, mixed> what the form posts: nothing about the price, the share as given */
    private function form(SalesChannelProductMapping $listing, array $share = []): array
    {
        return $share + ['price_source' => 'internal', 'stock_location_id' => $listing->stock_location_id, 'inventory_publish' => 1];
    }

    public function test_the_form_shows_the_share_inputs(): void
    {
        $this->linkedListing();

        $html = $this->get(route('admin.sales-channels.products.index'))->assertOk()->getContent();

        foreach (['stock_share', 'stock_share_equal', 'stock_share_value', 'stock_priority', 'stock_share_mode_fixed', 'stock_share_mode_percent', 'stock_share_mode_priority', 'stock_share_help'] as $key) {
            $this->assertStringContainsString(e(translate($key)), $html, $key);
        }
        $this->assertStringContainsString('name="stock_share_mode"', $html);
    }

    public function test_saving_a_share_stores_it_audits_it_marks_the_pool_and_publishes_nothing(): void
    {
        $this->buildAuditLog();
        $listing = $this->linkedListing();
        Http::fake();

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing, ['stock_share_mode' => 'percent', 'stock_share_value' => 30, 'stock_priority' => 2]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertSame(['percent', 30, 2, null], [$listing->stock_share_mode, $listing->stock_share_value, $listing->stock_priority, $listing->allocation]);
        $audit = DB::table('audit_logs')->where('action', 'sales_channel.listing_settings_changed')->first();
        $this->assertSame([null, null, null], [json_decode($audit->before, true)['stock_share_mode'], json_decode($audit->before, true)['stock_share_value'], json_decode($audit->before, true)['stock_priority']]);
        $this->assertSame(['percent', 30, 2], [json_decode($audit->after, true)['stock_share_mode'], json_decode($audit->after, true)['stock_share_value'], json_decode($audit->after, true)['stock_priority']]);
        $this->assertSame([(string) new PoolKey($this->torodLocation()->id, self::PRODUCT)], array_map('strval', app(DirtyPools::class)->pending()), 'the pool is worked out again');
        Http::assertNothingSent();
    }

    public function test_a_share_that_is_out_of_range_is_refused_on_that_listing_and_nothing_is_saved(): void
    {
        $listing = $this->linkedListing();

        foreach ([['stock_share_mode' => 'percent', 'stock_share_value' => 150], ['stock_share_mode' => 'fixed', 'stock_share_value' => -3], ['stock_share_mode' => 'fixed'], ['stock_priority' => -1]] as $share) {
            $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing, $share))->assertSessionHasErrorsIn('listing_' . $listing->id);
        }

        $this->assertSame([null, null, null], [$listing->fresh()->stock_share_mode, $listing->fresh()->stock_share_value, $listing->fresh()->stock_priority]);
        $this->assertSame(0, InventoryPool::count());
    }

    public function test_the_form_says_when_the_stock_is_shared_with_a_listing_of_another_account(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        $listing = $this->linkedListing();
        $this->stockListing($this->trendyolAccount(sellerId: '7654321'), self::PRODUCT, $this->torodLocation(), 'BAR-OTHER', ['inventory_publish' => true]);

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing, ['stock_share_mode' => 'fixed', 'stock_share_value' => 5]))->assertSessionHasNoErrors();

        $this->assertToastContains(translate('stock_pool_shared_with_other_listings'));
        $this->assertToastContains('BAR-OTHER');
    }

    public function test_the_form_says_when_the_shares_ask_for_more_than_the_pool_holds(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        $listing = $this->linkedListing();
        app(PoolAllocationService::class)->allocate(new PoolKey($this->torodLocation()->id, self::PRODUCT));

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing, ['stock_share_mode' => 'fixed', 'stock_share_value' => 30]))->assertSessionHasNoErrors();
        $this->assertToastContains(translate('stock_shares_ask_for_more_than_the_pool_holds'));
        $this->assertToastContains('30 / 20');

        $this->forgetToasts();
        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing, ['stock_share_mode' => 'percent', 'stock_share_value' => 60]))->assertSessionHasNoErrors();
        $this->assertSame([], array_filter($this->flashedToasts(), fn (string $toast): bool => str_contains($toast, translate('stock_shares_ask_for_more_than_the_pool_holds'))), '60% of the pool is fine');
    }

    public function test_the_form_says_when_the_location_is_not_where_the_products_provider_keeps_the_stock(): void
    {
        $listing = $this->stockListing($this->trendyolAccount(), self::PRODUCT, $this->ownLocation(), 'BAR-1', ['inventory_publish' => false]);

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing))->assertSessionHasNoErrors();

        $this->assertToastContains(translate('stock_location_is_not_where_the_products_provider_keeps_it'));
    }

    public function test_changing_the_location_of_a_listing_marks_both_pools_and_clears_what_was_said_about_the_old_one(): void
    {
        $listing = $this->linkedListing(overrides: ['allocation' => ['pool' => 'old', 'publish' => 5]]);

        $this->backTo()->post(route('admin.sales-channels.products.settings', $listing), $this->form($listing, ['stock_location_id' => $this->ownLocation()->id]))->assertSessionHasNoErrors();

        $this->assertNull($listing->fresh()->allocation);
        $this->assertEqualsCanonicalizing(
            [(string) new PoolKey($this->torodLocation()->id, self::PRODUCT), (string) new PoolKey($this->ownLocation()->id, self::PRODUCT)],
            array_map('strval', app(DirtyPools::class)->pending()),
        );
    }

    public function test_a_pools_reserve_and_buffer_are_validated_audited_and_mark_the_pool(): void
    {
        $this->buildAuditLog();
        $key = new PoolKey($this->torodLocation()->id, self::PRODUCT);

        $pool = app(PoolSettings::class)->save($key, ['storefront_reserve_mode' => 'percent', 'storefront_reserve_value' => 40, 'safety_buffer' => 3], adminId: 1);

        $this->assertSame(['percent', 40, 3], [$pool->storefront_reserve_mode, $pool->storefront_reserve_value, $pool->safety_buffer]);
        $audit = DB::table('audit_logs')->where('action', 'sales_channel.pool_settings_changed')->first();
        $this->assertSame(['percent', 40], [json_decode($audit->after, true)['storefront_reserve_mode'], json_decode($audit->after, true)['storefront_reserve_value']]);
        $this->assertNull(json_decode($audit->before, true)['storefront_reserve_mode']);
        $this->assertSame([(string) $key], array_map('strval', app(DirtyPools::class)->pending()));

        $this->expectException(ValidationException::class);
        app(PoolSettings::class)->save($key, ['storefront_reserve_mode' => 'percent', 'storefront_reserve_value' => 140], adminId: 1);
    }
}
