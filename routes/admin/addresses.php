<?php

use App\Http\Controllers\Admin\Address\AddressCityController;
use App\Http\Controllers\Admin\Address\AddressImportController;
use App\Http\Controllers\Admin\Address\AddressOverviewController;
use App\Http\Controllers\Admin\Address\AddressRegionController;
use App\Http\Controllers\Admin\Address\LegacyAddressController;
use App\Http\Middleware\EnsureAddressMigrated;
use Illuminate\Support\Facades\Route;

/*
| The unified Saudi address: the mode switch, the region and city lists, the review of old free-text cities and the data import.
|
| Guarded like the other store settings: an authenticated admin whose role holds the `business_settings` module (the address system is
| set up under Business setup, beside the delivery zone it has to agree with). Until the four address migrations have run, every page
| explains what is missing instead of failing on a table that is not there. Every POST is rate limited, each with a bucket of its own: a
| numeric throttle without a prefix shares one counter with every other numeric throttle for the same client.
|
| There is no delete anywhere: addresses, provider mappings and the copies orders keep point at a region or city by its id, so a place
| that should no longer be offered is switched off and stays in the list.
*/
Route::group([
    'prefix' => 'admin/address-system',
    'as' => 'admin.address-system.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:business_settings', EnsureAddressMigrated::class],
], function () {
    Route::get('/', [AddressOverviewController::class, 'index'])->name('index');
    Route::post('mode', [AddressOverviewController::class, 'updateMode'])->middleware('throttle:20,1,address-mode')->name('mode.update');

    Route::get('regions', [AddressRegionController::class, 'index'])->name('regions.index');
    Route::post('regions/{region}', [AddressRegionController::class, 'update'])->whereNumber('region')->middleware('throttle:60,1,address-region')->name('regions.update');
    Route::post('regions/{region}/status', [AddressRegionController::class, 'status'])->whereNumber('region')->middleware('throttle:60,1,address-region-status')->name('regions.status');

    Route::get('cities', [AddressCityController::class, 'index'])->name('cities.index');
    Route::get('cities/create', [AddressCityController::class, 'create'])->name('cities.create');
    Route::post('cities', [AddressCityController::class, 'store'])->middleware('throttle:60,1,address-city-store')->name('cities.store');
    Route::get('cities/{city}/edit', [AddressCityController::class, 'edit'])->whereNumber('city')->name('cities.edit');
    Route::post('cities/{city}', [AddressCityController::class, 'update'])->whereNumber('city')->middleware('throttle:60,1,address-city-update')->name('cities.update');
    Route::post('cities/{city}/status', [AddressCityController::class, 'status'])->whereNumber('city')->middleware('throttle:60,1,address-city-status')->name('cities.status');

    Route::get('legacy', [LegacyAddressController::class, 'index'])->name('legacy.index');
    Route::post('legacy/confirm', [LegacyAddressController::class, 'confirm'])->middleware('throttle:60,1,address-legacy-confirm')->name('legacy.confirm');
    Route::post('legacy/apply-matches', [LegacyAddressController::class, 'apply'])->middleware('throttle:6,1,address-legacy-apply')->name('legacy.apply');

    Route::get('import', [AddressImportController::class, 'index'])->name('import.index');
    Route::post('import/preview', [AddressImportController::class, 'preview'])->middleware('throttle:6,1,address-import-preview')->name('import.preview');
    Route::post('import/apply', [AddressImportController::class, 'apply'])->middleware('throttle:3,1,address-import-apply')->name('import.apply');
});
