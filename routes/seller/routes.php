<?php

use App\Http\Controllers\Seller\AuditController;
use App\Http\Controllers\Seller\AutomationController;
use App\Http\Controllers\Seller\AutomationHistoryController;
use App\Http\Controllers\Seller\ControlTowerController;
use App\Http\Controllers\Seller\FoundationController;
use App\Http\Controllers\Seller\HomeController;
use App\Http\Controllers\Seller\InventoryController;
use App\Http\Controllers\Seller\IssueController;
use App\Http\Controllers\Seller\OpportunityController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\HelpController;
use App\Http\Controllers\Seller\PreferencesController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller Center
|--------------------------------------------------------------------------
|
| The seller's operations surface (design handoff 01), mounted on `/vendor` — the panel sellers
| already know. One panel, and the new screens are added to it rather than swapped in over the old
| ones: every page that exists today is still at the address it is at today, working as it works
| today.
|
| Two things are true of every route below and are enforced here rather than remembered per
| controller: the request carries a `SellerPrincipal` resolved from the session, and any route
| touching a permissioned domain declares that permission with `seller_can:` — the same middleware
| the seller app's API uses, reading the same keys. A screen's menu item disappearing is a courtesy;
| this is the enforcement.
|
| Nothing here replaces or removes a legacy page. The navigation registry links to both — the new
| screens by route, the existing ones by their own URL — so a seller moves between them inside one
| panel and nothing they use today stops working (PART 15: everything PRESERVED).
*/

Route::group(['middleware' => ['maintenance_mode', 'actch:admin_panel']], function () {
    Route::group([
        // `/vendor`, not a second panel beside it. Route *names* stay `seller.` so the Seller
        // Center's own links are one namespace, and so they cannot collide with the several
        // hundred `vendor.` names the classic panel already owns.
        'prefix' => 'vendor',
        'as' => 'seller.',
        'middleware' => ['seller', 'seller_staff_access', 'seller_center'],
    ], function () {

        // ── shell preferences ────────────────────────────────────────────
        Route::controller(PreferencesController::class)->prefix('preferences')->as('preferences.')->group(function () {
            Route::get('density', 'density')->name('density');
            Route::get('direction', 'direction')->name('direction');
        });

        // ── wave 2 · core seller operations ──────────────────────────────
        // Beside the classic dashboard, not over it. Both are reachable from the same navigation.
        Route::get('overview', HomeController::class)->name('home');
        Route::get('control-tower', ControlTowerController::class)->name('control-tower');

        Route::controller(IssueController::class)->group(function () {
            Route::get('issues', 'index')->name('issues.index');
            Route::get('issues/{issue}', 'show')->name('issues.show')->whereNumber('issue');
        });

        Route::controller(OrderController::class)->middleware('seller_can:orders.view,orders.manage')->group(function () {
            Route::get('orders', 'index')->name('orders.index');
            Route::get('orders/{order}', 'show')->name('orders.show')->whereNumber('order');
        });

        Route::controller(ProductController::class)->middleware('seller_can:products.view,products.manage')->group(function () {
            Route::get('products', 'index')->name('products.index');
        });

        Route::controller(InventoryController::class)->middleware('seller_can:products.view,products.manage')->group(function () {
            Route::get('inventory', 'index')->name('inventory.index');
            Route::get('inventory/movements', 'movements')->name('inventory.movements');
        });

        // ── wave 3 · automation ──────────────────────────────────────────
        // Reading a rule and the record of what it did is catalogue history; writing one changes
        // the catalogue. The two are gated separately, exactly as the seller app's API gates them.
        Route::controller(AutomationController::class)->prefix('automation')->as('automation.')->group(function () {
            Route::middleware('seller_can:products.view,products.manage')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('{rule}/preview', 'preview')->name('preview')->whereNumber('rule');
            });

            Route::middleware('seller_can:products.manage')->group(function () {
                Route::get('new', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('{rule}/edit', 'edit')->name('edit')->whereNumber('rule');
                Route::put('{rule}', 'update')->name('update')->whereNumber('rule');
                Route::put('{rule}/status', 'setStatus')->name('status')->whereNumber('rule');
                Route::post('{rule}/run', 'runNow')->name('run')->whereNumber('rule');
                Route::delete('{rule}', 'destroy')->name('destroy')->whereNumber('rule');
            });
        });

        Route::controller(AutomationHistoryController::class)->group(function () {
            Route::get('automation/history', 'index')->name('automation.history')
                ->middleware('seller_can:products.view,products.manage');
            Route::post('automation/history/{action}/revert', 'revert')->name('automation.revert')
                ->whereNumber('action')->middleware('seller_can:products.manage');
        });

        Route::get('opportunities', OpportunityController::class)->name('opportunities.index')
            ->middleware('seller_can:products.view,products.manage');

        // The shop's own history. The navigation has reserved this name since Wave 1 and the route
        // did not exist, so the menu item was silently dropped and a seller could read their trail
        // only from the phone app — which drops the before/after values.
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index')
            ->middleware('seller_can:staff.manage');

        Route::get('search', SearchController::class)->name('search');
        Route::get('help', [HelpController::class, 'index'])->name('help');

        // Wave 1's acceptance screen. Debug-only, and deliberately not in the navigation.
        Route::get('foundation', FoundationController::class)->name('foundation');
    });
});
