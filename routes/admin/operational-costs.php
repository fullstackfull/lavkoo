<?php

use App\Http\Middleware\EnsureSalesChannelsMigrated;
use Illuminate\Support\Facades\Route;

/*
| Operational costs: contracts, product costs, shipment/storage/missing costs, invoices and reconciliation, profitability, reports.
|
| An authenticated admin holding the `operational_costs` module. Estimated and actual figures are always shown apart, a missing cost is shown as missing (never as zero),
| and an approved or reconciled entry is never rewritten from these screens.
*/
Route::group([
    'prefix' => 'admin/operational-costs',
    'as' => 'admin.operational-costs.',
    'middleware' => ['admin', 'actch:admin_panel', 'module:operational_costs', EnsureSalesChannelsMigrated::class],
], function () {
});
