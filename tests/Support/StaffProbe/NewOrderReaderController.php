<?php

namespace Tests\Support\StaffProbe;

use App\Models\Order;

/** A report added later that sums orders: the order guard must see it. */
class NewOrderReaderController
{
    public function index(): float
    {
        return (float) Order::query()->sum('order_amount');
    }
}
