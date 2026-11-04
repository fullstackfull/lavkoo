<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\app\Http\Controllers\AIController;
use Modules\AI\app\Http\Controllers\API\V3\AIAuctionProductController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group([], function () {
    Route::resource('ai', AIController::class)->names('ai');
});

Route::group(['prefix' => 'customer', 'as' => 'customer.', 'middleware' => ['customer']], function () {
    Route::group(['prefix' => 'auction/product', 'as' => 'auction.product.'], function () {
        Route::get('title-auto-fill', [AIAuctionProductController::class, 'titleAutoFill'])->name('title-auto-fill');
        Route::get('description-auto-fill', [AIAuctionProductController::class, 'descriptionAutoFill'])->name('description-auto-fill');
        Route::get('general-setup-auto-fill', [AIAuctionProductController::class, 'generalSetupAutoFill'])->name('general-setup-auto-fill');
        Route::get('shipping-policy-auto-fill', [AIAuctionProductController::class, 'shippingPolicyAutoFill'])->name('shipping-policy-auto-fill');
        Route::get('auction-info-auto-fill', [AIAuctionProductController::class, 'auctionInfoAutoFill'])->name('auction-info-auto-fill');
        Route::get('seo-section-auto-fill', [AIAuctionProductController::class, 'seoSectionAutoFill'])->name('seo-section-auto-fill');
        Route::post('analyze-image-auto-fill', [AIAuctionProductController::class, 'generateTitleFromImages'])->name('analyze-image-auto-fill');
        Route::post('generate-title-suggestions', [AIAuctionProductController::class, 'generateProductTitleSuggestion'])->name('generate-title-suggestions');
    });
});
