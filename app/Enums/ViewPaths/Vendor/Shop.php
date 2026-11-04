<?php

namespace App\Enums\ViewPaths\Vendor;

enum Shop
{
    const INDEX = [
        URI => 'index',
        VIEW => 'vendor-views.shop.index',
        ROUTE => 'vendor.shop.index',
    ];
    const UPDATE = [
        URI => 'update',
        VIEW => 'vendor-views.shop.update-view'
    ];
}
