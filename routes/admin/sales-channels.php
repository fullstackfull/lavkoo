<?php

use App\Enums\SalesChannels\IntegrationCapability;
use App\Http\Controllers\Admin\SalesChannels\ChannelAccountController;
use App\Http\Controllers\Admin\SalesChannels\ChannelAccountOperationsController;
use App\Http\Controllers\Admin\SalesChannels\ChannelHubController;
use App\Http\Controllers\Admin\SalesChannels\ChannelLogController;
use App\Http\Controllers\Admin\SalesChannels\ChannelOrderController;
use App\Http\Controllers\Admin\SalesChannels\ChannelProductController;
use App\Http\Controllers\Admin\SalesChannels\ChannelRoutingController;
use App\Http\Controllers\Admin\SalesChannels\Support\CapabilityLadder;
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
    Route::get('/', [ChannelHubController::class, 'index'])->name('index');

    // ---- accounts -------------------------------------------------------------------------------------------------
    Route::get('accounts/create', [ChannelAccountController::class, 'create'])->name('accounts.create');
    Route::post('accounts', [ChannelAccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}', [ChannelAccountController::class, 'show'])->whereNumber('account')->name('accounts.show');
    Route::post('accounts/{account}', [ChannelAccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
    Route::post('accounts/{account}/toggle/{action}', [ChannelAccountController::class, 'toggle'])->whereNumber('account')->whereIn('action', ChannelAccountController::SWITCH_ACTIONS)->name('accounts.toggle');
    Route::post('accounts/{account}/capabilities/{capability}/{action}', [ChannelAccountController::class, 'capability'])->whereNumber('account')
        ->whereIn('capability', IntegrationCapability::values())->whereIn('action', ChannelAccountController::SWITCH_ACTIONS)->name('accounts.capability');
    Route::post('accounts/{account}/approvals/{scope}/{action}', [ChannelAccountController::class, 'approval'])->whereNumber('account')
        ->whereIn('scope', CapabilityLadder::approvalScopes())->whereIn('action', ChannelAccountController::APPROVAL_ACTIONS)->name('accounts.approval');
    Route::post('accounts/{account}/writes', [ChannelAccountController::class, 'writes'])->whereNumber('account')->name('accounts.writes');
    Route::post('accounts/{account}/dry-run', [ChannelAccountController::class, 'dryRun'])->whereNumber('account')->name('accounts.dry-run');

    // Everything below calls the channel: a POST (a link on another site cannot trigger it), and rate limited.
    Route::post('accounts/{account}/test', [ChannelAccountOperationsController::class, 'test'])->whereNumber('account')->middleware('throttle:10,1')->name('accounts.test');
    Route::post('accounts/{account}/sync/{resource}', [ChannelAccountOperationsController::class, 'sync'])->whereNumber('account')
        ->whereIn('resource', ChannelAccountOperationsController::SYNC_RESOURCES)->middleware('throttle:6,1')->name('accounts.sync');
    Route::post('accounts/{account}/publish/{kind}', [ChannelAccountOperationsController::class, 'publish'])->whereNumber('account')
        ->whereIn('kind', ChannelAccountOperationsController::PUBLISH_KINDS)->middleware('throttle:6,1')->name('accounts.publish');
    Route::post('accounts/{account}/webhook', [ChannelAccountOperationsController::class, 'webhook'])->whereNumber('account')->middleware('throttle:6,1')->name('accounts.webhook');
    Route::post('accounts/{account}/webhook/list', [ChannelAccountOperationsController::class, 'listWebhooks'])->whereNumber('account')->middleware('throttle:6,1')->name('accounts.webhook.list');

    // ---- products and channels ------------------------------------------------------------------------------------
    Route::get('products', [ChannelProductController::class, 'index'])->name('products.index');
    Route::get('products/product-search', [ChannelProductController::class, 'productSearch'])->name('products.product-search');
    Route::post('products/{mapping}/link', [ChannelProductController::class, 'link'])->whereNumber('mapping')->name('products.link');
    Route::post('products/{mapping}/ignore', [ChannelProductController::class, 'ignore'])->whereNumber('mapping')->name('products.ignore');
    Route::post('products/{mapping}/unignore', [ChannelProductController::class, 'unignore'])->whereNumber('mapping')->name('products.unignore');
    Route::post('products/{mapping}/accept-suggestion', [ChannelProductController::class, 'acceptSuggestion'])->whereNumber('mapping')->name('products.accept-suggestion');
    Route::post('products/{mapping}/settings', [ChannelProductController::class, 'settings'])->whereNumber('mapping')->name('products.settings');

    // ---- unified orders -------------------------------------------------------------------------------------------
    Route::get('orders', [ChannelOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{mapping}', [ChannelOrderController::class, 'show'])->whereNumber('mapping')->name('orders.show');
    Route::post('orders/{mapping}/route', [ChannelOrderController::class, 'route'])->whereNumber('mapping')->middleware('throttle:30,1')->name('orders.route');
    Route::post('orders/{mapping}/link-shipment', [ChannelOrderController::class, 'linkShipment'])->whereNumber('mapping')->middleware('throttle:30,1')->name('orders.link-shipment');
    Route::post('orders/{mapping}/unlink-shipment', [ChannelOrderController::class, 'unlinkShipment'])->whereNumber('mapping')->middleware('throttle:30,1')->name('orders.unlink-shipment');
    // Tells the channel about the shipment: calls it.
    Route::post('orders/{mapping}/notify', [ChannelOrderController::class, 'notify'])->whereNumber('mapping')->middleware('throttle:6,1')->name('orders.notify');

    // ---- dynamic routing ------------------------------------------------------------------------------------------
    Route::get('routing', [ChannelRoutingController::class, 'index'])->name('routing.index');
    Route::post('routing/rules', [ChannelRoutingController::class, 'store'])->name('routing.rules.store');
    Route::post('routing/rules/{rule}', [ChannelRoutingController::class, 'update'])->whereNumber('rule')->name('routing.rules.update');
    Route::post('routing/rules/{rule}/toggle', [ChannelRoutingController::class, 'toggle'])->whereNumber('rule')->name('routing.rules.toggle');

    // ---- logs -----------------------------------------------------------------------------------------------------
    Route::get('logs', [ChannelLogController::class, 'index'])->name('logs.index');
});
