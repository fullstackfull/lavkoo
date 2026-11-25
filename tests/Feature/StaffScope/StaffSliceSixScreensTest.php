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
use App\Models\Admin;
use App\Models\ChannelListingDraft;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\Staff\StaffCostRoutes;
use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffIntegrationRoutes;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\SalesChannels\AdminPagesTestCase;
use Tests\Support\BuildsStaffScopeWorld;

/**
 * Slice 6 of the staff country scopes through the real route table (GCC-14c; G-08, G-09): the sales channels, the fulfilment providers, the
 * payment-gateway monitor and the operational costs.
 *
 * It is built on the sales-channel page harness rather than on the staff-scope one, because these screens need their real tables (built from
 * the real migrations) and their real views; what it overrides is the middleware list, so the sign-in check and the module permission of each
 * route stay ON and the scope middleware the controllers register runs where it really runs -- after `SubstituteBindings`, which is the whole
 * difficulty of this slice: `$request->route('account')` hands back a MODEL and not digits.
 *
 * **Every refusal is asserted twice**: once for the restricted employee, who must be answered like for something that does not exist, and once
 * for an employee of the same role with no country, who must still be served. A 404 on its own cannot tell a guard from a URL that was never
 * there.
 *
 * The world. Products: 1 is Saudi, 2 is Kuwaiti. Accounts: 71 sells in Saudi Arabia, 72 in Kuwait, 73 names two countries' marketplaces and so
 * sells in NO market. Channel listings: 81 is the Kuwaiti account's and unlinked (`product_id` null, which is the state the mapping screen
 * exists for), 82 is the Saudi account's, 83 is the no-market account's. Drafts: 84 of the Saudi product, 85 of the Kuwaiti one. Channel orders:
 * 86 of the Kuwaiti account, 87 of the Saudi one. Warehouses: 88 is Kuwaiti, 89 Saudi, 90 unmarked (so the default storefront's, which is Saudi
 * Arabia). Routing rules: 91 names the Kuwaiti product, 92 names Saudi Arabia, 93 names neither and decides for every market. Cost layers: 94
 * of the Saudi product, 95 of the Kuwaiti one. Orders 101 (Saudi product) and 102 (Kuwaiti); provider shipments 96 of order 102 and 97 of order
 * 101. Employees: 41 holds the three modules of this slice and is restricted to Kuwait, 42 holds the same role and is restricted to nothing.
 */
class StaffSliceSixScreensTest extends AdminPagesTestCase
{
    use BuildsStaffScopeWorld;

    private const KUWAIT_HAND = 41;

    private const UNRESTRICTED_HAND = 42;

    private const SAUDI_HAND = 43;

    /** The scope middleware is what this file is about, so the two guards in front of it stay on. */
    protected function middlewareToDisable(): array
    {
        return [
            ActivationCheckMiddleware::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
        // The payment monitor's own table, so that the unrestricted twin of its refusal reaches the controller instead of a missing table.
        (require database_path('migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php'))->up();
        $this->runStaffScopeMigration();

        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'KW');

        $this->buildChannelAccounts();
        $this->buildChannelRows();
        $this->buildWarehousesAndCosts();
        $this->buildOrdersAndProviderShipments();

        DB::table('admin_roles')->insert(['id' => 40, 'name' => 'Channels, costs and integrations', 'module_access' => json_encode(['sales_channels', 'operational_costs', '3rd_party_setup']), 'status' => 1]);
        DB::table('admins')->insert([
            ['id' => self::KUWAIT_HAND, 'name' => 'Kuwait hand', 'email' => 'fortyone@example.test', 'admin_role_id' => 40],
            ['id' => self::UNRESTRICTED_HAND, 'name' => 'Every market', 'email' => 'fortytwo@example.test', 'admin_role_id' => 40],
            ['id' => self::SAUDI_HAND, 'name' => 'Saudi hand', 'email' => 'fortythree@example.test', 'admin_role_id' => 40],
        ]);
    }

