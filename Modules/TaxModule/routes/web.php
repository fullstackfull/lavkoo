<?php

use Illuminate\Support\Facades\Route;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\AdminTaxReportController;
use Modules\TaxModule\app\Http\Controllers\SystemTaxVatSetupController;
use Modules\TaxModule\app\Http\Controllers\TaxVatController;
use Modules\TaxModule\app\Http\Controllers\Admin\Reports\VendorTaxReportController;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
|
*/

Route::group(['prefix' => 'admin', 'as' => 'admin.', 'middleware' => ['admin']], function () {
    Route::group(['prefix' => 'vat-tax', 'as' => 'vat-tax.'], function () {
        Route::controller(TaxVatController::class)->group(function () {
            Route::get('list', 'index')->name('index');
            Route::post('add-vat-tax-data', 'store')->name('store');
            Route::any('update-vat-tax-data', 'update')->name('update');
            Route::post('update-vat-tax-status', 'updateStatus')->name('status');
            Route::get('export-vat-tax', 'export')->name('export');
        });

        Route::controller(SystemTaxVatSetupController::class)->group(function () {
            Route::get('system-vat-tax', 'index')->name('systemVatTax');
            Route::post('system-vat-tax', 'systemTaxVatStore')->name('systemTaxVatStore');
            Route::post('system-vat-tax-vendor-status', 'vendorStatus')->name('systemTaxVatVendorStatus');
        });
    });

    Route::group(['prefix' => 'report', 'as' => 'report.'], function () {
        Route::controller(AdminTaxReportController::class)->group(function () {
            Route::get('get-tax-report', 'getTaxReport')->name('get-tax-report');
            Route::get('get-tax-details', 'getTaxDetails')->name('getTaxDetails');
            Route::get('tax-details-report-export', 'adminTaxDetailsExport')->name('getTaxDetailsExport');
            Route::get('admin-tax-report-export', 'adminTaxReportExport')->name('adminTaxReportExport');
        });

        Route::controller(VendorTaxReportController::class)->group(function () {
            Route::get('vendor-wise-taxes', 'vendorWiseTaxes')->name('vendor-wise-taxes');
            Route::get('vendor-wise-taxes-export', 'vendorWiseTaxExport')->name('vendorWiseTaxExport');
            Route::get('vendor-tax-report', 'vendorTax')->name('vendorTax');
            Route::get('vendor-tax-export', 'vendorTaxExport')->name('vendorTaxExport');
        });
    });
});
