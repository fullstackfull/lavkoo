<?php

use Illuminate\Support\Facades\Route;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\AdminTaxReportController;
use Modules\TaxModule\app\Http\Controllers\SystemTaxVatSetupController;
use Modules\TaxModule\app\Http\Controllers\TaxVatController;
use Modules\TaxModule\app\Http\Controllers\Vendor\Reports\TaxReportController;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
|
*/

Route::group(['prefix' => 'vendor', 'as' => 'vendor.', 'middleware' => ['seller']], function () {
    Route::group(['prefix' => 'report', 'as' => 'report.'], function () {
        Route::controller(TaxReportController::class)->group(function () {
            Route::get('get-vat-report', 'vendorTaxReportList')->name('get-vat-report');
            Route::get('get-vat-report-export', 'vendorTaxExport')->name('get-vat-report-export');
        });
    });
});
