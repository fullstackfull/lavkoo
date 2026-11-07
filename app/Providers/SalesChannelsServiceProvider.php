<?php

namespace App\Providers;

use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use App\Services\SalesChannels\Accounting\AccountingDestination;
use App\Services\SalesChannels\Accounting\NullAccountingDestination;
use App\Services\SalesChannels\Inventory\ChannelHoldsOnProviderStock;
use App\Services\SalesChannels\Shipments\ExternalShipmentLookup;
use App\Services\SalesChannels\Shipments\TorodShipmentLookup;
use App\Services\SalesChannels\Shipments\NullShipmentLookup;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the multi-channel hub (Amazon, Trendyol) and the operational-cost module.
 *
 * Nothing here switches an integration on: outbound traffic needs SALES_CHANNELS_ENABLED, an enabled account and a capability, and writes need
 * SALES_CHANNELS_EXTERNAL_WRITES_ENABLED plus a recorded approval. Routes live in this provider rather than in RouteServiceProvider so that the shared
 * routing file stays untouched.
 */
class SalesChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The honest default finds nothing and guesses nothing; the Torod Point lookup replaces it only when the owner has switched discovery on (see config/sales_channels.php).
        $this->app->bind(ExternalShipmentLookup::class, static fn ($app): ExternalShipmentLookup => config('sales_channels.shipment_discovery.enabled', false) ? $app->make(TorodShipmentLookup::class) : new NullShipmentLookup());
        // One shelf, two sellers: the provider sync asks the channels what their open orders hold before it writes the storefront's stock.
        $this->app->bind(ReportsChannelHolds::class, ChannelHoldsOnProviderStock::class);
        // Odoo is deferred: the books have an extension point (AccountingDestination) and, until one is built, a destination that posts nothing.
        $this->app->bind(AccountingDestination::class, NullAccountingDestination::class);
    }

    public function boot(): void
    {
        RateLimiter::for('sales-channel-webhooks', static fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));

        // Inbound webhooks: under the `api` prefix but outside the `api` middleware group, which records a telemetry row per request.
        Route::prefix('api')->group(base_path('routes/rest_api/sales_channel_webhooks.php'));

        Route::middleware('web')->group(base_path('routes/admin/sales-channels.php'));
        Route::middleware('web')->group(base_path('routes/admin/operational-costs.php'));
    }
}
