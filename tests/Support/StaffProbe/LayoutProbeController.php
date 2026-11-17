<?php

namespace Tests\Support\StaffProbe;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Contracts\View\View;

/** An admin screen that no staff scope covers, as most of the panel is: it reads orders and products in its own content, then renders the shared layout. */
class LayoutProbeController
{
    public function show(): View
    {
        return view('staff-layout-probe', ['ordersTheScreenRead' => Order::query()->count(), 'productsTheScreenRead' => Product::query()->count()]);
    }
}
