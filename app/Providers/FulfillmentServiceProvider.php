<?php

namespace App\Providers;

use App\Services\Fulfillment\Contracts\NotesStockChanged;
use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use App\Services\Fulfillment\Contracts\WritesStorefrontStock;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\Readiness\Compatibility\MigrationsSection;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\Fulfillment\Support\NoChannelHolds;
use App\Services\Fulfillment\StorefrontStockRefresher;
use App\Services\Fulfillment\Support\NoStockNotes;
use App\Services\Fulfillment\Support\StoreCurrencyRateSource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the multi-provider fulfillment integration (M5AZN, Torod Point).
 *
 * Nothing here switches the integration on: outbound traffic needs FULFILLMENT_INTEGRATIONS_ENABLED and a
 * provider's own enabled flag, and without them the feature is inert.
 */
class FulfillmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurrencyRateSource::class, StoreCurrencyRateSource::class);
        // On its own the fulfilment module knows of no other seller of a provider's stock; the sales-channel module replaces this with its own holds.
        $this->app->bind(ReportsChannelHolds::class, NoChannelHolds::class);
        $this->app->bind(NotesStockChanged::class, NoStockNotes::class);
        // The storefront's own number stays Fulfillment's to write, however asks for it.
        $this->app->bind(WritesStorefrontStock::class, StorefrontStockRefresher::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ProviderConnectionManager::class);

        // The compatibility check must list the migrations `php artisan migrate` would run, module paths included: those are
        // registered on the shared 'migrator' instance, not on a Migrator the container would build by itself.
        $this->app->when(MigrationsSection::class)->needs(Migrator::class)->give(fn () => $this->app->make('migrator'));
    }

    public function boot(): void
    {
        // Providers retry webhooks, so the ceiling is generous; it exists to bound abuse of the endpoint,
        // not to throttle legitimate traffic. Keyed by IP so one noisy caller cannot starve the rest.
        RateLimiter::for('fulfillment-webhooks', static fn (Request $request): Limit => Limit::perMinute(600)->by($request->ip()));
    }
}
