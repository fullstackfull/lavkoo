<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderApiLog;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderFulfillment;
use App\Models\ProviderPriceChange;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\ProviderConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class AdminPagesTest extends AdminPagesTestCase
{
    private function assertNoSecretLeaks(string $html): void
    {
        foreach (self::SECRETS as $name => $secret) {
            $this->assertStringNotContainsString($secret, $html, "the stored {$name} must never be rendered");
        }
    }

    private function productRow(int $id, string $addedBy = 'admin'): Product
    {
        return (new Product())->forceFill(['id' => $id, 'added_by' => $addedBy]);
    }

    public function test_every_admin_route_is_behind_the_admin_guard_and_the_third_party_permission(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(static fn ($route): bool => str_starts_with($route->uri(), 'admin/fulfillment-providers'));

        $this->assertGreaterThan(20, $routes->count());
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('admin', $middleware, $route->uri());
            $this->assertContains('module:3rd_party_setup', $middleware, $route->uri());
            $this->assertContains('actch:admin_panel', $middleware, $route->uri());
        }
    }

    public function test_the_integration_center_shows_both_providers_and_no_secret(): void
    {
        ProviderSyncState::create(['provider' => 'torod_point', 'capability' => 'products', 'last_success_at' => now(), 'stats' => ['count' => 12]]);
        ProviderSyncState::create(['provider' => 'm5azn', 'capability' => 'connection', 'last_failure_at' => now(), 'last_error' => 'HTTP 401 from provider']);
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Held, ['hold_reason' => HoldReason::CITY_UNMAPPED]);

        $html = $this->get(route('admin.fulfillment-providers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('M5AZN', $html);
        $this->assertStringContainsString('Torod Point', $html);
        $this->assertStringContainsString('HTTP 401 from provider', $html, 'the last API error is surfaced');
        $this->assertStringContainsString(e(route('admin.fulfillment-providers.fulfillments.index', ['provider' => 'torod_point', 'status' => 'held'])), $html);
        $this->assertNoSecretLeaks($html);
    }

    public function test_the_integration_center_surfaces_system_problems_before_they_cost_orders(): void
    {
        config(['fulfillment.enabled' => true, 'queue.connections.database.retry_after' => 90]);

        $html = $this->get(route('admin.fulfillment-providers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('System checks', $html);
        $this->assertStringContainsString('retry_after', $html);
        $this->assertStringContainsString('scheduler heartbeat', $html, 'a scheduler that never ran is called out');
    }

    public function test_the_integration_center_says_when_the_environment_switch_is_off(): void
    {
        config(['fulfillment.enabled' => false]);

        $this->get(route('admin.fulfillment-providers.index'))->assertOk()->assertSee('FULFILLMENT_INTEGRATIONS_ENABLED');
    }

    public function test_provider_settings_pages_render_with_masked_credentials(): void
    {
        foreach (['m5azn', 'torod-point'] as $slug) {
            $html = $this->get(route('admin.fulfillment-providers.show', $slug))->assertOk()->getContent();

            $this->assertNoSecretLeaks($html);
            $this->assertStringContainsString('••••••••', $html, "{$slug}: a stored credential shows only that it exists");
        }
    }

    public function test_the_torod_page_prefills_company_id_714_as_a_suggestion_and_shows_both_api_families(): void
    {
        DB::table('integration_settings')->where('provider', 'torod_point')->update(['settings' => json_encode([])]);

        $html = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="714"', $html);
        $this->assertStringContainsString('apisv5.logestechs.com', $html);
        $this->assertStringContainsString('apisv2.logestechs.com', $html);
        $this->assertStringContainsString('name="fulfillment_username"', $html);
        $this->assertStringContainsString('name="lastmile_email"', $html);
    }

    public function test_the_webhook_urls_hide_the_secret_until_it_is_rotated(): void
    {
        $masked = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->assertOk()->getContent();
        $this->assertStringContainsString('/api/fulfillment-webhooks/torod-point/', $masked);
        $this->assertStringContainsString('••••••••1234', $masked);
        $this->assertStringNotContainsString(self::SECRETS['webhook_secret'], $masked);

        $this->post(route('admin.fulfillment-providers.rotate-secret', 'torod-point'))->assertRedirect();
        $newSecret = app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint)->secret('webhook_secret');
        $this->assertNotSame(self::SECRETS['webhook_secret'], $newSecret);

        $once = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->getContent();
        $this->assertStringContainsString($newSecret, $once, 'shown in full right after it is generated');

        $later = $this->get(route('admin.fulfillment-providers.show', 'torod-point'))->getContent();
        $this->assertStringNotContainsString($newSecret, $later, 'and never again');
    }

    public function test_saving_settings_keeps_credentials_write_only_and_encrypted(): void
    {
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), [
            'enabled' => 1, 'dispatch_enabled' => 1, 'dry_run' => 1, 'email' => 'new-seller@secret.test', 'password' => '',
        ])->assertSessionHasNoErrors();

        $config = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);
        $this->assertSame('new-seller@secret.test', $config->secret('email'));
        $this->assertSame(self::SECRETS['m5azn_password'], $config->secret('password'), 'a blank password field keeps the stored one');
        $this->assertTrue($config->bool('dry_run'));

        $stored = (string) DB::table('integration_settings')->where('provider', 'm5azn')->value('credentials');
        $this->assertStringNotContainsString('new-seller@secret.test', $stored, 'encrypted at rest');
        $this->assertStringNotContainsString(self::SECRETS['m5azn_password'], $stored);
    }

    public function test_a_stored_secret_is_cleared_only_by_its_own_explicit_switch(): void
    {
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), [
            'enabled' => 1, 'company_id' => '714', 'clear_secrets' => ['lastmile_password'],
        ])->assertSessionHasNoErrors();

        $config = app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint);
        $this->assertFalse($config->hasSecret('lastmile_password'));
        $this->assertSame(self::SECRETS['torod_password'], $config->secret('fulfillment_password'));
        $this->assertSame(self::SECRETS['lastmile_email'], $config->secret('lastmile_email'));
    }

    public function test_settings_that_could_send_credentials_elsewhere_are_refused(): void
    {
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'base_url' => 'https://evil.example.com'])
            ->assertSessionHasErrors('base_url');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'base_url' => 'http://apisv5.logestechs.com/api'])
            ->assertSessionHasErrors('base_url');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'lastmile_base_url' => 'https://logestechs.com.evil.example/api'])
            ->assertSessionHasErrors('lastmile_base_url');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['company_id' => '714; DROP TABLE'])
            ->assertSessionHasErrors('company_id');
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['product_defaults' => json_encode(['quantity' => 500])])
            ->assertSessionHasErrors('product_defaults');

        $this->assertStringNotContainsString('evil', app(ProviderConnectionManager::class)->config(ProviderCode::M5azn)->baseUrl);
        $this->assertStringNotContainsString('evil', app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint)->string('lastmile_base_url'));
        $this->assertSame('714', app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint)->string('company_id'), 'a refused save changes nothing');
    }

    public function test_sync_intervals_and_product_types_are_validated_and_saved(): void
    {
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'product_sync_interval_minutes' => 2])
            ->assertSessionHasErrors('product_sync_interval_minutes');
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'inventory_sync_interval_minutes' => 5000])
            ->assertSessionHasErrors('inventory_sync_interval_minutes');
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'catalog_product_type' => 'local, shoes'])
            ->assertSessionHasErrors('catalog_product_type');

        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), [
            'enabled' => 1, 'product_sync_interval_minutes' => 10, 'inventory_sync_interval_minutes' => 15, 'catalog_product_type' => 'local, global',
        ])->assertSessionHasNoErrors();

        $config = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);
        $this->assertSame(10, $config->int('product_sync_interval_minutes'));
        $this->assertSame(15, $config->int('inventory_sync_interval_minutes'));
        $this->assertSame('local, global', $config->string('catalog_product_type'));
    }

    public function test_an_unknown_provider_slug_is_a_404(): void
    {
        $this->get('/admin/fulfillment-providers/dhl')->assertNotFound();
    }

    public function test_the_shipments_list_filters_by_provider_status_and_attention(): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Held, ['hold_reason' => HoldReason::MAPPING_MISSING]);
        $this->shipment(101, ProviderCode::M5azn, FulfillmentStatus::Delivered, ['tracking_number' => 'TRK-DELIVERED'], productId: 2);
        $this->shipment(102, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['tracking_number' => 'TRK-MOVING'], productId: 3);
        $this->shipment(103, ProviderCode::TorodPoint, FulfillmentStatus::Failed, ['last_error_message' => 'Provider rejected the address'], productId: 4);

        $all = $this->get(route('admin.fulfillment-providers.fulfillments.index'))->assertOk()->getContent();
        foreach (['100-TP1', '101-MZ1', '102-MZ1', '103-TP1'] as $reference) {
            $this->assertStringContainsString($reference, $all);
        }
        $this->assertStringContainsString('A product is not matched to a provider product.', $all, 'a hold shows its reason');
        $this->assertStringContainsString('Provider rejected the address', $all);

        $attention = $this->get(route('admin.fulfillment-providers.fulfillments.index', ['attention' => 1]))->getContent();
        $this->assertStringContainsString('100-TP1', $attention);
        $this->assertStringContainsString('103-TP1', $attention);
        $this->assertStringNotContainsString('101-MZ1', $attention);
        $this->assertStringNotContainsString('102-MZ1', $attention);

        $m5aznOnly = $this->get(route('admin.fulfillment-providers.fulfillments.index', ['provider' => 'm5azn', 'status' => 'in_transit']))->getContent();
        $this->assertStringContainsString('102-MZ1', $m5aznOnly);
        $this->assertStringNotContainsString('101-MZ1', $m5aznOnly);

        $byTracking = $this->get(route('admin.fulfillment-providers.fulfillments.index', ['q' => 'TRK-DELIVERED']))->getContent();
        $this->assertStringContainsString('101-MZ1', $byTracking);
        $this->assertStringNotContainsString('102-MZ1', $byTracking);
    }

    public function test_an_empty_shipments_list_explains_itself(): void
    {
        $this->get(route('admin.fulfillment-providers.fulfillments.index'))->assertOk()->assertSee('No provider shipments yet');
    }

    public function test_the_shipment_page_offers_only_the_actions_that_fit_its_state(): void
    {
        $held = $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Held, ['hold_reason' => HoldReason::CITY_UNMAPPED]);
        $unknown = $this->shipment(101, ProviderCode::TorodPoint, FulfillmentStatus::ReconcilePending, [], productId: 2);
        $inFlight = $this->shipment(102, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-77'], productId: 3);
        $done = $this->shipment(103, ProviderCode::M5azn, FulfillmentStatus::Delivered, [], productId: 4);
        $cancelled = $this->shipment(104, ProviderCode::M5azn, FulfillmentStatus::Cancelled, [], productId: 5);

        $page = fn (ProviderFulfillment $fulfillment): string => $this->get(route('admin.fulfillment-providers.fulfillments.show', $fulfillment->id))->assertOk()->getContent();
        $has = fn (string $html, string $action): bool => str_contains($html, '/' . $action . '"');

        $heldHtml = $page($held);
        $this->assertTrue($has($heldHtml, 'release'));
        $this->assertFalse($has($heldHtml, 'mark-delivered'), 'a shipment the provider never accepted cannot be closed by hand');
        $this->assertStringContainsString('The delivery city has no matching provider city.', $heldHtml);

        $unknownHtml = $page($unknown);
        $this->assertTrue($has($unknownHtml, 'confirm-not-created'));
        $this->assertTrue($has($unknownHtml, 'link'));
        $this->assertStringContainsString('Nothing is re-sent until the outcome is known', $unknownHtml);

        $inFlightHtml = $page($inFlight);
        foreach (['refresh', 'cancel', 'mark-delivered', 'mark-cancelled'] as $action) {
            $this->assertTrue($has($inFlightHtml, $action), $action);
        }
        $this->assertFalse($has($inFlightHtml, 'release'));

        $doneHtml = $page($done);
        foreach (['cancel', 'mark-delivered', 'mark-cancelled', 'release', 'redispatch', 'confirm-not-created'] as $action) {
            $this->assertFalse($has($doneHtml, $action), "a delivered shipment offers no {$action}");
        }

        $this->assertTrue($has($page($cancelled), 'redispatch'));
    }

    public function test_the_shipment_page_lists_items_events_and_api_calls_without_secrets(): void
    {
        $fulfillment = $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Submitted, ['provider_reference' => 'MZ-1', 'last_error_message' => 'Gateway timeout']);
        $fulfillment->events()->create(['provider' => 'm5azn', 'source' => 'poll', 'event_type' => 'status', 'provider_status' => 'shipped', 'internal_status' => 'in_transit', 'outcome' => 'applied', 'received_at' => now()]);
        ProviderApiLog::create([
            'provider' => 'm5azn', 'operation' => 'order.create', 'provider_fulfillment_id' => $fulfillment->id, 'correlation_id' => 'abc123', 'method' => 'POST',
            'url' => 'https://m5azn.com/api/orders', 'success' => false, 'response_status' => 502, 'error_message' => 'Bad gateway', 'created_at' => now(),
        ]);

        $html = $this->get(route('admin.fulfillment-providers.fulfillments.show', $fulfillment->id))->assertOk()->getContent();

        foreach (['Product 1', 'MZ-1', 'Gateway timeout', 'order.create', 'Bad gateway', 'in_transit'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertNoSecretLeaks($html);
    }

    public function test_an_operator_action_that_asserts_a_fact_needs_a_reason(): void
    {
        $unknown = $this->shipment(101, ProviderCode::TorodPoint, FulfillmentStatus::ReconcilePending);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $unknown->id, 'action' => 'confirm-not-created']), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(FulfillmentStatus::ReconcilePending, $unknown->refresh()->status);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $unknown->id, 'action' => 'link']), ['reason' => 'checked in the portal'])
            ->assertSessionHasErrors('reference');
        $this->assertSame(FulfillmentStatus::ReconcilePending, $unknown->refresh()->status);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $unknown->id, 'action' => 'link']), [
            'reference' => 'WH-5551', 'tracking_number' => 'TRK1', 'reason' => 'found it in the LogesTechs portal',
        ])->assertSessionHasNoErrors();
        $this->assertSame(FulfillmentStatus::Submitted, $unknown->refresh()->status);
        $this->assertSame('WH-5551', $unknown->provider_reference);
    }

    public function test_the_shipment_page_says_what_the_cancellation_has_reached(): void
    {
        $blocked = $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-1', 'cancel_blocked_reason' => 'unsupported: Cancel it in the <b>M5AZN</b> dashboard']);
        $requested = $this->shipment(101, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-2', 'cancel_requested_at' => now()], productId: 2);
        $wanted = $this->shipment(102, ProviderCode::M5azn, FulfillmentStatus::ReconcilePending, ['cancel_intent_at' => now()], productId: 3);
        $plain = $this->shipment(103, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-4'], productId: 4);
        $page = fn (ProviderFulfillment $fulfillment): string => $this->get(route('admin.fulfillment-providers.fulfillments.show', $fulfillment->id))->assertOk()->getContent();

        $blockedHtml = $page($blocked);
        $this->assertStringContainsString('Automatic cancellation cannot finish', $blockedHtml);
        $this->assertStringContainsString('Cancel it in the &lt;b&gt;M5AZN&lt;/b&gt; dashboard', $blockedHtml, 'provider text is escaped, never rendered as markup');
        $this->assertStringContainsString('Cancellation requested.', $page($requested));
        $this->assertStringContainsString('Cancellation is wanted and will be sent', $page($wanted));

        $plainHtml = $page($plain);
        foreach (['Automatic cancellation cannot finish', 'Cancellation requested.', 'Cancellation is wanted'] as $banner) {
            $this->assertStringNotContainsString($banner, $plainHtml);
        }
    }

    public function test_a_blocked_cancellation_appears_under_needs_attention(): void
    {
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-1', 'cancel_blocked_reason' => 'unsupported'], productId: 1);
        $this->shipment(101, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-2'], productId: 2);

        $html = $this->get(route('admin.fulfillment-providers.fulfillments.index', ['attention' => 1]))->getContent();

        $this->assertStringContainsString('100-MZ1', $html);
        $this->assertStringNotContainsString('101-MZ1', $html);
    }

    public function test_cancelling_at_a_provider_that_cannot_says_so_instead_of_reporting_success(): void
    {
        config(['queue.default' => 'sync']);
        $shipment = $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['provider_reference' => 'MZ-1']);
        $this->m5azn->cancelQueue = [\App\Services\Fulfillment\Data\CancellationResult::unsupported('M5AZN documents no cancel endpoint.')];

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $shipment->id, 'action' => 'cancel']))->assertRedirect();

        $this->assertTrue($shipment->refresh()->isCancelBlocked());
        $this->assertNull($shipment->cancel_requested_at);
    }

    public function test_re_dispatching_a_cancelled_shipment_needs_a_stated_check_of_the_original(): void
    {
        $cancelled = $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Cancelled);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $cancelled->id, 'action' => 'redispatch']), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame($cancelled->id, \App\Models\ProviderFulfillmentItem::where('order_id', 100)->first()->provider_fulfillment_id);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $cancelled->id, 'action' => 'redispatch']), ['reason' => 'M5AZN dashboard shows it cancelled'])->assertSessionHasNoErrors();
        $this->assertNull(\App\Models\ProviderFulfillmentItem::where('order_id', 100)->first()->provider_fulfillment_id);
    }

    public function test_an_unknown_operator_action_is_a_404(): void
    {
        $fulfillment = $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Held);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $fulfillment->id, 'action' => 'drop-database']))->assertNotFound();
    }

    public function test_a_held_shipment_can_be_released_but_a_money_hold_cannot(): void
    {
        $city = $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Held, ['hold_reason' => HoldReason::CITY_UNMAPPED]);
        $money = $this->shipment(101, ProviderCode::TorodPoint, FulfillmentStatus::Held, ['hold_reason' => HoldReason::MIXED_COD_BLOCKED], productId: 2);

        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $city->id, 'action' => 'release']))->assertRedirect();
        $this->post(route('admin.fulfillment-providers.fulfillments.action', ['id' => $money->id, 'action' => 'release']))->assertRedirect();

        $this->assertSame(FulfillmentStatus::Pending, $city->refresh()->status);
        $this->assertSame(FulfillmentStatus::Held, $money->refresh()->status, 'only fixing the cause releases a money hold');
    }

    public function test_the_api_log_page_filters_and_redacts(): void
    {
        ProviderApiLog::create(['provider' => 'm5azn', 'operation' => 'catalog.list', 'correlation_id' => 'c1', 'method' => 'GET', 'url' => 'https://m5azn.com/api/seller/myproductlist', 'success' => true, 'response_status' => 200, 'created_at' => now()]);
        ProviderApiLog::create(['provider' => 'torod_point', 'operation' => 'stock.sync', 'correlation_id' => 'c2', 'method' => 'POST', 'url' => 'https://apisv5.logestechs.com/api/orders', 'success' => false, 'response_status' => 500, 'error_message' => 'Server error', 'created_at' => now()]);

        $all = $this->get(route('admin.fulfillment-providers.logs'))->assertOk()->getContent();
        $this->assertStringContainsString('catalog.list', $all);
        $this->assertStringContainsString('stock.sync', $all);

        $failed = $this->get(route('admin.fulfillment-providers.logs', ['result' => 'failed']))->getContent();
        $this->assertStringNotContainsString('catalog.list', $failed);
        $this->assertStringContainsString('Server error', $failed);

        $byOperation = $this->get(route('admin.fulfillment-providers.logs', ['operation' => 'catalog']))->getContent();
        $this->assertStringContainsString('catalog.list', $byOperation);
        $this->assertStringNotContainsString('stock.sync', $byOperation);
    }

    public function test_the_m5azn_products_page_shows_cost_price_and_the_margin_limitation(): void
    {
        $this->makeProduct(7, ProviderCode::M5azn, ['unit_price' => 10, 'name' => 'Imported vitamin']);
        ProviderProductMapping::create([
            'provider' => 'm5azn', 'product_id' => 7, 'variant_key' => '', 'external_id' => 'M-7', 'external_variant_id' => '', 'status' => MappingStatus::Approved,
            'stock_source' => StockSource::ProviderSync, 'provider_stock' => 25, 'supplier_cost' => 6, 'supplier_cost_currency' => 'SAR',
        ]);
        ProviderCatalogItem::create(['provider' => 'm5azn', 'external_id' => 'M-7', 'payload' => ['id' => 'M-7'], 'payload_hash' => 'h1', 'name' => 'Imported vitamin', 'sku' => 'VIT-1', 'status' => 'imported', 'product_id' => 7]);
        ProviderCatalogItem::create(['provider' => 'm5azn', 'external_id' => 'M-8', 'payload' => ['id' => 'M-8'], 'payload_hash' => 'h2', 'name' => 'Possible duplicate', 'sku' => 'DUP-1', 'status' => 'needs_review', 'note' => 'Same barcode as an existing product']);
        ProviderPriceChange::create(['provider' => 'm5azn', 'product_id' => 7, 'field' => 'supplier_cost', 'old_value' => 5, 'new_value' => 6, 'source' => 'sync', 'created_at' => now()]);

        $html = $this->get(route('admin.fulfillment-providers.m5azn.products'))->assertOk()->getContent();

        $this->assertStringContainsString('Imported vitamin', $html);
        $this->assertStringContainsString('4.00 (40%)', $html, 'selling price 10 minus supplier cost 6');
        $this->assertStringContainsString('provides no call to read or change a margin', $html, 'the limitation is stated plainly');
        $this->assertStringContainsString('Import as new anyway', $html);
        $this->assertStringContainsString('Same barcode as an existing product', $html);
        $this->assertStringContainsString('Supplier cost', $html);
        $this->assertStringContainsString('Synced from the provider', $html);
        $this->assertStringContainsString('the catalogue response fields have not been verified', strtolower($html));
    }

    public function test_the_m5azn_products_page_filters_by_status(): void
    {
        ProviderCatalogItem::create(['provider' => 'm5azn', 'external_id' => 'A', 'payload' => [], 'payload_hash' => 'a', 'name' => 'Fresh item', 'status' => 'new']);
        ProviderCatalogItem::create(['provider' => 'm5azn', 'external_id' => 'B', 'payload' => [], 'payload_hash' => 'b', 'name' => 'Broken item', 'status' => 'error']);

        $errors = $this->get(route('admin.fulfillment-providers.m5azn.products', ['status' => 'error']))->getContent();

        $this->assertStringContainsString('Broken item', $errors);
        $this->assertStringNotContainsString('Fresh item', $errors);
    }

    public function test_the_torod_matching_page_suggests_but_never_matches_by_itself(): void
    {
        $this->makeProduct(5, null, ['name' => 'Store vitamin D', 'code' => 'SKU-D', 'barcode' => 'BC-D']);
        $this->makeProduct(6, null, ['name' => 'Unrelated', 'code' => 'OTHER', 'barcode' => 'BC-X']);
        ProviderCatalogItem::create(['provider' => 'torod_point', 'external_id' => 'W-1', 'payload' => ['id' => 'W-1'], 'payload_hash' => 'w1', 'name' => 'Vitamin D warehouse', 'sku' => 'SKU-D', 'barcode' => 'BC-D', 'stock' => 40, 'status' => 'new']);

        $html = $this->get(route('admin.fulfillment-providers.torod.matching'))->assertOk()->getContent();

        $this->assertStringContainsString('Vitamin D warehouse', $html);
        $this->assertStringContainsString('Store vitamin D', $html);
        $this->assertStringContainsString('Same SKU', $html);
        $this->assertStringContainsString('Same barcode', $html);
        $this->assertStringNotContainsString('Unrelated', $html);
        $this->assertStringContainsString('Not confirmed as available quantity', $html, 'warehouse stock is labelled unconfirmed');
        $this->assertSame(0, ProviderProductMapping::count(), 'viewing suggestions links nothing');
        $this->assertNull(DB::table('products')->where('id', 5)->value('fulfillment_provider'));
    }

    public function test_approving_a_match_links_the_product_and_assigning_it_to_torod_point_is_a_separate_choice(): void
    {
        $this->makeProduct(5);
        $this->makeProduct(6);
        $first = ProviderCatalogItem::create(['provider' => 'torod_point', 'external_id' => 'W-1', 'payload' => [], 'payload_hash' => 'w1', 'name' => 'First', 'sku' => 'S1', 'status' => 'new']);
        $second = ProviderCatalogItem::create(['provider' => 'torod_point', 'external_id' => 'W-2', 'payload' => [], 'payload_hash' => 'w2', 'name' => 'Second', 'sku' => 'S2', 'status' => 'new']);

        $this->post(route('admin.fulfillment-providers.torod.approve'), ['catalog_item_id' => $first->id, 'product_id' => 5])->assertRedirect();
        $this->post(route('admin.fulfillment-providers.torod.approve'), ['catalog_item_id' => $second->id, 'product_id' => 6, 'assign_to_provider' => 1])->assertRedirect();

        $this->assertSame(2, ProviderProductMapping::where('status', MappingStatus::Approved->value)->count());
        $this->assertNull(DB::table('products')->where('id', 5)->value('fulfillment_provider'), 'a match alone assigns nothing');
        $this->assertSame('torod_point', DB::table('products')->where('id', 6)->value('fulfillment_provider'));
    }

    public function test_one_warehouse_product_cannot_be_matched_to_two_store_products(): void
    {
        $this->makeProduct(5);
        $this->makeProduct(6);
        $item = ProviderCatalogItem::create(['provider' => 'torod_point', 'external_id' => 'W-1', 'payload' => [], 'payload_hash' => 'w1', 'name' => 'First', 'status' => 'new']);

        $this->post(route('admin.fulfillment-providers.torod.approve'), ['catalog_item_id' => $item->id, 'product_id' => 5]);
        $this->post(route('admin.fulfillment-providers.torod.approve'), ['catalog_item_id' => $item->id, 'product_id' => 6]);

        $this->assertSame([5], ProviderProductMapping::pluck('product_id')->all());
    }

    public function test_manual_stock_is_labelled_manual(): void
    {
        $this->makeProduct(5);
        $mapping = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 5, 'variant_key' => '', 'external_id' => 'W-1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);

        $this->post(route('admin.fulfillment-providers.torod.stock'), ['mapping_id' => $mapping->id, 'quantity' => 12])->assertRedirect();

        $this->assertSame(StockSource::Manual, $mapping->refresh()->stock_source);
        $this->assertSame(12, $mapping->provider_stock);
        $this->assertStringContainsString('Entered manually', $this->get(route('admin.fulfillment-providers.torod.matching'))->getContent());
    }

    public function test_a_match_cannot_be_retired_while_its_product_still_belongs_to_the_provider(): void
    {
        $this->makeProduct(5, ProviderCode::TorodPoint);
        $mapping = ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 5, 'variant_key' => '', 'external_id' => 'W-1', 'external_variant_id' => '', 'status' => MappingStatus::Approved]);

        $this->post(route('admin.fulfillment-providers.torod.retire', $mapping->id))->assertRedirect();
        $this->assertSame(MappingStatus::Approved, $mapping->refresh()->status, 'move the product off the provider first');

        DB::table('products')->where('id', 5)->update(['fulfillment_provider' => null]);
        $this->post(route('admin.fulfillment-providers.torod.retire', $mapping->id))->assertRedirect();
        $this->assertSame(MappingStatus::Retired, $mapping->refresh()->status);
    }

    public function test_the_order_page_card_lists_each_shipment_and_links_only_for_permitted_admins(): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::InTransit, ['tracking_number' => 'TRK-9', 'tracking_url' => 'https://track.example.test/TRK-9']);
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Held, ['hold_reason' => HoldReason::MAPPING_MISSING, 'reference' => '100-MZ1'], productId: 2);
        $order = Order::findOrFail(100);

        $html = view('admin-views.order._fulfillments-card', ['order' => $order])->render();

        foreach (['100-TP1', '100-MZ1', 'TRK-9', 'Product 1', 'A product is not matched to a provider product.'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('This order is split across providers', $html);
        $this->assertStringContainsString(route('admin.fulfillment-providers.fulfillments.show', ProviderFulfillment::where('reference', '100-TP1')->value('id')), $html);

        $this->actingAs(Admin::findOrFail(2), 'admin');
        $withoutPermission = view('admin-views.order._fulfillments-card', ['order' => $order])->render();
        $this->assertStringContainsString('100-TP1', $withoutPermission, 'anyone who can see the order sees its shipments');
        $this->assertStringNotContainsString('admin/fulfillment-providers/fulfillments/', $withoutPermission, 'but only the third-party role gets the operator links');
    }

    public function test_the_order_page_card_is_empty_for_an_order_no_provider_fulfils(): void
    {
        $this->makeOrder(200);

        $this->assertSame('', trim(view('admin-views.order._fulfillments-card', ['order' => Order::findOrFail(200)])->render()));
    }

    public function test_the_order_page_warns_that_an_unfinished_provider_shipment_blocks_delivery(): void
    {
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::InTransit);

        $html = view('admin-views.order._fulfillments-card', ['order' => Order::findOrFail(100)])->render();

        $this->assertStringContainsString('Provider shipments for this order are not all delivered yet.', $html);
    }

    public function test_the_product_form_card_for_an_unmatched_product_offers_only_the_default(): void
    {
        $this->makeProduct(9);
        $html = view('admin-views.partials._fulfillment-provider', ['product' => $this->productRow(9)])->render();

        $this->assertStringContainsString('name="fulfillment_provider"', $html);
        foreach (['default', 'm5azn', 'torod_point'] as $value) {
            $this->assertStringContainsString('id="fulfillment_provider_' . $value . '"', $html);
        }
        $this->assertMatchesRegularExpression('/id="fulfillment_provider_default"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="fulfillment_provider_default"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/id="fulfillment_provider_torod_point"[^>]*disabled/', $html);
        $this->assertStringContainsString('Needs an approved match to a provider product first', $html);
    }

    public function test_the_product_form_card_enables_a_provider_once_its_match_is_approved(): void
    {
        $this->makeProduct(9);
        ProviderProductMapping::create(['provider' => 'torod_point', 'product_id' => 9, 'variant_key' => '', 'external_id' => 'W-9', 'external_variant_id' => '', 'external_sku' => 'WSKU-9', 'status' => MappingStatus::Approved]);

        $html = view('admin-views.partials._fulfillment-provider', ['product' => $this->productRow(9)])->render();

        $this->assertDoesNotMatchRegularExpression('/id="fulfillment_provider_torod_point"[^>]*disabled/', $html);
        $this->assertStringContainsString('WSKU-9', $html, 'the read-only link table lists the match');
    }

    public function test_the_product_form_card_is_read_only_without_the_third_party_permission(): void
    {
        $this->makeProduct(9);
        $this->actingAs(Admin::findOrFail(2), 'admin');

        $html = view('admin-views.partials._fulfillment-provider', ['product' => $this->productRow(9)])->render();

        $this->assertSame(3, preg_match_all('/name="fulfillment_provider"[^>]*disabled/', $html));
        $this->assertStringContainsString('Only administrators with third-party setup access can change the provider.', $html);
    }

    public function test_the_product_form_card_is_hidden_for_vendor_products_and_gallery_clones(): void
    {
        $this->makeProduct(9, null, ['added_by' => 'seller']);

        $vendor = view('admin-views.partials._fulfillment-provider', ['product' => $this->productRow(9, 'seller')])->render();
        $this->assertSame('', trim($vendor));

        request()->merge(['product-gallery' => 1]);
        $clone = view('admin-views.partials._fulfillment-provider', ['product' => $this->productRow(9)])->render();
        $this->assertSame('', trim($clone));
    }

    public function test_the_create_form_card_shows_the_providers_as_not_yet_available(): void
    {
        $html = view('admin-views.partials._fulfillment-provider')->render();

        $this->assertStringContainsString('Save the product first, then match it to a provider product to assign it.', $html);
        $this->assertSame(2, preg_match_all('/name="fulfillment_provider"[^>]*disabled/', $html));
    }
}
