<?php

use App\Http\Controllers\Admin\Storefront\StorefrontLegalTextController;
use App\Http\Controllers\Admin\Storefront\StorefrontLifecycleController;
use App\Http\Middleware\EnsureStorefrontPermission;
use App\Services\Storefront\StorefrontPermissionService;
use Illuminate\Support\Facades\Route;

/*
| The storefronts of the platform in the admin panel (I2-03, I2-11): where a storefront is marked ready, activated, paused and resumed, and where its legal texts are written,
| approved, published and revoked.
|
| The list of storefronts is open to an admin whose role holds any one of the three storefront permissions (StorefrontPermissionService), the legal texts of a storefront to one who
| writes or approves them, and every step needs its own: the lifecycle, writing a text, and approving or publishing it. The master admin holds all of them and no other role holds any until it is granted in the role form. The screens work while
| GCC_STOREFRONTS_ENABLED is off, as the console commands do, because a storefront is prepared before it is switched on. Every POST has a throttle bucket of its own.
*/
Route::group([
    'prefix' => 'admin/storefronts',
    'as' => 'admin.storefronts.',
    'middleware' => ['admin', 'actch:admin_panel'],
], function () {
    Route::get('/', [StorefrontLifecycleController::class, 'index'])->middleware(EnsureStorefrontPermission::for(...array_keys(StorefrontPermissionService::all())))->name('index');

    Route::get('{country}/legal-texts', [StorefrontLegalTextController::class, 'index'])
        ->where('country', '[A-Za-z]{2}')
        ->middleware(EnsureStorefrontPermission::for(StorefrontPermissionService::LEGAL_WRITE, StorefrontPermissionService::LEGAL_APPROVE))
        ->name('legal-texts.index');

    Route::middleware([EnsureStorefrontPermission::for(StorefrontPermissionService::LIFECYCLE), 'throttle:30,1,storefront-lifecycle'])->where(['country' => '[A-Za-z]{2}'])->group(function () {
        Route::post('{country}/ready', [StorefrontLifecycleController::class, 'markReady'])->name('ready');
        Route::post('{country}/activate', [StorefrontLifecycleController::class, 'activate'])->name('activate');
        Route::post('{country}/pause', [StorefrontLifecycleController::class, 'pause'])->name('pause');
        Route::post('{country}/resume', [StorefrontLifecycleController::class, 'resume'])->name('resume');
    });

    Route::middleware([EnsureStorefrontPermission::for(StorefrontPermissionService::LEGAL_WRITE), 'throttle:60,1,storefront-legal-write'])->group(function () {
        Route::post('{country}/legal-texts', [StorefrontLegalTextController::class, 'draft'])->where('country', '[A-Za-z]{2}')->name('legal-texts.draft');
        Route::post('legal-texts/{text}/new-version', [StorefrontLegalTextController::class, 'newVersion'])->whereNumber('text')->name('legal-texts.new-version');
        Route::post('legal-texts/{text}/edit', [StorefrontLegalTextController::class, 'edit'])->whereNumber('text')->name('legal-texts.edit');
        Route::post('legal-texts/{text}/discard', [StorefrontLegalTextController::class, 'discard'])->whereNumber('text')->name('legal-texts.discard');
    });

    Route::middleware([EnsureStorefrontPermission::for(StorefrontPermissionService::LEGAL_APPROVE), 'throttle:30,1,storefront-legal-approve'])->whereNumber('text')->group(function () {
        Route::post('legal-texts/{text}/approve', [StorefrontLegalTextController::class, 'approve'])->name('legal-texts.approve');
        Route::post('legal-texts/{text}/publish', [StorefrontLegalTextController::class, 'publish'])->name('legal-texts.publish');
        Route::post('legal-texts/{text}/revoke', [StorefrontLegalTextController::class, 'revoke'])->name('legal-texts.revoke');
    });
});
