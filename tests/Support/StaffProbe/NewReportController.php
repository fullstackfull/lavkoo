<?php

namespace Tests\Support\StaffProbe;

use App\Models\Product;

/** A report added later that counts products: the inventory guard must see it. */
class NewReportController
{
    public function index(): int
    {
        return Product::query()->count();
    }
}
