<?php


use Illuminate\Support\Facades\Route;
use Modules\AI\app\Http\Controllers\Admin\AIProductController;
use Modules\AI\app\Http\Controllers\Admin\AISettingController;
use Modules\AI\app\Http\Controllers\Admin\Blog\AIBlogController;
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

Route::group(['prefix' => 'admin', 'as' => 'admin.', 'middleware' => ['admin', 'actch:admin_panel']], function () {
    //Product
    Route::group(['prefix' => 'product', 'as' => 'product.'], function () {
        Route::get('title-auto-fill', [AIProductController::class, 'titleAutoFill'])->name('title-auto-fill');
        Route::get('description-auto-fill', [AIProductController::class, 'descriptionAutoFill'])->name('description-auto-fill');
        Route::get('general-setup-auto-fill', [AIProductController::class, 'generalSetupAutoFill'])->name('general-setup-auto-fill');
        Route::get('price-others-auto-fill', [AIProductController::class, 'pricingAndOthersAutoFill'])->name('price-others-auto-fill');
        Route::get('seo-section-auto-fill', [AIProductController::class, 'productSeoSectionAutoFill'])->name('seo-section-auto-fill');
        Route::get('variation-setup-auto-fill', [AIProductController::class, 'productVariationSetupAutoFill'])->name('variation-setup-auto-fill');
        Route::post('analyze-image-auto-fill', [AIProductController::class, 'generateTitleFromImages'])->name('analyze-image-auto-fill');
        Route::post('generate-title-suggestions', [AIProductController::class, 'generateProductTitleSuggestion'])->name('generate-title-suggestions');
    });

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

    //Blog
 Route::group(['prefix' => 'blog', 'as' => 'blog.'], function () {
        Route::get('title-auto-fill', [AIBlogController::class, 'titleAutoFill'])->name('title-auto-fill');
        Route::get('description-auto-fill', [AIBlogController::class, 'descriptionAutoFill'])->name('description-auto-fill');
        Route::post('seo-section-auto-fill', [AIBlogController::class, 'seoSectionAutoFill'])->name('seo-section-auto-fill');
        Route::post('generate-title-suggestions', [AIBlogController::class, 'generateBlogTitleSuggestion'])->name('generate-title-suggestions');
        Route::post('analyze-image-auto-fill', [AIBlogController::class, 'generateBlogTitleFromImages'])->name('analyze-image-auto-fill');
    });

    Route::group(['prefix' => 'third-party', 'as' => 'third-party.'], function () {
        Route::group(['prefix' => 'ai-setting', 'as' => 'ai-setting.'], function () {
            Route::controller(AISettingController::class)->group(function () {
                Route::get('', 'index')->name('index');
                Route::post('store', 'store')->name('store');

                Route::get('vendors-usage-limits', 'getVendorUsagesLimitView')->name('vendors-usage-limits');
                Route::post('vendors-usage-limits/update', 'updateVendorUsagesLimit')->name('vendors-usage-limits-update');
                Route::get('customers-usage-limits', 'getCustomerUsagesLimitView')->name('customers-usage-limits');
                Route::post('customers-usage-limits/update', 'updateCustomerUsagesLimit')->name('customers-usage-limits-update');
            });
        });
    });
});
