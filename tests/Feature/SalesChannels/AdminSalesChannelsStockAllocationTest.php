<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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
        $cells = implode('\s*', array_map(static fn (int $figure): string => '<td class="fs-12 k-table__num">' . $figure . '(?:<div>.*?<\/div>)?<\/td>', $figures));

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
}
