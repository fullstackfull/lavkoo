<?php

use App\Http\Controllers\Admin\OperationalCosts\ContractController;
use App\Http\Controllers\Admin\OperationalCosts\ContractRuleController;
use App\Http\Controllers\Admin\OperationalCosts\CostEntryController;
use App\Http\Controllers\Admin\OperationalCosts\InvoiceController;
use App\Http\Controllers\Admin\OperationalCosts\OverviewController;
use App\Http\Controllers\Admin\OperationalCosts\ProductCostController;
use App\Http\Controllers\Admin\OperationalCosts\ProfitabilityController;
use App\Http\Controllers\Admin\OperationalCosts\ReportController;
use App\Http\Middleware\EnsureSalesChannelsMigrated;
use Illuminate\Support\Facades\Route;

/*
| Operational costs: contracts, product costs, shipment/storage/missing costs, invoices and reconciliation, profitability, reports.
|
| An authenticated admin holding the `operational_costs` module. Estimated and actual figures are always shown apart, a missing cost is shown as missing (never as zero),
| and an approved or reconciled entry is never rewritten from these screens.
|
| Everything that changes the ledger is a POST that asks for a reason where it undoes something. Whatever reads a long stretch of history (the exports, a reconciliation, an
| invoice approval that writes many entries) is rate limited.
*/
Route::group([
    'prefix' => 'admin/operational-costs',
    'as' => 'admin.operational-costs.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:operational_costs', EnsureSalesChannelsMigrated::class],
], function () {
    Route::get('/', [OverviewController::class, 'index'])->name('index');

    Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
    Route::get('contracts/create', [ContractController::class, 'create'])->name('contracts.create');
    Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
    Route::get('contracts/{contract}', [ContractController::class, 'show'])->whereNumber('contract')->name('contracts.show');
    Route::get('contracts/{contract}/edit', [ContractController::class, 'edit'])->whereNumber('contract')->name('contracts.edit');
    Route::post('contracts/{contract}', [ContractController::class, 'update'])->whereNumber('contract')->name('contracts.update');
    Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->whereNumber('contract')->middleware('throttle:20,1')->name('contracts.activate');
    Route::post('contracts/{contract}/new-version', [ContractController::class, 'newVersion'])->whereNumber('contract')->middleware('throttle:20,1')->name('contracts.new-version');
    Route::post('contracts/{contract}/archive', [ContractController::class, 'archive'])->whereNumber('contract')->name('contracts.archive');
    Route::get('contracts/{contract}/rules/create', [ContractRuleController::class, 'create'])->whereNumber('contract')->name('contracts.rules.create');
    Route::post('contracts/{contract}/rules', [ContractRuleController::class, 'store'])->whereNumber('contract')->name('contracts.rules.store');
    Route::get('contracts/{contract}/rules/{rule}/edit', [ContractRuleController::class, 'edit'])->whereNumber(['contract', 'rule'])->name('contracts.rules.edit');
    Route::post('contracts/{contract}/rules/{rule}', [ContractRuleController::class, 'update'])->whereNumber(['contract', 'rule'])->name('contracts.rules.update');
    Route::post('contracts/{contract}/rules/{rule}/delete', [ContractRuleController::class, 'destroy'])->whereNumber(['contract', 'rule'])->name('contracts.rules.destroy');

    Route::get('product-costs', [ProductCostController::class, 'index'])->name('product-costs.index');
    // A POST: it records goods in stock at a cost. The reference makes a double submit record once.
    Route::post('product-costs', [ProductCostController::class, 'store'])->middleware('throttle:30,1')->name('product-costs.store');
    Route::get('product-costs/products/search', [ProductCostController::class, 'productSearch'])->middleware('throttle:120,1')->name('product-costs.product-search');
    Route::get('product-costs/{layer}', [ProductCostController::class, 'show'])->whereNumber('layer')->name('product-costs.show');

    Route::get('entries', [CostEntryController::class, 'index'])->name('entries.index');
    Route::get('entries/{entry}', [CostEntryController::class, 'show'])->whereNumber('entry')->name('entries.show');
    Route::post('entries/{entry}/approve', [CostEntryController::class, 'approve'])->whereNumber('entry')->middleware('throttle:60,1')->name('entries.approve');
    Route::post('entries/{entry}/dispute', [CostEntryController::class, 'dispute'])->whereNumber('entry')->middleware('throttle:60,1')->name('entries.dispute');
    Route::post('entries/{entry}/reverse', [CostEntryController::class, 'reverse'])->whereNumber('entry')->middleware('throttle:60,1')->name('entries.reverse');
    Route::post('entries/{entry}/resolve', [CostEntryController::class, 'resolve'])->whereNumber('entry')->middleware('throttle:60,1')->name('entries.resolve');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware('throttle:20,1')->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    // Approving writes one cost per line and reconciling compares every line with its estimate: a few runs a minute is plenty.
    Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->whereNumber('invoice')->middleware('throttle:10,1')->name('invoices.approve');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->whereNumber('invoice')->name('invoices.void');
    Route::post('invoices/{invoice}/not-duplicate', [InvoiceController::class, 'notDuplicate'])->whereNumber('invoice')->name('invoices.not-duplicate');
    Route::post('invoices/{invoice}/reconcile', [InvoiceController::class, 'reconcile'])->whereNumber('invoice')->middleware('throttle:10,1')->name('invoices.reconcile');
    Route::post('invoices/{invoice}/reconciliations/{reconciliation}/resolve', [InvoiceController::class, 'resolve'])->whereNumber(['invoice', 'reconciliation'])->middleware('throttle:60,1')->name('invoices.resolve');

    Route::get('profit', [ProfitabilityController::class, 'index'])->name('profit.index');
    Route::get('profit/export', [ProfitabilityController::class, 'export'])->middleware('throttle:6,1')->name('profit.export');
    Route::get('profit/orders/{type}/{id}', [ProfitabilityController::class, 'order'])->whereIn('type', ProfitabilityController::DOCUMENT_TYPES)->whereNumber('id')->name('profit.order');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'export'])->whereIn('report', ReportController::REPORTS)->middleware('throttle:6,1')->name('reports.export');
});
