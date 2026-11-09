<?php

use App\Http\Controllers\Admin\Accounting\OdooConnectionController;
use App\Http\Controllers\Admin\Accounting\OdooMappingController;
use App\Http\Controllers\Admin\Accounting\OdooOutboxController;
use App\Http\Middleware\EnsureSalesChannelsMigrated;
use Illuminate\Support\Facades\Route;

/*
| Odoo accounting: the connection, the chart-of-accounts mapping, and the posting outbox.
|
| **Odoo is not a sales channel.** It is where the cost ledger's settled figures go, so these screens sit behind the
| `operational_costs` module rather than `sales_channels`, and `odoo` is deliberately absent from the `SalesChannel` enum: it can
| never appear as a source of sales or orders.
|
| Everything that touches Odoo is a POST — a link on another site cannot trigger one — and is rate limited. The connection test
| and the mapping screens READ only; the one write in the whole integration is creating a draft journal entry, which is behind
| its own switch and a recorded approval and is reached from the outbox.
|
| The Odoo tables are their own migration and are NOT required by the sales-channel module, so each screen checks for them and
| explains rather than failing on a missing table.
*/
Route::group([
    'prefix' => 'admin/accounting/odoo',
    'as' => 'admin.accounting.odoo.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:operational_costs', EnsureSalesChannelsMigrated::class],
], function () {
    Route::get('/', [OdooConnectionController::class, 'index'])->name('index');
    Route::post('settings', [OdooConnectionController::class, 'settings'])->name('settings');
    Route::post('key', [OdooConnectionController::class, 'key'])->name('key');
    // Reads the instance: its version, its models, its journal-entry fields, its company. Rate limited because it is several
    // calls to somebody else's server.
    Route::post('test', [OdooConnectionController::class, 'test'])->middleware('throttle:10,1')->name('test');

    // ---- the chart of accounts, and what each of our facts means in it -------------------------------------------
    Route::get('mapping', [OdooMappingController::class, 'index'])->name('mapping.index');
    // Reads the chart. A POST a person presses, because it is several calls to somebody else's server.
    Route::post('mapping/read', [OdooMappingController::class, 'read'])->middleware('throttle:6,1')->name('mapping.read');
    Route::post('mapping', [OdooMappingController::class, 'store'])->name('mapping.store');
    // Saving is not approving: until a mapping is approved the builder refuses the component by name.
    Route::post('mapping/{mapping}/approve', [OdooMappingController::class, 'approve'])->whereNumber('mapping')->name('mapping.approve');
    Route::post('mapping/{mapping}/unapprove', [OdooMappingController::class, 'unapprove'])->whereNumber('mapping')->name('mapping.unapprove');
    Route::delete('mapping/{mapping}', [OdooMappingController::class, 'destroy'])->whereNumber('mapping')->name('mapping.destroy');

    // ---- the outbox, and the rows waiting for a person -----------------------------------------------------------
    Route::get('outbox', [OdooOutboxController::class, 'index'])->name('outbox.index');
    Route::post('outbox/drain', [OdooOutboxController::class, 'drain'])->middleware('throttle:4,1')->name('outbox.drain');
    // The two ways a person may end a `needs_review` row, and the only two: Odoo has it (with its id), or it is not there.
    Route::post('outbox/{row}/found', [OdooOutboxController::class, 'found'])->whereNumber('row')->name('outbox.found');
    Route::post('outbox/{row}/closed', [OdooOutboxController::class, 'closed'])->whereNumber('row')->name('outbox.closed');
});
