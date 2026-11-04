<?php

use App\Http\Controllers\RestAPI\v3\seller\auth\ForgotPasswordController;
use App\Http\Controllers\RestAPI\v3\seller\auth\LoginController as VendorLoginController;
use App\Http\Controllers\RestAPI\v3\seller\auth\RegisterController;
use App\Http\Controllers\RestAPI\v3\seller\BrandController;
use App\Http\Controllers\RestAPI\v3\seller\ChatController;
use App\Http\Controllers\RestAPI\v3\seller\ClearanceSaleController;
use App\Http\Controllers\RestAPI\v3\seller\CouponController;
use App\Http\Controllers\RestAPI\v3\seller\DeliveryManCashCollectController;
use App\Http\Controllers\RestAPI\v3\seller\DeliveryManController;
use App\Http\Controllers\RestAPI\v3\seller\DeliverymanWithdrawController;
use App\Http\Controllers\RestAPI\v3\seller\EmergencyContactController;
use App\Http\Controllers\RestAPI\v3\seller\OrderController;
use App\Http\Controllers\RestAPI\v3\seller\OrderEditController;
use App\Http\Controllers\RestAPI\v3\seller\POSCartController;
use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Http\Controllers\RestAPI\v3\seller\ProductController;
use App\Http\Controllers\RestAPI\v3\seller\RefundController;
use App\Http\Controllers\RestAPI\v3\seller\SellerAnalyticsController;
use App\Http\Controllers\RestAPI\v3\seller\SellerAutomationController;
use App\Http\Controllers\RestAPI\v3\seller\SellerBrandClaimController;
use App\Http\Controllers\RestAPI\v3\seller\SellerBulkJobController;
use App\Http\Controllers\RestAPI\v3\seller\SellerCenterController;
use App\Http\Controllers\RestAPI\v3\seller\SellerControlTowerController;
use App\Http\Controllers\RestAPI\v3\seller\SellerFinanceControlController;
use App\Http\Controllers\RestAPI\v3\seller\SellerIntegrationController;
use App\Http\Controllers\RestAPI\v3\seller\SellerInventoryController;
use App\Http\Controllers\RestAPI\v3\seller\SellerController;
use App\Http\Controllers\RestAPI\v3\seller\SellerPayoutController;
use App\Http\Controllers\RestAPI\v3\seller\SellerActionCenterController;
use App\Http\Controllers\RestAPI\v3\seller\SellerReportController;
use App\Http\Controllers\RestAPI\v3\seller\SellerReturnController;
use App\Http\Controllers\RestAPI\v3\seller\SellerSecurityController;
use App\Http\Controllers\RestAPI\v3\seller\SellerStatementController;
use App\Http\Controllers\RestAPI\v3\seller\SellerVerificationController;
use App\Http\Controllers\RestAPI\v3\seller\shippingController;
use App\Http\Controllers\RestAPI\v3\seller\ShippingMethodController;
use App\Http\Controllers\RestAPI\v3\seller\ShopController;
use App\Http\Controllers\RestAPI\v3\seller\VendorPaymentInfoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller Mobile APP API Routes
|--------------------------------------------------------------------------
|*/