    private function buildChannelAccounts(): void
    {
        $saudi = $this->trendyolAccount(sellerId: 'SA-SELLER');
        $kuwait = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol Kuwait', 'external_account_id' => 'KW-SELLER', 'currency' => 'KWD', 'settings' => ['storefront_code' => 'KW', 'integration_name' => 'PharmacyHub', 'carrier_codes' => []]],
            ['api_key' => 'key-kw-123', 'api_secret' => 'secret-kw-789'],
            adminId: 1,
        );
        // Two countries' marketplaces: `ChannelAccountCountry` answers none, so the account belongs to no market and to no restricted employee.
        $both = app(ChannelAccountService::class)->create(
            ['channel' => 'amazon', 'name' => 'Amazon Gulf', 'external_account_id' => 'A2SELLER', 'currency' => 'SAR', 'settings' => ['region' => 'eu', 'marketplace_ids' => ['A17E79C6D8DWNP', 'A2VIGQ35RCS4UG']]],
            ['lwa_client_id' => 'amzn1.application-oa2-client.test', 'lwa_client_secret' => 'client-secret-xyz', 'refresh_token' => 'Atzr|refresh-token-abc'],
            adminId: 1,
        );

        DB::table('sales_channel_accounts')->where('id', $saudi->id)->update(['id' => 71]);
        DB::table('sales_channel_accounts')->where('id', $kuwait->id)->update(['id' => 72]);
        DB::table('sales_channel_accounts')->where('id', $both->id)->update(['id' => 73]);
    }

    private function buildChannelRows(): void
    {
        DB::table('sales_channel_product_mappings')->insert([
            ['id' => 81, 'account_id' => 72, 'product_id' => null, 'listing_key' => 'kw-listing', 'status' => 'unmapped'],
            ['id' => 82, 'account_id' => 71, 'product_id' => 1, 'listing_key' => 'sa-listing', 'status' => 'mapped'],
            ['id' => 83, 'account_id' => 73, 'product_id' => null, 'listing_key' => 'gulf-listing', 'status' => 'unmapped'],
        ]);

        DB::table('channel_listing_drafts')->insert([
            ['id' => 84, 'account_id' => 71, 'sales_channel' => 'trendyol', 'product_id' => 1, 'variant_key' => '', 'state' => 'draft', 'payload' => '{}'],
            ['id' => 85, 'account_id' => 72, 'sales_channel' => 'trendyol', 'product_id' => 2, 'variant_key' => '', 'state' => 'draft', 'payload' => '{}'],
        ]);

        DB::table('external_order_mappings')->insert([
            ['id' => 86, 'account_id' => 72, 'sales_channel' => 'trendyol', 'external_order_id' => 'KW-1', 'status' => 'new'],
            ['id' => 87, 'account_id' => 71, 'sales_channel' => 'trendyol', 'external_order_id' => 'SA-1', 'status' => 'new'],
        ]);

        DB::table('external_order_lines')->insert([
            ['id' => 51, 'mapping_id' => 86, 'external_line_id' => 'KW-1-1', 'quantity' => 1, 'quantity_returned' => 1, 'product_id' => 2, 'status' => 'open'],
            ['id' => 52, 'mapping_id' => 87, 'external_line_id' => 'SA-1-1', 'quantity' => 1, 'quantity_returned' => 1, 'product_id' => 1, 'status' => 'open'],
        ]);

        DB::table('fulfillment_routing_rules')->insert([
            ['id' => 91, 'name' => 'Kuwaiti product', 'product_id' => 2, 'country' => null, 'action' => 'monitor_only', 'priority' => 100, 'is_active' => 1],
            ['id' => 92, 'name' => 'Saudi destination', 'product_id' => null, 'country' => 'SA', 'action' => 'monitor_only', 'priority' => 100, 'is_active' => 1],
            ['id' => 93, 'name' => 'Every market', 'product_id' => null, 'country' => null, 'action' => 'monitor_only', 'priority' => 100, 'is_active' => 1],
        ]);
    }

    private function buildWarehousesAndCosts(): void
    {
        DB::table('inventory_locations')->insert([
            ['id' => 88, 'code' => 'KW-1', 'name' => 'Kuwait shelf', 'type' => 'own_warehouse', 'source_of_truth' => 'manual', 'seller_id' => 0, 'is_active' => 1, 'storefront_sellable' => 1, 'storefront_country' => 'KW'],
            ['id' => 89, 'code' => 'SA-1', 'name' => 'Saudi shelf', 'type' => 'own_warehouse', 'source_of_truth' => 'manual', 'seller_id' => 0, 'is_active' => 1, 'storefront_sellable' => 1, 'storefront_country' => 'SA'],
            ['id' => 90, 'code' => 'OLD-1', 'name' => 'Unmarked shelf', 'type' => 'own_warehouse', 'source_of_truth' => 'manual', 'seller_id' => 0, 'is_active' => 1, 'storefront_sellable' => 1, 'storefront_country' => null],
        ]);

        DB::table('inventory_cost_layers')->insert([
            ['id' => 94, 'product_id' => 1, 'inventory_location_id' => 89, 'source_type' => 'opening_balance', 'dedupe_key' => 'layer-94', 'quantity_received' => 1, 'quantity_remaining' => 1, 'unit_cost' => 10, 'currency' => 'SAR', 'received_at' => now()],
            ['id' => 95, 'product_id' => 2, 'inventory_location_id' => 88, 'source_type' => 'opening_balance', 'dedupe_key' => 'layer-95', 'quantity_received' => 1, 'quantity_remaining' => 1, 'unit_cost' => 20, 'currency' => 'SAR', 'received_at' => now()],
        ]);
    }

    private function buildOrdersAndProviderShipments(): void
    {
        DB::table('orders')->insert([
            ['id' => 101, 'seller_is' => 'admin', 'seller_id' => 1, 'order_status' => 'delivered'],
            ['id' => 102, 'seller_is' => 'admin', 'seller_id' => 1, 'order_status' => 'delivered'],
        ]);
        DB::table('order_details')->insert([
            ['order_id' => 101, 'product_id' => 1],
            ['order_id' => 102, 'product_id' => 2],
        ]);

        DB::table('provider_fulfillments')->insert([
            ['id' => 96, 'order_id' => 102, 'provider' => 'torod_point', 'generation' => 1, 'reference' => '102-TP1', 'idempotency_key' => '102-TP1', 'status' => 'pending'],
            ['id' => 97, 'order_id' => 101, 'provider' => 'torod_point', 'generation' => 1, 'reference' => '101-TP1', 'idempotency_key' => '101-TP1', 'status' => 'pending'],
        ]);
    }

    private function asKuwaitHand(): static
    {
        return $this->asHandOf(adminId: self::KUWAIT_HAND, countries: ['KW']);
    }

    private function asSaudiHand(): static
    {
        return $this->asHandOf(adminId: self::SAUDI_HAND, countries: ['SA']);
    }

    /** The same switch and the same role, no country: the employee every refusal is measured against. */
    private function asUnrestrictedHand(): static
    {
        $this->switchStaffScopesOn();

        return $this->signInAsStaff(adminId: self::UNRESTRICTED_HAND);
    }

    /** @param list<string> $countries */
    private function asHandOf(int $adminId, array $countries): static
    {
        $this->switchStaffScopesOn();
        $held = DB::table('admin_country_scopes')->where('admin_id', $adminId)->pluck('country')->all();
        $this->giveCountries(adminId: $adminId, countries: array_values(array_diff($countries, $held)));

        return $this->signInAsStaff(adminId: $adminId);
    }

    // ---- the channel listings: the ACCOUNT carries the market, not the product -----------------------------------------------------------

    public function test_a_channel_listing_of_an_account_of_another_market_cannot_be_ignored(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.products.ignore', 82))->assertNotFound();
        $this->assertSame('mapped', DB::table('sales_channel_product_mappings')->where('id', 82)->value('status'));

        $this->asUnrestrictedHand()->post(route('admin.sales-channels.products.ignore', 82))->assertRedirect();
        $this->assertSame('ignored', DB::table('sales_channel_product_mappings')->where('id', 82)->value('status'));
    }

    public function test_a_channel_listing_of_an_account_that_names_no_market_is_refused(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.products.ignore', 83))->assertNotFound();
        $this->asSaudiHand()->post(route('admin.sales-channels.products.ignore', 83))->assertNotFound();
        $this->assertSame('unmapped', DB::table('sales_channel_product_mappings')->where('id', 83)->value('status'));

        $this->asUnrestrictedHand()->post(route('admin.sales-channels.products.ignore', 83))->assertRedirect();
        $this->assertSame('ignored', DB::table('sales_channel_product_mappings')->where('id', 83)->value('status'));
    }

    public function test_an_unlinked_listing_of_the_employees_own_account_is_still_theirs_to_work_with(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.products.ignore', 81))->assertRedirect();

        $this->assertSame('ignored', DB::table('sales_channel_product_mappings')->where('id', 81)->value('status'));
    }

    public function test_linking_a_listing_to_a_product_of_another_market_is_refused_and_writes_nothing(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.products.link', 81), ['product_id' => 1])->assertNotFound();

        $this->assertNull(DB::table('sales_channel_product_mappings')->where('id', 81)->value('product_id'));

        $this->asKuwaitHand()->post(route('admin.sales-channels.products.link', 81), ['product_id' => 2])->assertRedirect();

        $this->assertSame(2, (int) DB::table('sales_channel_product_mappings')->where('id', 81)->value('product_id'));
    }

    public function test_the_channel_listings_list_holds_only_the_accounts_of_the_employees_markets(): void
    {
        $listings = $this->asKuwaitHand()->get(route('admin.sales-channels.products.index'))->assertOk()->viewData('listings');

        $this->assertSame([81], $listings->pluck('id')->all());

        $everyMarket = $this->asUnrestrictedHand()->get(route('admin.sales-channels.products.index'))->assertOk()->viewData('listings');

        $this->assertSame([83, 82, 81], $everyMarket->pluck('id')->all());
    }

    // ---- the channel orders: the account again, because `order_id` is null until we fulfil one -------------------------------------------

    public function test_a_channel_order_of_another_markets_account_cannot_be_opened_or_routed(): void
    {
        $this->asKuwaitHand()->get(route('admin.sales-channels.orders.show', 87))->assertNotFound();
        $this->asKuwaitHand()->post(route('admin.sales-channels.orders.route', 87))->assertNotFound();

        $this->asUnrestrictedHand()->get(route('admin.sales-channels.orders.show', 87))->assertOk();
    }

    public function test_the_channel_orders_list_holds_only_the_employees_accounts(): void
    {
        $orders = $this->asKuwaitHand()->get(route('admin.sales-channels.orders.index'))->assertOk()->viewData('orders');

        $this->assertSame([86], $orders->pluck('id')->all());
    }

    // ---- our product going out: the draft follows the product --------------------------------------------------------------------------

    public function test_a_listing_draft_of_another_markets_product_cannot_be_withdrawn(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.listings.withdraw', 84))->assertNotFound();
        $this->assertSame('draft', DB::table('channel_listing_drafts')->where('id', 84)->value('state'));

        $this->asUnrestrictedHand()->post(route('admin.sales-channels.listings.withdraw', 84))->assertRedirect();
        $this->assertNotSame('draft', DB::table('channel_listing_drafts')->where('id', 84)->value('state'));
    }

    public function test_the_channel_screen_of_a_product_of_another_market_cannot_be_opened(): void
    {
        $this->asKuwaitHand()->get(route('admin.sales-channels.listings.show', 1))->assertNotFound();
        $this->asUnrestrictedHand()->get(route('admin.sales-channels.listings.show', 1))->assertOk();
    }

    /**
     * The drafts a listing screen lists are the drafts of ONE product, and the rule has already judged that product -- so the row scope on
     * `ChannelListingDraft` is reached by no screen of this slice, and the teeth check is what showed it: taking the scope off failed
     * nothing. It is kept because the table's rows carry a market through their product and the next screen to list them across products
     * would otherwise ship unnarrowed, and it is asserted here directly rather than left as coverage a screen test does not actually give.
     */
    public function test_a_draft_query_of_a_narrowed_request_reaches_only_the_employees_products(): void
    {
        $this->asKuwaitHand()->get(route('admin.sales-channels.listings.show', 2))->assertOk();

        app(StaffCountryScope::class)->bind(request: request(), admin: Admin::query()->findOrFail(self::KUWAIT_HAND));

        $this->assertSame([85], ChannelListingDraft::query()->orderBy('id')->pluck('id')->all());
    }

    // ---- the warehouse itself, which is what `coversEveryInventoryLocation()` was built for ---------------------------------------------

    public function test_the_channel_stock_screen_lists_only_the_employees_warehouses(): void
    {
        $locations = $this->asKuwaitHand()->get(route('admin.sales-channels.inventory.index'))->assertOk()->viewData('locations');

        $this->assertSame([88], $locations->pluck('id')->all());

        // The twin sees the Kuwaiti shelf, the Saudi one and the unmarked one -- and the platform's own unmarked locations besides, which is
        // why this is a containment and not an equality: what matters is that the three the restricted employee was narrowed to one of are
        // all there for an employee who is narrowed to none.
        $everyMarket = $this->asUnrestrictedHand()->get(route('admin.sales-channels.inventory.index'))->assertOk()->viewData('locations');

        $this->assertSame([88, 89, 90], array_values(array_intersect($everyMarket->pluck('id')->all(), [88, 89, 90])));
    }

    public function test_the_channel_stock_screen_refuses_a_warehouse_of_another_market(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.inventory.locations.channels', 89), ['channels' => []])->assertNotFound();
        $this->asUnrestrictedHand()->post(route('admin.sales-channels.inventory.locations.channels', 89), ['channels' => []])->assertRedirect();
    }

    public function test_an_unmarked_warehouse_is_the_default_storefronts_and_only_its_employee_reaches_it(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.inventory.locations.channels', 90), ['channels' => []])->assertNotFound();
        $this->asSaudiHand()->post(route('admin.sales-channels.inventory.locations.channels', 90), ['channels' => []])->assertRedirect();
    }

    // ---- calling a marketplace is an account's connection ------------------------------------------------------------------------------

    public function test_a_sync_of_an_account_of_another_market_is_refused(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.accounts.sync', [71, 'listings']))->assertNotFound();
        $this->asUnrestrictedHand()->post(route('admin.sales-channels.accounts.sync', [71, 'listings']))->assertRedirect();
    }

    // ---- the routing rules -------------------------------------------------------------------------------------------------------------

    public function test_a_routing_rule_of_another_markets_product_cannot_be_toggled(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.routing.rules.toggle', 92))->assertNotFound();
        $this->assertSame(1, (int) DB::table('fulfillment_routing_rules')->where('id', 92)->value('is_active'));

        $this->asUnrestrictedHand()->post(route('admin.sales-channels.routing.rules.toggle', 92))->assertRedirect();
        $this->assertSame(0, (int) DB::table('fulfillment_routing_rules')->where('id', 92)->value('is_active'));
    }

    public function test_a_rule_that_names_neither_a_product_nor_a_country_decides_for_every_market_and_is_refused(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.routing.rules.toggle', 93))->assertNotFound();
        $this->asSaudiHand()->post(route('admin.sales-channels.routing.rules.toggle', 93))->assertNotFound();
        $this->assertSame(1, (int) DB::table('fulfillment_routing_rules')->where('id', 93)->value('is_active'));

        $this->asUnrestrictedHand()->post(route('admin.sales-channels.routing.rules.toggle', 93))->assertRedirect();
        $this->assertSame(0, (int) DB::table('fulfillment_routing_rules')->where('id', 93)->value('is_active'));
    }

    public function test_a_rule_of_the_employees_own_product_is_theirs_to_toggle(): void
    {
        $this->asKuwaitHand()->post(route('admin.sales-channels.routing.rules.toggle', 91))->assertRedirect();

        $this->assertSame(0, (int) DB::table('fulfillment_routing_rules')->where('id', 91)->value('is_active'));
    }

    // ---- the fulfilment providers ------------------------------------------------------------------------------------------------------

    public function test_a_provider_shipment_of_another_markets_order_cannot_be_opened(): void
    {
        $this->asKuwaitHand()->get(route('admin.fulfillment-providers.fulfillments.show', 97))->assertNotFound();
        $this->asUnrestrictedHand()->get(route('admin.fulfillment-providers.fulfillments.show', 97))->assertOk();
    }

    public function test_the_provider_shipment_queue_holds_only_the_employees_orders(): void
    {
        $shipments = $this->asKuwaitHand()->get(route('admin.fulfillment-providers.fulfillments.index'))->assertOk()->viewData('fulfillments');

        $this->assertSame([96], $shipments->pluck('id')->all());
    }

    public function test_a_providers_own_catalogue_belongs_to_the_default_storefronts_market(): void
    {
        $this->asKuwaitHand()->get(route('admin.fulfillment-providers.m5azn.products'))->assertNotFound();
        $this->asKuwaitHand()->get(route('admin.fulfillment-providers.torod.matching'))->assertNotFound();

        $this->asSaudiHand()->get(route('admin.fulfillment-providers.m5azn.products'))->assertOk();
    }

    // ---- the payment-gateway monitor, which can be told apart by nothing ---------------------------------------------------------------

    public function test_the_payment_monitor_is_refused_to_a_restricted_employee(): void
    {
        $this->asKuwaitHand()->get(route('admin.third-party.payment-method.myfatoorah.index'))->assertNotFound();
        $this->asSaudiHand()->get(route('admin.third-party.payment-method.myfatoorah.index'))->assertNotFound();

        // The unrestricted twin, which is what tells a guard from a URL that was never there. Its own page cannot be RENDERED here -- it
        // reads the gateway's `addon_settings` row, and the inferred core schema of these suites has no such table -- so the twin is
        // measured on the refund action against an attempt that EXISTS: the 404 above is then this file's guard and nothing else, since
        // without the guard the controller refuses the amount and redirects.
        DB::table('myfatoorah_payment_attempts')->insert([
            'id' => 31, 'payment_request_id' => 'req-31', 'external_identifier' => 'ext-31', 'expected_amount' => 10,
            'expected_currency' => 'SAR', 'status' => 'created', 'mode' => 'test',
        ]);

        $this->asKuwaitHand()->post(route('admin.third-party.payment-method.myfatoorah.attempts.refund', 31), ['amount' => 1])->assertNotFound();
        $this->asUnrestrictedHand()->post(route('admin.third-party.payment-method.myfatoorah.attempts.refund', 31), ['amount' => 1])->assertRedirect();
    }

    // ---- the operational costs ---------------------------------------------------------------------------------------------------------

    public function test_a_cost_layer_of_another_markets_product_cannot_be_opened(): void
    {
        $this->asKuwaitHand()->get(route('admin.operational-costs.product-costs.show', 94))->assertNotFound();
        $this->asUnrestrictedHand()->get(route('admin.operational-costs.product-costs.show', 94))->assertOk();
    }

    public function test_the_cost_layer_list_holds_only_the_employees_products(): void
    {
        $layers = $this->asKuwaitHand()->get(route('admin.operational-costs.product-costs.index'))->assertOk()->viewData('layers');

        $this->assertSame([95], $layers->pluck('id')->all());
    }

    public function test_the_cost_overview_and_the_profit_list_are_refused_rather_than_shown_as_one_markets(): void
    {
        $this->asKuwaitHand()->get(route('admin.operational-costs.index'))->assertNotFound();
        $this->asKuwaitHand()->get(route('admin.operational-costs.profit.index'))->assertNotFound();
        $this->asKuwaitHand()->get(route('admin.operational-costs.profit.export'))->assertNotFound();

        $this->asUnrestrictedHand()->get(route('admin.operational-costs.index'))->assertOk();
    }

    public function test_a_profit_page_of_an_order_of_another_market_is_refused(): void
    {
        $this->asKuwaitHand()->get(route('admin.operational-costs.profit.order', [SaleDocument::SUBJECT_ORDER, 101]))->assertNotFound();
        $this->asKuwaitHand()->get(route('admin.operational-costs.profit.order', [SaleDocument::SUBJECT_CHANNEL_ORDER, 87]))->assertNotFound();
    }

    public function test_the_return_queue_holds_only_the_lines_of_the_employees_channel_orders(): void
    {
        $lines = $this->asKuwaitHand()->get(route('admin.operational-costs.returns.index'))->assertOk()->viewData('lines');

        $this->assertSame([51], $lines->pluck('id')->all());
    }

    public function test_a_return_decision_on_a_line_of_another_markets_channel_order_is_refused(): void
    {
        $decision = ['units_restocked' => 1, 'units_written_off' => 0, 'token' => 'staff-slice-six'];

        $this->asKuwaitHand()->post(route('admin.operational-costs.returns.decide', 52), $decision)->assertNotFound();
        $this->asUnrestrictedHand()->post(route('admin.operational-costs.returns.decide', 52), $decision)->assertRedirect();
    }

    public function test_recording_a_cost_in_a_warehouse_of_another_market_is_refused(): void
    {
        $cost = ['product_id' => 2, 'location_id' => 89, 'quantity' => 1, 'unit_cost' => 5, 'currency' => 'SAR', 'source_type' => 'purchase', 'confirm_separate_purchase' => 1];

        $this->asKuwaitHand()->post(route('admin.operational-costs.product-costs.store'), $cost)->assertNotFound();

        $this->assertSame(2, DB::table('inventory_cost_layers')->count());
    }

    // ---- the registries themselves ----------------------------------------------------------------------------------------------------

    public function test_every_public_action_of_every_covered_controller_has_a_rule(): void
    {
        $without = [];

        foreach ([...StaffIntegrationRoutes::controllers(), ...StaffCostRoutes::controllers()] as $controller) {
            $rules = StaffCostRoutes::rulesOf(controller: $controller) ?: StaffIntegrationRoutes::rulesOf(controller: $controller);

            foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $controller && !$method->isConstructor() && !isset($rules[$method->getName()])) {
                    $without[] = $controller . '::' . $method->getName();
                }
            }
        }

        $this->assertSame([], $without, 'an action of a covered controller has no rule, so a restricted employee is refused it: ' . implode(', ', $without));
    }

    public function test_the_two_registries_name_the_fourteen_controllers_of_the_slice(): void
    {
        $this->assertCount(10, StaffIntegrationRoutes::controllers());
        $this->assertCount(4, StaffCostRoutes::controllers());
    }
}
