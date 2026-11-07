<?php

use App\Http\Controllers\Admin\Fulfillment\IntegrationCenterController;
use App\Http\Controllers\Admin\Fulfillment\LiveVerificationController;
use App\Http\Controllers\Admin\Fulfillment\M5aznProductsController;
use App\Http\Controllers\Admin\Fulfillment\ProviderFulfillmentController;
use App\Http\Controllers\Admin\Fulfillment\TorodMatchingController;
use App\Http\Middleware\EnsureFulfillmentMigrated;
use Illuminate\Support\Facades\Route;

/*
| Fulfillment providers (M5AZN, Torod Point) — Integration Center and its pages.
|
| Same guard as the other third-party settings: an authenticated admin with the `3rd_party_setup` module. Until the two
| migrations have run, every page explains that instead of failing on a missing table.
| Credentials are write-only on these screens; no response ever carries a stored secret. Every POST that calls a
| provider is rate limited, so a click-happy session, a script or a forged form cannot hammer a provider's API
| (and lock the account out) from here.
*/
Route::group([
    'prefix' => 'admin/fulfillment-providers',
    'as' => 'admin.fulfillment-providers.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:3rd_party_setup', EnsureFulfillmentMigrated::class],
], function () {
    Route::get('/', [IntegrationCenterController::class, 'index'])->name('index');

    Route::get('fulfillments', [ProviderFulfillmentController::class, 'index'])->name('fulfillments.index');
    Route::get('fulfillments/{id}', [ProviderFulfillmentController::class, 'show'])->whereNumber('id')->name('fulfillments.show');
    Route::post('fulfillments/{id}/{action}', [ProviderFulfillmentController::class, 'action'])->whereNumber('id')->whereIn('action', ProviderFulfillmentController::ACTIONS)->middleware('throttle:30,1')->name('fulfillments.action');
    // A POST: it calls the provider with our credentials, which a link on another site must not be able to trigger.
    Route::post('fulfillments/{id}/label', [ProviderFulfillmentController::class, 'label'])->whereNumber('id')->middleware('throttle:10,1')->name('fulfillments.label');
    Route::get('logs', [ProviderFulfillmentController::class, 'logs'])->name('logs');

    Route::get('m5azn/products', [M5aznProductsController::class, 'index'])->name('m5azn.products');
    Route::post('m5azn/products/import-all', [M5aznProductsController::class, 'importAll'])->middleware('throttle:6,1')->name('m5azn.import-all');
    Route::post('m5azn/products/{item}/import', [M5aznProductsController::class, 'import'])->whereNumber('item')->name('m5azn.import');

    Route::get('torod-point/matching', [TorodMatchingController::class, 'index'])->name('torod.matching');
    Route::post('torod-point/matching/approve', [TorodMatchingController::class, 'approve'])->name('torod.approve');
    Route::post('torod-point/matching/{mapping}/retire', [TorodMatchingController::class, 'retire'])->whereNumber('mapping')->name('torod.retire');
    Route::post('torod-point/matching/stock', [TorodMatchingController::class, 'stock'])->name('torod.stock');
    Route::post('torod-point/matching/push', [TorodMatchingController::class, 'push'])->middleware('throttle:6,1')->name('torod.push');
    Route::post('torod-point/cities/alias', [TorodMatchingController::class, 'alias'])->name('torod.alias');
    Route::get('torod-point/cities/search', [TorodMatchingController::class, 'citySearch'])->name('torod.cities.search');
    Route::get('torod-point/cities/internal-search', [TorodMatchingController::class, 'internalCitySearch'])->name('torod.cities.internal-search');
    Route::post('torod-point/cities/mappings/approve', [TorodMatchingController::class, 'approveCity'])->name('torod.cities.approve');
    Route::post('torod-point/cities/mappings/reject', [TorodMatchingController::class, 'rejectCity'])->name('torod.cities.reject');
    Route::post('torod-point/cities/mappings/{mapping}/retire', [TorodMatchingController::class, 'retireCity'])->whereNumber('mapping')->name('torod.cities.retire');
    // Asks Torod Point which of its cities are named like one of ours: a read with our credentials, so a few a minute is plenty.
    Route::post('torod-point/cities/discover', [TorodMatchingController::class, 'discoverCity'])->middleware('throttle:6,1')->name('torod.cities.discover');
    Route::get('torod-point/products/search', [TorodMatchingController::class, 'productSearch'])->name('torod.products.search');
    // Recording (or withdrawing) the proof that the invoice number we send comes back in the order list; it calls no provider.
    Route::post('torod-point/invoice-round-trip', [IntegrationCenterController::class, 'confirmInvoiceRoundTrip'])->name('torod.invoice-round-trip.confirm');
    Route::post('torod-point/invoice-round-trip/withdraw', [IntegrationCenterController::class, 'withdrawInvoiceRoundTrip'])->name('torod.invoice-round-trip.withdraw');

    Route::get('{provider}', [IntegrationCenterController::class, 'show'])->whereIn('provider', ['m5azn', 'torod-point'])->name('show');
    Route::post('{provider}', [IntegrationCenterController::class, 'update'])->whereIn('provider', ['m5azn', 'torod-point'])->name('update');
    Route::post('{provider}/test', [IntegrationCenterController::class, 'test'])->whereIn('provider', ['m5azn', 'torod-point'])->middleware('throttle:10,1')->name('test');
    Route::post('{provider}/webhook-secret', [IntegrationCenterController::class, 'rotateSecret'])->whereIn('provider', ['m5azn', 'torod-point'])->name('rotate-secret');
    Route::post('{provider}/sync/{task}', [IntegrationCenterController::class, 'sync'])->whereIn('provider', ['m5azn', 'torod-point'])->middleware('throttle:6,1')->name('sync');
    // Read-only questions to the provider (products, order list, stock): each run asks about ten things, so a few runs a minute is plenty.
    Route::post('{provider}/verification', [LiveVerificationController::class, 'run'])->whereIn('provider', ['m5azn', 'torod-point'])->middleware('throttle:3,1')->name('verification.run');
    Route::get('{provider}/verification/{run}', [LiveVerificationController::class, 'download'])->whereIn('provider', ['m5azn', 'torod-point'])->where('run', '[A-Za-z0-9_]+')->name('verification.download');
    Route::post('{provider}/field-map/{capability}/sample', [IntegrationCenterController::class, 'fieldMapSample'])->whereIn('provider', ['m5azn', 'torod-point'])->middleware('throttle:10,1')->name('field-map.sample');
    Route::post('{provider}/field-map/{capability}', [IntegrationCenterController::class, 'fieldMapSave'])->whereIn('provider', ['m5azn', 'torod-point'])->name('field-map.save');
});
