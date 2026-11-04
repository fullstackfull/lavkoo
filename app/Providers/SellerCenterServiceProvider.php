<?php

namespace App\Providers;

use App\Http\View\Composers\SellerCenterComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Seller Center's shell data to its layout.
 *
 * One composer on the layout rather than a `with()` in every controller: a screen that forgot the
 * navigation would render a shell with no way out of it, and there will eventually be sixty-six
 * screens.
 */
class SellerCenterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('layouts.seller.app', SellerCenterComposer::class);
    }
}
