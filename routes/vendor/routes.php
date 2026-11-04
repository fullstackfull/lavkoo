<?php

use App\Enums\ViewPaths\Vendor\Chatting;
use App\Enums\ViewPaths\Vendor\Coupon;
use App\Enums\ViewPaths\Vendor\POS;
use App\Enums\ViewPaths\Vendor\POSOrder;
use App\Enums\ViewPaths\Vendor\Refund;
use App\Enums\ViewPaths\Vendor\Review;
use App\Http\Controllers\Vendor\Auth\ForgotPasswordController;
use App\Http\Controllers\Vendor\Auth\LoginController;
use App\Http\Controllers\Vendor\Auth\RegisterController;
use App\Http\Controllers\Vendor\Auth\StaffLoginController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\ChattingController;
use App\Http\Controllers\Vendor\Coupon\CouponController;
use App\Http\Controllers\Vendor\CustomerController;
use App\Http\Controllers\Vendor\DeliveryMan\DeliveryManController;
use App\Http\Controllers\Vendor\DeliveryMan\DeliveryManWalletController;
use App\Http\Controllers\Vendor\DeliveryMan\DeliveryManWithdrawController;
use App\Http\Controllers\Vendor\DeliveryMan\EmergencyContactController;
use App\Http\Controllers\Vendor\NotificationController;
use App\Http\Controllers\Vendor\Order\OrderEditController;
use App\Http\Controllers\Vendor\POS\CartController;
use App\Http\Controllers\Vendor\POS\POSController;
use App\Http\Controllers\Vendor\POS\POSOrderController;
use App\Http\Controllers\Vendor\Product\ProductController;
use App\Http\Controllers\Vendor\ProfileController;
use App\Http\Controllers\Vendor\Promotion\ClearanceSaleController;
use App\Http\Controllers\Vendor\RefundController;
use App\Http\Controllers\Vendor\ReviewController;
use App\Http\Controllers\Vendor\Shipping\CategoryShippingCostController;
use App\Http\Controllers\Vendor\Shipping\ShippingMethodController;
use App\Http\Controllers\Vendor\Shipping\ShippingTypeController;
use App\Http\Controllers\Vendor\ShopController;
use App\Http\Controllers\FirebaseController;
use App\Http\Controllers\Vendor\SystemController;
use App\Http\Controllers\Vendor\WithdrawController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Vendor\Order\OrderController;
use App\Http\Controllers\Vendor\TransactionReportController;
use App\Http\Controllers\Vendor\ProductReportController;
use App\Http\Controllers\Vendor\OrderReportController;
use App\Http\Controllers\Vendor\VendorPaymentInfoController;

