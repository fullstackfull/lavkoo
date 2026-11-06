<?php

use App\Http\Middleware\EnsureSalesChannelsMigrated;
use Illuminate\Support\Facades\Route;

/*
| Sales channels hub: accounts (Amazon, Trendyol), products and channels, unified orders, dynamic routing, API logs.
|
| An authenticated admin holding the `sales_channels` module. Until the migrations have run every page explains that instead of failing on a missing table.
| Credentials are write-only on these screens: no response ever carries a stored secret. Anything that calls a channel is a POST, rate limited.
*/
Route::group([
    'prefix' => 'admin/sales-channels',
    'as' => 'admin.sales-channels.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:sales_channels', EnsureSalesChannelsMigrated::class],
], function () {
});
