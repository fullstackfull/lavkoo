<?php

use Illuminate\Support\Facades\Route;
use Modules\TaxModule\app\Http\Controllers\Api\v3\VendorTaxReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(['prefix' => 'v3/seller'], function () {
    Route::group(['middleware' => ['seller_api_auth', 'api_lang']], function () {
        Route::get('get-vat-tax-report-list', [VendorTaxReportController::class, 'vendorWiseTaxes']);
    });
});
