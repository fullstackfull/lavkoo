<?php

namespace App\Providers;

use App\View\Composers\ProductSeoSupplementComposer;
use App\View\Composers\SeoHeadComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the SEO subsystem (Phase 1.6).
 *
 * Deliberately a dedicated provider rather than an addition to AppServiceProvider::boot(), which is
 * a ~200-line hot path wrapped in a silent catch — a failure there would be swallowed and hard to
 * diagnose. Registering here keeps the SEO wiring isolated and observable.
 */
class SeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Only the opt-in SEO head partial gets the composer, so no existing view is affected.
        View::composer('seo.head', SeoHeadComposer::class);

        // Product pages: supplies the canonical, hreflang and Product schema the theme's own
        // meta partial does not emit. Scoped to this one partial for the same reason — nothing
        // that does not include it can be affected.
        View::composer('seo.product-supplement', ProductSeoSupplementComposer::class);
    }
}