Route::group(['middleware' => ['maintenance_mode', 'actch:admin_panel']], function () {

    Route::group(['prefix' => 'vendor', 'as' => 'vendor.'], function () {
        // Rate limited: captcha removed by product decision — this is now the brute-force barrier.
        Route::group(['prefix' => 'auth', 'as' => 'auth.', 'middleware' => ['throttle:auth']], function () {
            Route::controller(LoginController::class)->group(function () {
                Route::get('login', 'getLoginView');
                Route::post('login', 'login')->name('login');
                // The address was literally the string `vendor.auth.login` — a route name pasted
                // into the URI slot, which put logout at /vendor/auth/vendor.auth.login. Every
                // caller uses the name, so the URL is all that changes.
                Route::get('logout', 'logout')->name('logout');
            });
            Route::group(['prefix' => 'forgot-password', 'as' => 'forgot-password.'], function () {
                Route::controller(ForgotPasswordController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('index', 'getPasswordResetRequest');
                    Route::get('otp-verification', 'getOTPVerificationView')->name('otp-verification');
                    Route::post('otp-verification', 'submitOTPVerificationCode');
                    Route::get('reset-password', 'getPasswordResetView')->name('reset-password');
                    Route::post('reset-password', 'resetPassword');
                });
            });
            Route::group(['prefix' => 'registration', 'as' => 'registration.'], function () {
                Route::controller(RegisterController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('add', 'add')->name('add');
                });
            });
        });

        // Seller staff sign-in (Phase 3, Stage A). A staff member signs in with their own credentials
        // and is logged in as their parent seller; SellerStaffAccessMiddleware then scopes what they may
        // do. Rate limited like the owner login — this is the brute-force barrier.
        Route::group(['prefix' => 'staff-auth', 'as' => 'staff-auth.', 'middleware' => ['throttle:auth']], function () {
            Route::controller(StaffLoginController::class)->group(function () {
                Route::get('login', 'getLoginView')->name('login');
                Route::post('login', 'login')->name('login.submit');
                Route::get('logout', 'logout')->name('logout');
            });
        });

        Route::group(['middleware' => ['seller', 'seller_staff_access']], function () {
            Route::controller(FirebaseController::class)->group(function () {
                Route::post('system/save-fcm-web-token', 'saveSellerWebToken')->name('system.save-fcm-web-token');
            });

            // V2 sidebar pin shortcuts (server-side persistence, per-seller)
            Route::group(['prefix' => 'v2/sidebar-pins', 'as' => 'v2.sidebar-pins.'], function () {
                Route::get('/', [\App\Http\Controllers\Vendor\V2SidebarPinController::class, 'index'])->name('index');
                Route::post('toggle',  [\App\Http\Controllers\Vendor\V2SidebarPinController::class, 'toggle'])->name('toggle');
                Route::post('replace', [\App\Http\Controllers\Vendor\V2SidebarPinController::class, 'replace'])->name('replace');
            });

            /*
            | A vendor's own analytics. No id in the route on purpose: the seller is taken from the
            | authentication guard, so there is no parameter to change and no way to ask for
            | somebody else's numbers.
            */
            Route::get('analytics', [\App\Http\Controllers\Vendor\AnalyticsController::class, 'index'])
                ->name('analytics.index');

            Route::group(['prefix' => 'dashboard', 'as' => 'dashboard.'], function () {
                Route::controller(DashboardController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::get('order-status/{type}', 'getOrderStatus')->name('order-status');
                    Route::get('earning-statistics', 'getEarningStatistics')->name('earning-statistics');
                    Route::post('withdraw-request', 'getWithdrawRequest')->name('withdraw-request');
                    Route::get('withdraw-request', 'getMethodList')->name('method-list');
                    Route::post('withdraw-request-update', 'updateWithdrawRequest')->name('withdraw-request-update');
                    Route::get('real-time-activities', 'getRealTimeActivities')->name('real-time-activities');
                });
            });
            Route::group(['prefix' => 'pos', 'as' => 'pos.'], function () {
                Route::controller(POSController::class)->group(function () {
                    Route::get(POS::INDEX[URI], 'index')->name('index');
                    Route::any(POS::CHANGE_CUSTOMER[URI], 'changeCustomer')->name('change-customer');
                    Route::post(POS::UPDATE_DISCOUNT[URI], 'updateDiscount')->name('update-discount');
                    Route::post(POS::COUPON_DISCOUNT[URI], 'getCouponDiscount')->name('coupon-discount');
                    Route::get(POS::QUICK_VIEW[URI], 'getQuickView')->name('quick-view');
                    Route::get(POS::SEARCH[URI], 'getSearchedProductsView')->name('search-product');
                });
                Route::controller(CartController::class)->group(function () {
                    Route::post('get-variant-price', 'getVariantPrice')->name('get-variant-price');
                    Route::post('quantity-update', 'updateQuantity')->name('quantity-update');
                    Route::get('get-cart-ids', 'getCartIds')->name('get-cart-ids');
                    Route::get('clear-cart-ids', 'clearSessionCartIds')->name('clear-cart-ids');
                    Route::post('add-to-cart', 'addToCart')->name('add-to-cart');
                    Route::post('cart-remove', 'removeCart')->name('cart-remove');
                    Route::any('cart-empty', 'emptyCart')->name('cart-empty');
                    Route::any('change-cart', 'changeCart')->name('change-cart');
                    Route::get('new-cart-id', 'addNewCartId')->name('new-cart-id');
                });
                Route::controller(POSOrderController::class)->group(function () {
                    Route::post(POSOrder::ORDER_DETAILS[URI] . '/{id}', 'index')->name('order-details');
                    Route::post(POSOrder::ORDER_PLACE[URI], 'placeOrder')->name('order-place');
                    Route::any(POSOrder::CANCEL_ORDER[URI], 'cancelOrder')->name('cancel-order');
                    Route::any(POSOrder::HOLD_ORDERS[URI], 'getAllHoldOrdersView')->name('view-hold-orders');
                });
            });
            Route::group(['prefix' => 'refund', 'as' => 'refund.'], function () {
                Route::controller(RefundController::class)->group(function () {
                    Route::get('index/{status}', 'index')->name('index');
                    Route::get('details/{id}', 'getDetailsView')->name('details');
                    Route::post('update-status', 'updateStatus')->name('update-status');
                    Route::get('export/{status}', 'exportList')->name('export');
                });
            });

            Route::group(['prefix' => 'products', 'as' => 'products.'], function () {
                Route::controller(ProductController::class)->group(function () {
                    Route::get('list/{type}', 'index')->name('list');
                    Route::get('add', 'getAddView')->name('add');
                    Route::post('add', 'add');
                    Route::get('get-categories', 'getCategories')->name('get-categories');
                    Route::post('sku-combination', 'getSkuCombinationView')->name('sku-combination');
                    Route::post('digital-variation-combination', 'getDigitalVariationCombinationView')->name('digital-variation-combination');
                    Route::post('digital-variation-file-delete', 'deleteDigitalVariationFile')->name('digital-variation-file-delete');
                    Route::post('status-update', 'updateStatus')->name('status-update');
                    Route::get('export-excel/{type}', 'exportList')->name('export-excel');
                    Route::get('view/{id}', 'getView')->name('view');
                    Route::get('barcode/{id}', 'getBarcodeView')->name('barcode');
                    Route::delete('delete/{id}', 'delete')->name('delete');
                    Route::get('stock-limit-list', 'getStockLimitListView')->name('stock-limit-list');
                    Route::post('update-quantity', 'updateQuantity')->name('update-quantity');
                    Route::get('update/{id}', 'getUpdateView')->name('update');
                    Route::post('update/{id}', 'update');
                    Route::post('update-product-images/{id}', 'updateProductImages')->name('update-product-images');
                    Route::get('delete-image', 'deleteImage')->name('delete-image');
                    Route::get('get-variations', 'getVariations')->name('get-variations');
                    Route::get('bulk-import', 'getBulkImportView')->name('bulk-import');
                    Route::post('bulk-import', 'importBulkProduct');
                    Route::get('search', 'getSearchedProductsView')->name('search-product');
                    Route::get('product-gallery', 'getProductGalleryView')->name('product-gallery');
                    Route::get('stock-limit-status', 'getStockLimitStatus')->name('stock-limit-status');
                    Route::post('delete-preview-file', 'deletePreviewFile')->name('delete-preview-file');
                    Route::get('request-restock-list', 'getRequestRestockListView')->name('request-restock-list');
                    Route::get('export-restock', 'exportRestockList')->name('restock-export');
                    Route::delete('delete-restock/{id}', 'deleteRestock')->name('restock-delete');

                    Route::post('load-more-brands', 'loadMoreBrands')->name('load-more-brands');
                });
            });

            Route::group(['prefix' => 'orders', 'as' => 'orders.'], function () {
                Route::controller(OrderController::class)->group(function () {
                    Route::get('list/{status}', 'index')->name('list');
                    Route::get('customers', 'getCustomers')->name('customers');
                    Route::get('export-excel/{status}', 'exportList')->name('export-excel');
                    Route::get('generate-invoice/{id}', 'generateInvoice')->name('generate-invoice');
                    Route::get('details/{id}', 'getView')->name('details');
                    Route::post('address-update', 'updateAddress')->name('address-update');// update address from order details
                    Route::post('payment-status', 'updatePaymentStatus')->name('payment-status');
                    Route::post('update-deliver-info', 'updateDeliverInfo')->name('update-deliver-info');
                    Route::get('add-delivery-man/{order_id}/{d_man_id}', 'addDeliveryMan')->name('add-delivery-man');
                    Route::post('amount-date-update', 'updateAmountDate')->name('amount-date-update');
                    Route::post('digital-file-upload-after-sell', 'uploadDigitalFileAfterSell')->name('digital-file-upload-after-sell');
                    Route::post('status', 'updateStatus')->name('status');
                    Route::post('customer-return-amount', 'orderReturnAmountToCustomer')->name('customer-return-amount');
                    Route::post('customer-due-amount', 'orderDueAmountSwitchToCOD')->name('customer-due-amount');
                    Route::post('customer-due-amount-mark-as-paid', 'orderDueAmountMarkAsPaid')->name('customer-due-amount-mark-as-paid');
                });
                Route::controller(OrderEditController::class)->group(function () {
                    Route::get('search-for-edit-order-product', 'getSearchEditOrderProductsView')->name('search-for-edit-order-product');
                    Route::post('edit-order-product-modal-view', 'getEditOrderProductModalView')->name('edit-order-product-modal-view');
                    Route::post('edit-order-product-add', 'addEditOrderProduct')->name('edit-order-product-add');
                    Route::post('edit-order-product-variant-price', 'checkProductVariantPrice')->name('edit-order-product-variant-price');
                    Route::post('edit-order-product-list-update', 'updateEditOrderProductList')->name('edit-order-product-list-update');
                    Route::post('edit-order-product-remove', 'removeEditOrderProduct')->name('edit-order-product-remove');
                    Route::post('edit-order-generate', 'generateEditOrderByProductList')->name('edit-order-generate');
                });
            });

            Route::group(['prefix' => 'customer', 'as' => 'customer.'], function () {
                Route::controller(CustomerController::class)->group(function () {
                    Route::get('list', 'getList')->name('list');
                    Route::post('add', 'add')->name('add');
                });
            });

            Route::group(['prefix' => 'reviews', 'as' => 'reviews.'], function () {
                Route::controller(ReviewController::class)->group(function () {
                    Route::get(Review::INDEX[URI], 'index')->name('index');
                    Route::get(Review::UPDATE_STATUS[URI] . '/{id}/{status}', 'updateStatus')->name('update-status');
                    Route::get(Review::EXPORT[URI], 'exportList')->name('export');
                    Route::post(Review::REVIEW_REPLY[URI], 'addReviewReply')->name('add-review-reply');
                });
            });

            Route::group(['prefix' => 'coupon', 'as' => 'coupon.'], function () {
                Route::controller(CouponController::class)->group(function () {
                    Route::get(Coupon::INDEX[URI], 'index')->name('index');
                    Route::post(Coupon::ADD[URI], 'add')->name('add');
                    Route::get(Coupon::UPDATE[URI] . '/{id}', 'getUpdateView')->name('update');
                    Route::post(Coupon::UPDATE[URI] . '/{id}', 'update');
                    Route::get(Coupon::UPDATE_STATUS[URI] . '/{id}/{status}', 'updateStatus')->name('update-status');
                    Route::delete(Coupon::DELETE[URI] . '/{id}', 'delete')->name('delete');
                    Route::get(Coupon::QUICK_VIEW[URI], 'getQuickView')->name('quick-view');
                    Route::get(Coupon::EXPORT[URI], 'exportList')->name('export');
                });
            });

            Route::group(['prefix' => 'clearance-sale', 'as' => 'clearance-sale.'], function () {
                Route::controller(ClearanceSaleController::class)->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::post('status-update', 'updateStatus')->name('status-update');
                    Route::post('update-config', 'updateClearanceConfig')->name('update-config');
                    Route::post('update-seo-meta', 'updateClearanceSeoConfig')->name('update-seo-meta');
                    Route::get('search', 'getSearchedProductsView')->name('search-product-for-clearance');
                    Route::get('multiple-product-details', 'getMultipleProductDetailsView')->name('multiple-clearance-product-details');
                    Route::post('add-clearance-product', 'addClearanceProduct')->name('add-product');
                    Route::post('clearance-product-status-update', 'updateProductStatus')->name('product-status-update');
                    Route::delete('clearance-delete' . '/{product_id}', 'deleteClearanceProduct')->name('clearance-delete');
                    Route::delete('clearance-products-delete', 'deleteClearanceAllProduct')->name('clearance-delete-all-product');
                    Route::post('update-discount', 'updateDiscountAmount')->name('update-discount');
                });
            });

            Route::group(['prefix' => 'messages', 'as' => 'messages.'], function () {
                Route::controller(ChattingController::class)->group(function () {
                    Route::get(Chatting::INDEX[URI] . '/{type}', 'index')->name('index');
                    Route::get(Chatting::MESSAGE[URI], 'getMessageByUser')->name('message');
                    Route::post(Chatting::MESSAGE[URI], 'addVendorMessage');
                    Route::get(Chatting::NEW_NOTIFICATION[URI], 'getNewNotification')->name('new-notification');
                });
            });

            Route::group(['prefix' => 'notification', 'as' => 'notification.'], function () {
                Route::post('index', [NotificationController::class, 'getNotificationModalView'])->name('index');
            });

            /* DeliveryMan */
            Route::group(['prefix' => 'delivery-man', 'as' => 'delivery-man.'], function () {
                Route::controller(DeliveryManController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::post('index', 'add');
                    Route::get('list', 'getListView')->name('list');
                    Route::get('export', 'exportList')->name('export');
                    Route::get('update/{id}', 'getUpdateView')->name('update');
                    Route::post('update/{id}', 'update');
                    Route::post('update-status/{id}', 'updateStatus')->name('update-status');
                    Route::delete('delete/{id}', 'delete')->name('delete');
                    Route::get('rating/{id}', 'getRatingView')->name('rating');
                });

                Route::group(['prefix' => 'wallet', 'as' => 'wallet.'], function () {
                    Route::controller(DeliveryManWalletController::class)->group(function () {
                        Route::get('index/{id}', 'index')->name('index');
                        Route::get('order-history/{id}', 'getOrderHistory')->name('order-history');
                        Route::get('order-history-status/{order}', 'getOrderStatusHistory')->name('order-status-history');
                        Route::get('earning/{id}', 'getEarningListView')->name('earning');
                        Route::get('cash-collect/{id}', 'getCashCollectView')->name('cash-collect');
                        Route::post('cash-collect/{id}', 'collectCash');
                    });
                });

                Route::group(['prefix' => 'withdraw', 'as' => 'withdraw.'], function () {
                    Route::controller(DeliveryManWithdrawController::class)->group(function () {
                        Route::get('index', 'index')->name('index');
                        Route::post('index', 'getFiltered');
                        Route::get('details/{withdrawId}', 'getDetails')->name('details');
                        Route::post('update-status/{withdrawId}', 'updateStatus')->name('update-status');
                        Route::any('export', 'exportList')->name('export');
                    });
                });

                Route::group(['prefix' => 'emergency-contact', 'as' => 'emergency-contact.'], function () {
                    Route::controller(EmergencyContactController::class)->group(function () {
                        Route::get('index', 'index')->name('index');
                        Route::post('index', 'add');
                        Route::get('update/{id}', 'getUpdateView')->name('update');
                        Route::post('update/{id}', 'update');
                        Route::patch('index', 'updateStatus');
                        Route::delete('index', 'delete');
                    });
                });
            });

            Route::group(['prefix' => 'profile', 'as' => 'profile.'], function () {
                Route::controller(ProfileController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::get('update/{id}', 'getUpdateView')->name('update');
                    Route::post('update/{id}', 'update');
                    Route::patch('update/{id}', 'updatePassword');
                    Route::get('update-bank-info/{id}', 'getBankInfoUpdateView')->name('update-bank-info');
                    Route::post('update-bank-info/{id}', 'updateBankInfo');
                });
            });

            Route::group(['prefix' => 'shop', 'as' => 'shop.'], function () {
                Route::controller(ShopController::class)->group(function () {
                    Route::get('index', 'index')->name('index');
                    Route::get('update/{id}', 'getUpdateView')->name('update');
                    Route::post('update/{id}', 'update');
                    Route::post('add-vacation', 'updateVacation')->name('update-vacation');
                    Route::post('close-shop-temporary', 'closeShopTemporary')->name('close-shop-temporary');
                    Route::post('update-other-settings', 'updateOtherSettings')->name('update-other-settings');

                    Route::get('other-setup', 'getOtherSetupView')->name('other-setup');
                });

                Route::group(['prefix' => 'payment-information', 'as' => 'payment-information.'], function () {
                    Route::controller(VendorPaymentInfoController::class)->group(function () {
                        Route::get('', 'index')->name('index');
                        Route::post('add', 'add')->name('add');
                        Route::post('update', 'update')->name('update');
                        Route::get('edit/{id?}', 'getUpdateView')->name('update-view');
                        Route::get('delete/{id?}', 'delete')->name('delete');
                        Route::post('default', 'updateDefault')->name('default');
                        Route::post('status', 'updateStatus')->name('update-status');
                        Route::get('dynamic-fields', 'getDynamicPaymentInformationView')->name('dynamic-fields');
                    });
                });
            });

            Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.'], function () {
                Route::group(['prefix' => 'shipping-method', 'as' => 'shipping-method.'], function () {
                    Route::controller(ShippingMethodController::class)->group(function () {
                        Route::get('index', 'index')->name('index');
                        Route::post('index', 'add');
                        Route::get('update/{id}', 'getUpdateView')->name('update');
                        Route::post('update/{id}', 'update');
                        Route::post('update-status', 'updateStatus')->name('update-status');
                        Route::post('delete', 'delete')->name('delete');
                    });
                });

                Route::group(['prefix' => 'shipping-type', 'as' => 'shipping-type.'], function () {
                    Route::post('index', [ShippingTypeController::class, 'addOrUpdate'])->name('index');
                });

                Route::group(['prefix' => 'category-wise-shipping-cost', 'as' => 'category-wise-shipping-cost.'], function () {
                    Route::post('index', [CategoryShippingCostController::class, 'index'])->name('index');
                });

                Route::group(['prefix' => 'withdraw', 'as' => 'withdraw.'], function () {
                    Route::controller(WithdrawController::class)->group(function () {
                        Route::get('index', 'index')->name('index');
                        Route::post('index', 'getListByStatus');
                        Route::get('close/{id}', 'closeWithdrawRequest')->name('close');
                        Route::get('export', 'exportList')->name('export-withdraw-list');
                        Route::post('render-withdraw-method-infos', 'renderInfosView')->name('render-withdraw-method-infos');
                    });
                });

                // Ledger-based payouts (Phase 3, Stage B). Runs over the vendor ledger — a request
                // can only ask for the withdrawable balance and reserves it — alongside the legacy
                // withdraw flow above, which stays untouched until reads are moved over.
                Route::group(['prefix' => 'payouts', 'as' => 'payouts.'], function () {
                    Route::controller(\App\Http\Controllers\Vendor\Marketplace\PayoutController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'store')->name('store');
                        Route::post('{id}/cancel', 'cancel')->whereNumber('id')->name('cancel');
                    });
                });

                // Seller KYC (Phase 3, Stage A): the seller submits identity/business documents and
                // sees each one's review state. Alongside payouts because verification gates them.
                Route::group(['prefix' => 'seller-verification', 'as' => 'seller-verification.'], function () {
                    Route::controller(\App\Http\Controllers\Vendor\Marketplace\SellerVerificationController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('document/{id}', 'document')->whereNumber('id')->name('document');
                        Route::post('/', 'store')->name('store');
                    });
                });

                // Seller's own performance scorecard (Phase 3, Stage A) — the same metrics the admin sees.
                Route::group(['prefix' => 'seller-scorecard', 'as' => 'seller-scorecard.'], function () {
                    Route::controller(\App\Http\Controllers\Vendor\Marketplace\SellerScorecardController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                    });
                });

                // Seller Center hub (Phase 3, Stage A): one cockpit over verification, performance,
                // finance and SLA — composed from the seller-facing services, not re-derived.
                Route::group(['prefix' => 'seller-center', 'as' => 'seller-center.'], function () {
                    Route::controller(\App\Http\Controllers\Vendor\Marketplace\SellerCenterController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                    });
                });

                // Seller staff & roles (Phase 3, Stage A): the seller defines roles and manages their
                // team. Permission enforcement / staff sign-in is the named deferred step.
                Route::group(['prefix' => 'staff', 'as' => 'staff.'], function () {
                    Route::controller(\App\Http\Controllers\Vendor\Marketplace\SellerStaffController::class)->group(function () {
                        Route::get('/', 'index')->name('index');
                        Route::post('/', 'storeStaff')->name('store');
                        Route::put('{id}', 'updateStaff')->whereNumber('id')->name('update');
                        Route::delete('{id}', 'destroyStaff')->whereNumber('id')->name('destroy');
                        Route::group(['prefix' => 'roles', 'as' => 'roles.'], function () {
                            Route::post('/', 'storeRole')->name('store');
                            Route::put('{id}', 'updateRole')->whereNumber('id')->name('update');
                            Route::delete('{id}', 'destroyRole')->whereNumber('id')->name('destroy');
                        });
                    });
                });
            });

            Route::controller(SystemController::class)->group(function () {
                Route::get('/get-order-data', 'getOrderData')->name('get-order-data');
            });

            Route::group(['prefix' => 'report', 'as' => 'report.'], function () {
                Route::controller(ProductReportController::class)->group(function () {
                    Route::get('all-product', 'all_product')->name('all-product');
                    Route::get('all-product-excel', 'allProductExportExcel')->name('all-product-excel');

                    Route::get('stock-product-report', 'stock_product_report')->name('stock-product-report');
                    Route::get('product-stock-export', 'productStockExport')->name('product-stock-export');
                });

                Route::controller(OrderReportController::class)->group(function () {
                    Route::get('order-report', 'order_report')->name('order-report');
                    Route::get('order-report-excel', 'orderReportExportExcel')->name('order-report-excel');
                    Route::get('order-report-pdf', 'exportOrderReportInPDF')->name('order-report-pdf');
                });
            });

            Route::group(['prefix' => 'transaction', 'as' => 'transaction.'], function () {
                Route::controller(TransactionReportController::class)->group(function () {
                    Route::get('order-list', 'order_transaction_list')->name('order-list');
                    Route::get('pdf-order-wise-transaction', 'pdf_order_wise_transaction')->name('pdf-order-wise-transaction');
                    Route::get('order-transaction-export-excel', 'orderTransactionExportExcel')->name('order-transaction-export-excel');
                    Route::get('order-transaction-summary-pdf', 'order_transaction_summary_pdf')->name('order-transaction-summary-pdf');
                    Route::get('expense-list', 'getExpenseTransactionList')->name('expense-list');
                    Route::get('pdf-order-wise-expense-transaction', 'pdf_order_wise_expense_transaction')->name('pdf-order-wise-expense-transaction');
                    Route::get('expense-transaction-summary-pdf', 'expense_transaction_summary_pdf')->name('expense-transaction-summary-pdf');
                    Route::get('expense-transaction-export-excel', 'expenseTransactionExportExcel')->name('expense-transaction-export-excel');
                });
            });
        });
    });

});
