<?php

namespace Tests\Feature\StaffScope;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use App\Services\Staff\StaffMarketplaceRoutes;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The marketplace stock screens of slice 5: the warehouses, the inventory adjustments, the batches and the purchase orders (GCC-14c; G1-11).
 *
 * These four MOVE stock, so the refusals are the point. A restricted employee could place another market's product on a shelf, adjust its
 * balance, write off its batch and receive a purchase order of it -- and every one of those changes a figure the other market sells
 * against.
 *
 * The world: products 1 and 7 are Saudi, 2 and 3 are Kuwaiti. Employee 92 holds the marketplace module and is restricted to Kuwait.
 * Purchase order 1 buys the Kuwaiti product 2, order 2 buys the Saudi product 1, order 3 mixes both and order 4 buys a service (a line with
 * no catalogue product at all).
 */
class StaffMarketplaceStockScreensTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use BuildsPlatformProcurementSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        View::getFinder()->prependLocation(base_path('tests/Support/staff-views'));
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        foreach (['product_batches', 'warehouse_stock', 'warehouses', 'stock_movements', 'purchase_order_items', 'purchase_orders', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->buildPlatformProcurementSchema();
        $this->runStaffScopeMigration();
        $this->primeTaxSystemCache();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');
        $this->insertStaffProduct(id: 3, country: 'KW');
        $this->insertStaffProduct(id: 7, country: 'SA');

        DB::table('suppliers')->insert([['id' => 1, 'name' => 'A supplier', 'status' => 'active']]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'Riyadh', 'code' => 'RUH', 'is_default' => true, 'status' => 'active'],
            ['id' => 2, 'name' => 'Kuwait City', 'code' => 'KWI', 'is_default' => false, 'status' => 'active'],
        ]);
        DB::table('warehouse_stock')->insert([
            ['id' => 1, 'warehouse_id' => 1, 'product_id' => 1, 'quantity' => 10],
            ['id' => 2, 'warehouse_id' => 2, 'product_id' => 2, 'quantity' => 20],
        ]);
        DB::table('stock_movements')->insert([
            ['id' => 1, 'product_id' => 1, 'type' => 'adjustment', 'qty_change' => 5, 'balance_after' => 10],
            ['id' => 2, 'product_id' => 2, 'type' => 'adjustment', 'qty_change' => 7, 'balance_after' => 20],
        ]);
        DB::table('product_batches')->insert([
            // Each batch holds no more than its product's own stock (5, from insertStaffProduct), or writing it off would be refused for
            // making the balance negative rather than for the market.
            ['id' => 1, 'product_id' => 1, 'batch_number' => 'SA-1', 'quantity' => 4, 'initial_quantity' => 4, 'status' => 'active'],
            ['id' => 2, 'product_id' => 2, 'batch_number' => 'KW-1', 'quantity' => 4, 'initial_quantity' => 4, 'status' => 'active'],
        ]);
        DB::table('purchase_orders')->insert([
            ['id' => 1, 'reference' => 'PO-1', 'supplier_id' => 1, 'status' => 'draft'],
            ['id' => 2, 'reference' => 'PO-2', 'supplier_id' => 1, 'status' => 'draft'],
            ['id' => 3, 'reference' => 'PO-3', 'supplier_id' => 1, 'status' => 'draft'],
            ['id' => 4, 'reference' => 'PO-4', 'supplier_id' => 1, 'status' => 'draft'],
        ]);
        DB::table('purchase_order_items')->insert([
            ['id' => 1, 'purchase_order_id' => 1, 'product_id' => 2, 'qty_ordered' => 5],
            ['id' => 2, 'purchase_order_id' => 2, 'product_id' => 1, 'qty_ordered' => 5],
            ['id' => 3, 'purchase_order_id' => 3, 'product_id' => 2, 'qty_ordered' => 5],
            ['id' => 4, 'purchase_order_id' => 3, 'product_id' => 1, 'qty_ordered' => 5],
        ]);
        // Its own statement: a row with one more column than the others makes a multi-row insert "all VALUES must have the same number of terms".
        DB::table('purchase_order_items')->insert(['id' => 5, 'purchase_order_id' => 4, 'product_id' => null, 'description' => 'A pallet of packaging', 'qty_ordered' => 1]);

        DB::table('admin_roles')->insert(['id' => 63, 'name' => 'Marketplace only', 'module_access' => json_encode(['marketplace']), 'status' => 1]);
        DB::table('admins')->insert(['id' => 92, 'name' => 'Stock hand', 'email' => 'ninetytwo@example.test', 'admin_role_id' => 63]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function asKuwaitStockHand(): static
    {
        $this->switchStaffScopesOn();
        $this->giveCountries(adminId: 92, countries: ['KW']);

        return $this->signInAsStaff(adminId: 92);
    }

    // ---- the warehouses -------------------------------------------------------------------------------------------------------------

    public function test_placing_or_transferring_another_markets_product_is_refused_and_moves_nothing(): void
    {
        $this->asKuwaitStockHand();

        $this->post('/admin/marketplace/warehouses/place', ['warehouse_id' => 2, 'product_id' => 1, 'quantity' => 3])->assertNotFound();
        $this->post('/admin/marketplace/warehouses/transfer', ['from_warehouse_id' => 1, 'to_warehouse_id' => 2, 'product_id' => 1, 'quantity' => 3])->assertNotFound();
        $this->post('/admin/marketplace/warehouses/place', ['warehouse_id' => 2, 'product_id' => 999, 'quantity' => 3])->assertNotFound();

        $this->assertSame(10, (int) DB::table('warehouse_stock')->where('id', 1)->value('quantity'), 'the Saudi shelf was not touched');
        $this->assertSame(2, DB::table('warehouse_stock')->count());
    }

    public function test_placing_the_employees_own_product_still_works(): void
    {
        $this->asKuwaitStockHand()->post('/admin/marketplace/warehouses/place', ['warehouse_id' => 2, 'product_id' => 3, 'quantity' => 4])->assertRedirect();

        $this->assertSame(1, DB::table('warehouse_stock')->where(['warehouse_id' => 2, 'product_id' => 3])->count());
    }

    public function test_the_per_shelf_breakdown_holds_only_the_employees_products(): void
    {
        $mine = $this->asKuwaitStockHand()->get('/admin/marketplace/warehouses?product_id=2');
        $mine->assertOk();
        $this->assertStringContainsString('[shelf:2 qty:20]', (string) $mine->getContent());
        $this->assertStringContainsString('[product:2]', (string) $mine->getContent());

        $this->get('/admin/marketplace/warehouses?product_id=1')->assertNotFound('another market\'s product is answered like one that does not exist');

        // A1-18: a warehouse carries no market of its own, so its market is derived from what is ON it. Warehouse 1 holds only Saudi
        // units, so this Kuwaiti employee is not shown it; warehouse 2 holds Kuwaiti units and is theirs. Neither is a guess about the
        // owner's open question -- a warehouse holding two markets' stock would be shown to employees of either.
        $this->assertStringNotContainsString('[warehouse:1]', (string) $mine->getContent(), 'a shelf holding only another market\'s units is not theirs');
        $this->assertStringContainsString('[warehouse:2]', (string) $mine->getContent());
    }

    /**
     * The three readings of a warehouse's market, which is the whole of A1-18's remaining criterion as far as it can be answered without
     * the owner: one market's shelf, two markets' shelf, and an empty one.
     *
     * An empty shelf names no market and is shown to everybody, which is the honest answer rather than a gap -- hiding one would make a
     * warehouse vanish from the screen of the employee who had just created it.
     */
    public function test_a_warehouses_market_is_read_off_what_is_on_it(): void
    {
        DB::table('warehouses')->insert([
            ['id' => 3, 'name' => 'Both', 'code' => 'BOTH', 'is_default' => false, 'status' => 'active'],
            ['id' => 4, 'name' => 'Empty', 'code' => 'EMPTY', 'is_default' => false, 'status' => 'active'],
        ]);
        DB::table('warehouse_stock')->insert([
            ['id' => 3, 'warehouse_id' => 3, 'product_id' => 1, 'quantity' => 1],
            ['id' => 4, 'warehouse_id' => 3, 'product_id' => 2, 'quantity' => 1],
        ]);
        $markets = app(\App\Services\Marketplace\WarehouseMarkets::class);

        $this->assertSame(['SA'], $markets->marketsOf(1));
        $this->assertSame(['KW'], $markets->marketsOf(2));
        $this->assertSame(['SA', 'KW'], $markets->marketsOf(3), 'a shelf of two markets belongs to both, which is what it is');
        $this->assertSame([], $markets->marketsOf(4), 'and an empty shelf belongs to nobody yet');

        $shown = (string) $this->asKuwaitStockHand()->get('/admin/marketplace/warehouses')->assertOk()->getContent();

        $this->assertStringNotContainsString('[warehouse:1]', $shown);
        foreach (['[warehouse:2]', '[warehouse:3]', '[warehouse:4]'] as $theirs) {
            $this->assertStringContainsString($theirs, $shown);
        }
    }

    /** The unrestricted twin: nothing is hidden from an employee who is not restricted, so the narrowing is of the employee and not of the screen. */
    public function test_an_unrestricted_employee_still_sees_every_warehouse(): void
    {
        $shown = (string) $this->signInAsStaff(1)->get('/admin/marketplace/warehouses')->assertOk()->getContent();

        $this->assertStringContainsString('[warehouse:1]', $shown);
        $this->assertStringContainsString('[warehouse:2]', $shown);
    }

    // ---- the inventory adjustments --------------------------------------------------------------------------------------------------

    public function test_adjusting_another_markets_balance_is_refused_and_the_history_is_narrowed(): void
    {
        $this->asKuwaitStockHand();

        $this->post('/admin/marketplace/inventory-adjustments/adjust', ['product_id' => 1, 'direction' => 'decrease', 'quantity' => 2, 'reason' => 'damage'])->assertNotFound();
        $this->assertSame(2, DB::table('stock_movements')->count(), 'no movement was written');

        $page = $this->get('/admin/marketplace/inventory-adjustments');
        $page->assertOk();
        $this->assertStringContainsString('[movement:2 product:2]', (string) $page->getContent());
        $this->assertStringNotContainsString('[movement:1 ', (string) $page->getContent());
    }

    public function test_adjusting_the_employees_own_balance_still_writes_a_movement(): void
    {
        $this->asKuwaitStockHand()->post('/admin/marketplace/inventory-adjustments/adjust', ['product_id' => 2, 'direction' => 'increase', 'quantity' => 2, 'reason' => 'found'])->assertRedirect();

        $this->assertSame(3, DB::table('stock_movements')->count());
    }

    // ---- the batches ----------------------------------------------------------------------------------------------------------------

    public function test_the_batch_list_and_its_counters_hold_only_the_employees_products(): void
    {
        DB::table('product_batches')->whereIn('id', [1, 2])->update(['expiry_date' => now()->addDays(10)->toDateString()]);

        $page = $this->asKuwaitStockHand()->get('/admin/marketplace/batches');

        $page->assertOk();
        $this->assertStringContainsString('[batch:2 product:2]', (string) $page->getContent());
        $this->assertStringNotContainsString('[batch:1 ', (string) $page->getContent());
        $this->assertStringContainsString('[expiring:1][expired:0]', (string) $page->getContent(), 'the counter above the list counts one, not two');
    }

    public function test_a_batch_of_another_markets_product_cannot_be_written_off_or_added(): void
    {
        $this->asKuwaitStockHand();

        $this->post('/admin/marketplace/batches/1/write-off')->assertNotFound();
        $this->post('/admin/marketplace/batches/999/write-off')->assertNotFound();
        $this->post('/admin/marketplace/batches', ['product_id' => 7, 'quantity' => 3])->assertNotFound();

        $this->assertSame('active', DB::table('product_batches')->where('id', 1)->value('status'));
        $this->assertSame(2, DB::table('product_batches')->count());

        $this->post('/admin/marketplace/batches/2/write-off')->assertRedirect();
        $this->assertSame('depleted', DB::table('product_batches')->where('id', 2)->value('status'));
    }

    // ---- the purchase orders --------------------------------------------------------------------------------------------------------

    /** Every line's product must be the employee's: an order that mixes two markets belongs to whoever holds both, and to nobody else. */
    public function test_a_purchase_order_is_judged_by_every_line_it_holds(): void
    {
        $this->asKuwaitStockHand();

        $this->assertNotSame(404, $this->get('/admin/marketplace/purchase-orders/1')->getStatusCode(), 'the Kuwaiti order');
        $this->get('/admin/marketplace/purchase-orders/2')->assertNotFound('the Saudi order');
        $this->get('/admin/marketplace/purchase-orders/3')->assertNotFound('the mixed order: one line is not theirs');
        $this->get('/admin/marketplace/purchase-orders/4')->assertNotFound('an order that buys nothing from the catalogue belongs to no market');
        $this->get('/admin/marketplace/purchase-orders/999')->assertNotFound();
    }

    public function test_placing_receiving_or_cancelling_another_markets_purchase_order_is_refused(): void
    {
        $this->asKuwaitStockHand();

        foreach ([2, 3, 4] as $orderId) {
            $this->post('/admin/marketplace/purchase-orders/' . $orderId . '/place')->assertNotFound((string) $orderId);
            $this->post('/admin/marketplace/purchase-orders/' . $orderId . '/receive', ['receive_all' => true])->assertNotFound((string) $orderId);
            $this->post('/admin/marketplace/purchase-orders/' . $orderId . '/cancel')->assertNotFound((string) $orderId);
        }

        $this->assertSame(['draft', 'draft', 'draft'], DB::table('purchase_orders')->whereIn('id', [2, 3, 4])->orderBy('id')->pluck('status')->all());
        $this->assertSame(0, (int) DB::table('purchase_order_items')->where('id', 2)->value('qty_received'));
    }

    public function test_creating_a_purchase_order_is_refused_whole_when_one_line_is_another_markets(): void
    {
        $this->asKuwaitStockHand();

        $this->post('/admin/marketplace/purchase-orders', [
            'supplier_id' => 1,
            'items' => [['product_id' => 2, 'qty_ordered' => 1], ['product_id' => 1, 'qty_ordered' => 1]],
        ])->assertNotFound();

        $this->assertSame(4, DB::table('purchase_orders')->count(), 'nothing was created');

        // A line with no catalogue product names no product, so it is not a refusal: a purchase order may buy a service or a pallet.
        $this->post('/admin/marketplace/purchase-orders', [
            'supplier_id' => 1,
            'items' => [['product_id' => 2, 'qty_ordered' => 1], ['description' => 'A pallet', 'qty_ordered' => 1]],
        ])->assertRedirect();
        $this->assertSame(5, DB::table('purchase_orders')->count());
    }

    /**
     * The scope of the lines, read through a real request: nothing is bound until a request of a covered screen binds it, so a direct read
     * after a bare sign-in would prove the opposite of what it looks like. A line with NO catalogue product belongs to no market and is
     * none of theirs either.
     */
    public function test_the_lines_of_a_purchase_order_are_narrowed_through_their_products(): void
    {
        $this->asKuwaitStockHand()->get('/admin/marketplace/purchase-orders')->assertOk();

        // Lines 1 and 3 are the Kuwaiti product's, line 3 inside the MIXED order. Two different mechanisms, and this is the difference
        // between them: the line scope narrows line by line, and what hides the mixed order is the rule of the ORDER -- every line's
        // product must be theirs. A reading that expected one line here would have been reading the order rule into the row scope.
        $this->assertSame([1, 3], \App\Models\PurchaseOrderItem::query()->orderBy('id')->pluck('id')->map('intval')->all());
    }

    // ---- the two mechanisms that the screens do not distinguish ---------------------------------------------------------------------

    /**
     * Two parts of this slice are narrowed TWICE, and the teeth check is what shows it. A batch write-off is refused by the rule AND by the
     * row scope, because `ProductBatch::findOrFail()` cannot find a batch the scope hides; and the per-shelf breakdown is read by a product
     * id the rule has already checked, so the scope on `WarehouseStock` changes nothing the screen shows. Taking either off leaves every
     * screen test green -- which is exactly why each needs a test of its own rather than being trusted because a screen looks right.
     */
    public function test_a_batch_is_followed_to_its_product_by_the_rule_itself(): void
    {
        $request = \Illuminate\Http\Request::create('/admin/marketplace/batches/2/write-off', 'POST');
        $request->setRouteResolver(static function () use ($request) {
            $route = new \Illuminate\Routing\Route('POST', 'admin/marketplace/batches/{id}/write-off', []);
            $route->bind($request);

            return $route;
        });

        $this->assertSame([2], StaffMarketplaceRoutes::productIdsOf(request: $request, part: 'batch.route:id'), 'the Kuwaiti batch names the Kuwaiti product');

        $bare = \Illuminate\Http\Request::create('/admin/marketplace/batches');
        $this->assertSame([], StaffMarketplaceRoutes::productIdsOf(request: $bare, part: 'none'));
        $this->assertSame([], StaffMarketplaceRoutes::productIdsOf(request: $bare, part: 'list'));
        $this->assertNull(StaffMarketplaceRoutes::productIdsOf(request: $bare, part: 'batch.route:id'), 'no id in the path names nothing that can be checked');
        $this->assertNull(StaffMarketplaceRoutes::productIdsOf(request: $bare, part: 'bogus:id'), 'a part nobody understands refuses the request');
        $this->assertNull(StaffMarketplaceRoutes::productIdsOf(request: $bare, part: 'product.items:items'), 'a list of lines that is not a list names nothing that can be checked');
    }

    public function test_a_shelf_balance_of_another_markets_product_is_not_read_even_by_a_query_that_names_no_product(): void
    {
        $this->asKuwaitStockHand()->get('/admin/marketplace/warehouses')->assertOk();

        $this->assertSame([2], \App\Models\WarehouseStock::query()->orderBy('id')->pluck('product_id')->map('intval')->all());
        $this->assertSame([2], \App\Models\ProductBatch::query()->orderBy('id')->pluck('product_id')->map('intval')->all());
        $this->assertSame([2], \App\Models\StockMovement::query()->orderBy('id')->pluck('product_id')->map('intval')->all());
    }

    // ---- the master admin and the switch --------------------------------------------------------------------------------------------

    public function test_the_master_admin_reaches_every_market_s_stock(): void
    {
        $this->switchStaffScopesOn();
        $this->signInAsStaff(adminId: 1);

        foreach ([1, 2, 3, 4] as $orderId) {
            $this->assertNotSame(404, $this->get('/admin/marketplace/purchase-orders/' . $orderId)->getStatusCode(), (string) $orderId);
        }
        $this->post('/admin/marketplace/warehouses/place', ['warehouse_id' => 1, 'product_id' => 1, 'quantity' => 1])->assertRedirect();

        $page = $this->get('/admin/marketplace/inventory-adjustments');
        $this->assertStringContainsString('[movement:1 product:1]', (string) $page->getContent());
    }

    public function test_with_the_switch_off_nothing_is_refused_and_nothing_is_narrowed(): void
    {
        $this->giveCountries(adminId: 92, countries: ['KW']);
        $this->signInAsStaff(adminId: 92);

        $this->assertNotSame(404, $this->get('/admin/marketplace/purchase-orders/3')->getStatusCode());
        $this->post('/admin/marketplace/inventory-adjustments/adjust', ['product_id' => 1, 'direction' => 'increase', 'quantity' => 1, 'reason' => 'found'])->assertRedirect();

        $page = $this->get('/admin/marketplace/batches');
        $this->assertStringContainsString('[batch:1 product:1]', (string) $page->getContent());
    }
}