Route::group(['namespace' => 'RestAPI\v3\seller', 'prefix' => 'v3/seller', 'middleware' => ['api_lang']], function () {
    // Rate limited: seller login / OTP / password reset are brute-force targets.
    Route::group(['prefix' => 'auth', 'namespace' => 'auth', 'middleware' => ['throttle:auth']], function () {

        Route::controller(VendorLoginController::class)->group(function () {
            Route::post('login', 'login');
        });
        Route::controller(ForgotPasswordController::class)->group(function () {
            Route::post('forgot-password', 'reset_password_request');
            Route::post('verify-otp', 'otp_verification_submit');
            Route::put('reset-password', 'reset_password_submit');
            Route::post('firebase-auth-token-store', 'firebaseAuthTokenStore');
            Route::post('firebase-auth-verify', 'firebaseAuthVerify');
            Route::post('check-vendor-exist-info', 'checkVendorExistInfo');
        });
    });

    Route::group(['prefix' => 'registration', 'namespace' => 'auth'], function () {
        Route::controller(RegisterController::class)->group(function () {
            Route::post('/', 'store');
        });
    });

    Route::group(['middleware' => ['seller_api_auth']], function () {
        Route::controller(SellerController::class)->group(function () {
            // The caller's own session and their own shop's public face: available to whoever
            // holds a credential for this shop, because withholding them would leave a staff
            // member unable to see which shop they are signed in to.
            Route::put('language-change', 'language_change');
            Route::get('seller-info', 'getSellerInfo');
            Route::put('cm-firebase-token', 'update_cm_firebase_token');
            Route::get('shop-info', 'shop_info');
            Route::get('seller-delivery-man', 'seller_delivery_man');

            // Taking or reshaping the account itself. No delegable permission covers these:
            // a role that could change the owner's password or delete the shop would be a role
            // that can take the shop, which is not something an owner should be able to hand out
            // — and certainly not something an issued key should reach.
            Route::middleware('seller_owner')->group(function () {
                // Deleting an account is not a read. GET is kept only so builds already
                // installed keep working, and can go once they are gone; new callers
                // use DELETE, which no prefetcher, retry layer or proxy will replay.
                Route::delete('account-delete', 'account_delete');
                Route::get('account-delete', 'account_delete');
                Route::put('seller-update', 'seller_info_update');
            });

            // The shop's own settings.
            Route::middleware('seller_can:shop_settings.manage')->group(function () {
                Route::put('shop-update', 'shop_info_update');
                Route::post('update-setup-guide-app', 'updateSetupGuideApp');
            });

            // Reviews.
            Route::middleware('seller_can:reviews.view')->group(function () {
                Route::get('shop-product-reviews', 'shop_product_reviews');
                Route::get('shop-product-reviews-status', 'shop_product_reviews_status');
                Route::post('shop-product-reviews-reply', 'shopProductReviewReply');
            });

            // The books.
            Route::middleware('seller_can:finance.view')->group(function () {
                Route::get('get-earning-statitics', 'getEarningStatics');
                Route::get('order-statistics', 'order_statistics');
                Route::get('monthly-earning', 'monthly_earning');
                Route::get('monthly-commission-given', 'monthly_commission_given');
                Route::get('transactions', 'transaction');
                Route::get('withdraw-method-list', 'withdraw_method_list');
            });

            // Moving the money out is a separate authority from reading it.
            Route::middleware('seller_can:payouts.request')->group(function () {
                Route::post('balance-withdraw', 'withdraw_request');
                Route::post('balance-withdraw-update', 'withdraw_request_update');
                Route::delete('close-withdraw-request', 'close_withdraw_request');
            });
        });

        // Closing the shop, even temporarily, stops it selling.
        Route::controller(ShopController::class)->group(function () {
            Route::middleware('seller_can:shop_settings.manage')->group(function () {
                Route::put('vacation-add', 'vacation_add');
                Route::put('temporary-close', 'temporary_close');
            });
        });

        // Reference lists a product form needs.
        Route::group(['prefix' => 'brands'], function () {
            Route::controller(BrandController::class)->group(function () {
                Route::middleware('seller_can:products.view,products.manage')->group(function () {
                    Route::get('/', 'getBrands');
                });
            });
        });

        Route::controller(ProductController::class)->group(function () {
            Route::middleware('seller_can:products.view,products.manage,orders.view,orders.manage')->group(function () {
                Route::get('top-delivery-man', 'top_delivery_man');
                Route::get('categories', 'get_categories');
            });

            // The catalogue. Reading it and changing it are different authorities, and both are
            // declared: a staff member whose role grants neither reaches nothing here, and an
            // issued key reaches exactly what its scopes name.
            Route::group(['prefix' => 'products'], function () {
                Route::middleware('seller_can:products.view,products.manage')->group(function () {
                    Route::get('list', 'getProductList');
                    Route::get('details/{id}', 'details');
                    Route::get('stock-out-list', 'stock_out_list');
                    Route::get('edit/{id}', 'edit');
                    Route::get('review-list/{id}', 'review_list');
                    Route::get('barcode/generate', 'barcode_generate');
                    Route::get('top-selling-product', 'top_selling_products');
                    Route::get('most-popular-product', 'most_popular_products');
                    Route::get('get-product-images/{id}', 'getProductImages');
                    Route::get('stock-limit-status', 'getStockLimitStatus');
                    Route::get('digital-author-list', 'getDigitalProductsAuthorList');
                    Route::get('digital-publishing-house-list', 'getDigitalPublishingHouseList');
                    Route::post('restock-request-list', 'getRestockRequestList');
                    Route::get('restock-request-brands-list', 'getRestockRequestBrands');
                });

                Route::middleware('seller_can:products.manage')->group(function () {
                    Route::post('upload-images', 'upload_images');
                    Route::post('upload-digital-product', 'upload_digital_product');
                    Route::post('delete-digital-product', 'deleteDigitalProduct');
                    Route::post('add', 'add_new');
                    Route::put('status-update', 'status_update');
                    Route::put('update/{id}', 'updateProduct');
                    Route::delete('delete/{id}', 'delete');
                    Route::get('delete-image', 'deleteImage');
                    Route::get('delete-preview-file', 'deletePreviewFile');
                    Route::get('restock-request-delete', 'deleteRestockRequest');
                });

                // Stock is its own permission: a warehouse role counts what is on the shelf
                // without being able to rewrite the listing or its price.
                Route::middleware('seller_can:inventory.manage,products.manage')->group(function () {
                    Route::put('quantity-update', 'updateProductQuantity');
                    Route::post('restock-request-stock-update', 'updateRestockQuantity');
                });
            });
        });

        Route::group(['prefix' => 'orders'], function () {
            Route::controller(OrderController::class)->group(function () {
                Route::middleware('seller_can:orders.view,orders.manage')->group(function () {
                    Route::post('list', 'list');
                    // Ahead of the catch-all `/{id}`, which would otherwise swallow it.
                    Route::get('{id}/invoice', 'invoice')->whereNumber('id');
                    // Declared before the catch-all `{id}` below, which would otherwise swallow it.
                    Route::get('{id}/breakdown', 'breakdown')->whereNumber('id');
                    Route::get('/{id}', 'details');
                });

                Route::middleware('seller_can:orders.manage')->group(function () {
                    Route::put('order-detail-status/{id}', 'order_detail_status');
                    Route::put('assign-delivery-man', 'assign_delivery_man');
                    Route::put('order-wise-product-upload', 'digital_file_upload_after_sell');
                    Route::put('delivery-charge-date-update', 'amount_date_update');
                    Route::post('assign-third-party-delivery', 'assign_third_party_delivery');
                    Route::post('update-payment-status', 'update_payment_status');
                    Route::post('address-update', 'address_update');
                    Route::post('order-detail-info-update', 'updateOrderDetails');
                });
            });

            // Editing an order after the fact changes what the customer is charged.
            Route::controller(OrderEditController::class)->group(function () {
                Route::middleware('seller_can:orders.manage')->group(function () {
                    Route::post('edit-order-submit', 'submitEditOrder');
                    Route::post('edit-order-validation', 'checkEditOrderValidation');
                    Route::post('assign-order-in-cod', 'assignOrderInCOD');
                });
            });
        });

        Route::group(['prefix' => 'clearance-sale'], function () {
            // A clearance sale is a promotion, and moves prices.
            Route::middleware('seller_can:promotions.manage')->group(function () {
                Route::controller(ClearanceSaleController::class)->group(function () {
                    Route::get('product-list', 'list');
                    Route::post('product-add', 'addClearanceProduct');
                    Route::post('product-delete', 'deleteClearanceProduct');
                    Route::post('all-product-delete', 'deleteAllClearanceProduct');
                    Route::post('product-status-update', 'updateClearanceProductStatus');
                    Route::post('product-discount-update', 'updateClearanceProductDiscount');
                    Route::post('config-status-update', 'updateClearanceConfigStatus');
                    Route::get('config-data', 'getConfigData');
                    Route::post('config-data-update', 'updateConfigData');
                });
        
            });
        });

        Route::group(['prefix' => 'refund'], function () {
            // A refund decision moves money back out of an order.
            Route::middleware('seller_can:orders.manage')->group(function () {
                Route::controller(RefundController::class)->group(function () {
                    Route::get('list', 'list');
                    Route::get('single-item', 'getSingleItem');
                    Route::get('refund-details', 'refund_details');
                    Route::post('refund-status-update', 'refund_status_update');
                });
        
            });
        });

        Route::group(['prefix' => 'coupon'], function () {
            // Coupons cost the shop money, which is why they are not a catalogue permission.
            Route::middleware('seller_can:promotions.manage')->group(function () {
                Route::controller(CouponController::class)->group(function () {
                    Route::get('list', 'list');
                    Route::post('store', 'store');
                    Route::put('update/{id}', 'update');
                    Route::put('status-update/{id}', 'status_update');
                    Route::delete('delete/{id}', 'delete');
                    Route::post('check-coupon', 'check_coupon');
                    Route::get('customers', 'customers');
                });
        
            });
        });

        Route::group(['prefix' => 'shipping'], function () {
            // Shipping costs are shop configuration.
            Route::middleware('seller_can:shop_settings.manage')->group(function () {
                Route::controller(shippingController::class)->group(function () {
                    Route::get('get-shipping-method', 'get_shipping_type');
                    Route::get('selected-shipping-method', 'selected_shipping_type');
                    Route::get('all-category-cost', 'all_category_cost');
                    Route::post('set-category-cost', 'set_category_cost');
                });
        
            });
        });

        Route::group(['prefix' => 'shipping-method'], function () {
            // Shipping methods and rates are shop configuration.
            Route::middleware('seller_can:shop_settings.manage')->group(function () {
                Route::controller(ShippingMethodController::class)->group(function () {
                    Route::get('list', 'list');
                    Route::post('add', 'store');
                    Route::get('edit/{id}', 'edit');
                    Route::put('status', 'status_update');
                    Route::put('update/{id}', 'update');
                    Route::delete('delete/{id}', 'delete');
                });
        
            });
        });

        Route::group(['prefix' => 'messages'], function () {
            // Talking to a customer about their order is order work.
            Route::middleware('seller_can:orders.view,orders.manage')->group(function () {
                Route::controller(ChatController::class)->group(function () {
                    Route::get('list/{type}', 'list');
                    Route::get('get-message/{type}/{id}', 'get_message');
                    Route::post('send/{type}', 'send_message');
                    Route::post('seen/{type}', 'seenMessage');
                    Route::get('search/{type}', 'search');
                });
        
            });
        });

        Route::group(['prefix' => 'pos'], function () {
            // The point of sale writes real orders and takes real payment.
            Route::middleware('seller_can:orders.manage')->group(function () {
                Route::controller(POSController::class)->group(function () {
                    Route::get('get-categories', 'get_categories');
                    Route::get('customers', 'customers');
                    Route::post('customer-store', 'customer_store');
                    Route::get('products', 'get_product_by_barcode');
                    Route::get('product-list', 'product_list');
                    Route::post('place-order', 'place_order');
                    Route::get('get-invoice', 'get_invoice');
                });

                Route::controller(POSCartController::class)->group(function () {
                    Route::post('get-tax-amount', 'getTaxAmountCart');
                });
        
            });
        });

        Route::group(['prefix' => 'delivery-man'], function () {
            // Delivery staff are how orders get out of the door, so managing them is order work.
            Route::middleware('seller_can:orders.manage')->group(function () {
                Route::controller(DeliveryManController::class)->group(function () {
                    Route::get('list', 'list');
                    Route::post('store', 'store');
                    Route::put('update/{id}', 'update');
                    Route::get('details/{id}', 'details');
                    Route::post('status-update', 'status');
                    Route::get('delete/{id}', 'delete');
                    Route::get('reviews/{id}', 'reviews');
                    Route::get('order-list/{id}', 'order_list');
                    Route::get('order-status-history/{id}', 'order_status_history');
                    Route::get('earning/{id}', 'earning');
                });

                Route::controller(DeliveryManCashCollectController::class)->group(function () {
                    Route::post('cash-receive', 'cash_receive');
                    Route::get('collect-cash-list/{id}', 'list');
                });

                Route::group(['prefix' => 'withdraw'], function () {
                    Route::controller(DeliverymanWithdrawController::class)->group(function () {
                        Route::get('list', 'list');
                        Route::get('details/{id}', 'details');
                        Route::put('status-update', 'status_update');
                    });
                });

                Route::group(['prefix' => 'emergency-contact'], function () {
                    Route::controller(EmergencyContactController::class)->group(function () {
                        Route::get('list', 'list');
                        Route::post('store', 'store');
                        Route::put('update', 'update');
                        Route::put('status-update', 'status_update');
                        Route::delete('delete', 'destroy');
                    });
                });
        
            });
        });

        Route::group(['prefix' => 'notification'], function () {
            // The shop's own notifications: readable by anyone who works in it, nobody else.
            Route::middleware('seller_can:orders.view,products.view,finance.view')->group(function () {
                Route::controller(ShopController::class)->group(function () {
                    Route::get('/', 'notification_index');
                    Route::get('/view', 'seller_notification_view');
                });
        
            });
        });

        // Where the shop's money is sent. Reading the list is finance; changing it decides who
        // receives the payouts, which is the account itself rather than an operation on it.
        Route::group(['prefix' => 'payment-information', 'as' => 'payment-information.'], function () {
            Route::controller(VendorPaymentInfoController::class)->group(function () {
                Route::middleware('seller_can:finance.view')->group(function () {
                    Route::get('list', 'index');
                    Route::get('withdrawal-method-list', 'getWithdrawalMethods');
                });

                Route::middleware('seller_owner')->group(function () {
                    Route::post('add', 'add');
                    Route::post('update', 'update');
                    Route::post('default', 'updateDefault');
                    Route::post('status', 'updateStatus');
                    Route::get('delete', 'delete');
                });
            });
        });

        /*
         * Seller Center (marketplace suite) — the same services the web hub uses,
         * exposed to the mobile app. Identity always from the auth token's seller.
         */
        Route::group(['prefix' => 'seller-center'], function () {
            // Home and the scorecard: the shop's own standing, which anyone who works in it may
            // read. The narrowest permission any role can hold is enough, but a credential holding
            // none reaches nothing — which is the point of declaring it at all.
            Route::controller(SellerCenterController::class)->group(function () {
                Route::middleware('seller_can:orders.view,orders.manage,products.view,products.manage,finance.view')->group(function () {
                    Route::get('overview', 'overview');
                    Route::get('scorecard', 'scorecard');
                });
            });

            Route::group(['prefix' => 'verification'], function () {
                // Identity documents, and the act of submitting them. This is the shop's standing
                // with the marketplace rather than an operation inside it, so it belongs to the
                // account holder rather than to any delegable permission.
                Route::controller(SellerVerificationController::class)->group(function () {
                    Route::middleware('seller_owner')->group(function () {
                        Route::get('/', 'index');
                        Route::post('submit', 'submit');
                        Route::get('document/{id}', 'document')->whereNumber('id');
                    });
                });
            });

            Route::group(['prefix' => 'analytics'], function () {
                Route::middleware('seller_can:finance.view,orders.view')->group(function () {
                    Route::controller(SellerAnalyticsController::class)->group(function () {
                        Route::get('/', 'index');
                        Route::get('activities', 'activities');
                    });
                });
            });

            // What is wrong, arranged by when it needs doing. Distinct from the Action Center's
            // flat list and from Home's report on how the business is going.
            Route::group(['prefix' => 'control-tower'], function () {
                Route::controller(SellerControlTowerController::class)->group(function () {
                    Route::middleware('seller_can:orders.view')->group(function () {
                        Route::get('/', 'index');
                        Route::get('briefing', 'briefing');
                    });

                    // Parking the shop's whole operational queue is a write, and was the only one
                    // in the group with no gate on it.
                    Route::put('issues/{id}/status', 'updateStatus')
                        ->whereNumber('id')
                        ->middleware('seller_can:orders.manage');
                });
            });

            // Who may sell under a brand. Reading where the shop stands is open to anyone who may
            // see the catalogue; making a claim and attaching evidence to it is a change to how the
            // shop is allowed to trade, so it needs the permission that manages products.
            Route::group(['prefix' => 'brand-claims'], function () {
                Route::controller(SellerBrandClaimController::class)->group(function () {
                    // Which brands this shop sells under, and the evidence behind each claim, is
                    // catalogue standing rather than public information.
                    Route::middleware('seller_can:products.view,products.manage')->group(function () {
                        Route::get('exposure', 'exposure');
                        Route::get('/', 'index');
                        Route::get('{id}/documents/{documentId}', 'document')->whereNumber('id')->whereNumber('documentId');
                    });

                    Route::middleware('seller_can:products.manage')->group(function () {
                        Route::post('/', 'store');
                        Route::post('{id}/documents', 'attach')->whereNumber('id');
                        Route::delete('{id}/documents/{documentId}', 'detach')->whereNumber('id')->whereNumber('documentId');
                        Route::post('{id}/submit', 'submit')->whereNumber('id');
                        Route::post('{id}/withdraw', 'withdraw')->whereNumber('id');
                    });
                });
            });

            // The marketplace's own arithmetic, from the seller's side: does what I sold add up to
            // what I was paid, what would this sale cost me, and who changed this price. The first
            // two are money and gated on finance.view; the price history is catalogue history and
            // readable by anyone who may see the products it is about.
            Route::group(['prefix' => 'finance'], function () {
                Route::controller(SellerFinanceControlController::class)->group(function () {
                    Route::middleware('seller_can:finance.view')->group(function () {
                        Route::get('reconciliation', 'reconciliation');
                        Route::get('fee-simulator', 'feeSimulator');
                    });

                    Route::middleware('seller_can:products.view,products.manage')->group(function () {
                        Route::get('price-changes', 'priceChanges');

                    // The floor under the shop's own prices. Readable by anyone who may see the
                    // products it governs; setting it changes what the catalogue may be sold for,
                    // so it needs the permission that manages products.
                        Route::get('pricing-policy', 'pricingPolicy');
                    });

                    Route::put('pricing-policy', 'savePricingPolicy')->middleware('seller_can:products.manage');
                });
            });

            // Keys and webhooks. Writing is gated on shop_settings.manage and, inside the
            // controller, on the caller being a person rather than a key: a leaked key must not be
            // able to mint another one or delete the endpoint that would have raised the alarm.
            Route::group(['prefix' => 'integrations'], function () {
                Route::controller(SellerIntegrationController::class)->group(function () {
                    // The key list enumerates the shop's other credentials — prefix, scopes and
                    // where each was last used — so it is gated with the writes rather than beside
                    // them.
                    Route::middleware('seller_can:shop_settings.manage')->group(function () {
                        Route::get('keys', 'keys');
                        Route::get('events', 'events');
                        Route::get('webhooks', 'webhooks');
                        Route::get('deliveries', 'deliveries');

                        Route::post('keys', 'storeKey');
                        Route::delete('keys/{id}', 'revokeKey')->whereNumber('id');
                        Route::post('webhooks', 'storeWebhook');
                        Route::put('webhooks/{id}', 'updateWebhook')->whereNumber('id');
                        Route::put('webhooks/{id}/status', 'setWebhookStatus')->whereNumber('id');
                        Route::delete('webhooks/{id}', 'destroyWebhook')->whereNumber('id');
                        Route::post('webhooks/{id}/test', 'testWebhook')->whereNumber('id');
                    });
                });
            });

            // Who works in the shop, what they may do, and what has been done. Reading the team
            // is open to anyone who may see the shop; changing it needs staff.manage, which is the
            // permission that can grant every other permission and so is the one that matters most.
            Route::group(['prefix' => 'security'], function () {
                Route::controller(SellerSecurityController::class)->group(function () {
                    // Reading is gated as deliberately as writing. The audit trail carries the
                    // before and after of a bank-details change, and the team list carries every
                    // employee's email and last login — a credential that may not manage the team
                    // has no business reading either.
                    Route::middleware('seller_can:staff.manage')->group(function () {
                        Route::get('permissions', 'permissions');
                        Route::get('roles', 'roles');
                        Route::get('staff', 'staff');
                        Route::get('access', 'access');
                        Route::get('audit', 'audit');

                        Route::post('roles', 'storeRole');
                        Route::put('roles/{id}', 'updateRole')->whereNumber('id');
                        Route::delete('roles/{id}', 'destroyRole')->whereNumber('id');
                        Route::post('staff', 'storeStaff');
                        Route::put('staff/{id}', 'updateStaff')->whereNumber('id');
                        Route::post('staff/{id}/sign-out', 'signOutStaff')->whereNumber('id');
                        Route::delete('staff/{id}', 'destroyStaff')->whereNumber('id');
                    });
                });
            });

            // Rules the seller writes for their own shop, and the complete record of what those
            // rules did to it. Every route declares the permission it needs — reading a rule and
            // the trail of what it did is catalogue history, writing one changes the catalogue —
            // and the action's own permission is checked again inside the service.
            Route::group(['prefix' => 'automation'], function () {
                Route::controller(SellerAutomationController::class)->group(function () {
                    Route::middleware('seller_can:products.view,products.manage')->group(function () {
                        Route::get('catalogue', 'catalogue');
                        Route::get('activity', 'activity');
                        Route::get('rules', 'index');
                        Route::get('rules/{id}', 'show')->whereNumber('id');
                        Route::get('rules/{id}/preview', 'preview')->whereNumber('id');
                    });

                    Route::middleware('seller_can:products.manage')->group(function () {
                        Route::post('rules', 'store');
                        Route::put('rules/{id}', 'update')->whereNumber('id');
                        Route::put('rules/{id}/status', 'setStatus')->whereNumber('id');
                        Route::delete('rules/{id}', 'destroy')->whereNumber('id');
                        Route::post('rules/{id}/run', 'runNow')->whereNumber('id');
                        Route::post('activity/{id}/revert', 'revert')->whereNumber('id');
                    });
                });
            });

            // Everything waiting for the seller, from the one insight store — so Home, this list
            // and notifications cannot disagree about what needs attention.
            Route::group(['prefix' => 'action-center'], function () {
                Route::middleware('seller_can:orders.view,products.view')->group(function () {
                    Route::controller(SellerActionCenterController::class)->group(function () {
                        Route::get('/', 'index');
                        Route::post('{id}/dismiss', 'dismiss')->whereNumber('id');
                    });
                });
            });

            // The panel's report pages, as data. Every figure comes from SellerReportService, which
            // the vendor controllers read too, so the app and the panel cannot drift apart.
            Route::group(['prefix' => 'reports'], function () {
                Route::controller(SellerReportController::class)->group(function () {
                    // A report carries the same rows as the screen it summarises, so it is gated
                    // the same way — an export is not a lower bar than the list it exports.
                    Route::middleware('seller_can:orders.view,orders.manage,finance.view')->group(function () {
                        Route::get('orders', 'orders');
                        Route::get('orders/export', 'exportOrders');
                        Route::get('orders/export-pdf', 'exportOrdersPdf');
                    });

                    Route::middleware('seller_can:products.view,products.manage,inventory.manage')->group(function () {
                        Route::get('products', 'products');
                        Route::get('products/export', 'exportProducts');
                        Route::get('stock', 'stock');
                        Route::get('stock/export', 'exportStock');
                    });
                });
            });

            // The only place a seller's money leaves the platform, so reading and moving are two
            // different permissions: a finance clerk can be given the books without being given the
            // ability to withdraw from them.
            Route::group(['prefix' => 'payouts'], function () {
                Route::controller(SellerPayoutController::class)->group(function () {
                    Route::get('/', 'index')->middleware('seller_can:finance.view');
                    Route::post('/', 'store')->middleware('seller_can:payouts.request');
                    Route::post('{id}/cancel', 'cancel')->whereNumber('id')->middleware('seller_can:payouts.request');
                });
            });

            // The account, line by line. Reading the books is a separate permission from moving
            // money out of them, and this is squarely the first.
            Route::group(['prefix' => 'statement', 'middleware' => 'seller_can:finance.view'], function () {
                Route::controller(SellerStatementController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('export', 'export');
                });
            });

            // The goods coming back. Reading is open to anyone who can open the app; deciding what
            // happens to a return is the same permission as working an order.
            Route::group(['prefix' => 'returns'], function () {
                Route::controller(SellerReturnController::class)->group(function () {
                    Route::middleware('seller_can:orders.view,orders.manage')->group(function () {
                        Route::get('/', 'index');
                        Route::get('{id}', 'show')->whereNumber('id');
                    });
                    Route::post('{id}/in-transit', 'markInTransit')->whereNumber('id')->middleware('seller_can:orders.manage');
                    Route::post('{id}/receive', 'receive')->whereNumber('id')->middleware('seller_can:orders.manage');
                    Route::post('{id}/reject', 'reject')->whereNumber('id')->middleware('seller_can:orders.manage');
                });
            });

            // The stock ledger the marketplace has kept since Phase 3, which no seller has ever been
            // able to see. Reading is open to anyone who can open the app; changing a balance needs
            // the same permission as changing it one product at a time.
            Route::group(['prefix' => 'inventory'], function () {
                Route::controller(SellerInventoryController::class)->group(function () {
                    Route::middleware('seller_can:inventory.manage,products.view')->group(function () {
                        Route::get('overview', 'overview');
                        Route::get('movements', 'movements');
                        Route::get('warehouses', 'warehouses');
                        Route::get('batches', 'batches');
                    });
                    Route::post('products/{id}/adjust', 'adjust')
                        ->whereNumber('id')->middleware('seller_can:inventory.manage');
                });
            });

            // Bulk changes are gated by what they change, not by the fact that they are bulk: a
            // warehouse clerk who may set stock one product at a time may set it for four hundred,
            // and still may not touch a price.
            Route::group(['prefix' => 'bulk-jobs'], function () {
                Route::controller(SellerBulkJobController::class)->group(function () {
                    Route::middleware('seller_can:products.view,products.manage,inventory.manage')->group(function () {
                        Route::get('/', 'index');
                        Route::get('{id}', 'show')->whereNumber('id');
                        Route::get('{id}/failures', 'downloadFailures')->whereNumber('id');
                    });
                    Route::post('price', 'storePriceUpdate')->middleware('seller_can:products.manage');
                    Route::post('stock', 'storeStockUpdate')->middleware('seller_can:inventory.manage');
                });
            });
        });

    });

    /*
     * These two were declared AFTER the seller_api_auth group closed, so they inherited no
     * authentication: any anonymous caller could iterate {seller_id} and dump every vendor's full
     * catalogue (including unpublished and out-of-stock items). Moved under seller_api_auth.
     * The controller must still verify the token's seller owns {seller_id} — see the ownership
     * check added in getVendorAllProducts().
     */
    Route::group(['middleware' => ['seller_api_auth']], function () {
        Route::controller(ProductController::class)->group(function () {
            Route::group(['prefix' => 'products'], function () {
                Route::middleware('seller_can:products.view,products.manage,orders.manage')->group(function () {
                    Route::get('{seller_id}/all-products', 'getVendorAllProducts');
                    Route::get('{seller_id}/edit-order-all-products', 'editOrderVendorAllProducts');
                });
            });
        });
    });
});

