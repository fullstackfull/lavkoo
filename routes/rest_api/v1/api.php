<?php

use App\Http\Controllers\RestAPI\v1\AppHealthController;
use App\Http\Controllers\RestAPI\v1\AttributeController;
use App\Http\Controllers\RestAPI\v1\auth\CustomerAPIAuthController;
use App\Http\Controllers\RestAPI\v1\auth\EmailVerificationController;
use App\Http\Controllers\RestAPI\v1\auth\ForgotPasswordController;
use App\Http\Controllers\RestAPI\v1\auth\PassportAuthController;
use App\Http\Controllers\RestAPI\v1\auth\PhoneVerificationController;
use App\Http\Controllers\RestAPI\v1\auth\SocialAuthController;
use App\Http\Controllers\RestAPI\v1\AnalyticsEventController;
use App\Http\Controllers\RestAPI\v1\BannerController;
use App\Http\Controllers\RestAPI\v1\FulfillmentController;
use App\Http\Controllers\RestAPI\v1\BrandController;
use App\Http\Controllers\RestAPI\v1\CartController;
use App\Http\Controllers\RestAPI\v1\CategoryController;
use App\Http\Controllers\RestAPI\v1\ChatController;
use App\Http\Controllers\RestAPI\v1\CompareController;
use App\Http\Controllers\RestAPI\v1\ConfigController;
use App\Http\Controllers\RestAPI\v1\DeepLinkController;
use App\Http\Controllers\RestAPI\v1\CouponController;
use App\Http\Controllers\RestAPI\v1\CustomerController;
use App\Http\Controllers\RestAPI\v1\CustomerRestockRequestController;
use App\Http\Controllers\RestAPI\v1\DealController;
use App\Http\Controllers\RestAPI\v1\DealOfTheDayController;
use App\Http\Controllers\RestAPI\v1\FlashDealController;
use App\Http\Controllers\RestAPI\v1\GeneralController;
use App\Http\Controllers\RestAPI\v1\MapApiController;
use App\Http\Controllers\RestAPI\v1\NotificationController;
use App\Http\Controllers\RestAPI\v1\OrderController;
use App\Http\Controllers\RestAPI\v1\OrderEditController;
use App\Http\Controllers\RestAPI\v1\ProductController;
use App\Http\Controllers\RestAPI\v1\ReviewController;
use App\Http\Controllers\RestAPI\v1\SellerController;
use App\Http\Controllers\RestAPI\v1\ShippingMethodController;
use App\Http\Controllers\RestAPI\v1\ThemeSectionController;
use App\Http\Controllers\RestAPI\v1\UserLoyaltyController;
use App\Http\Controllers\RestAPI\v1\UserWalletController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Customer\PaymentController;

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

Route::group(['prefix' => 'v1', 'middleware' => ['api_lang']], function () {



Route::get('/cash', function () {
    return 400000;
});




    Route::controller(ConfigController::class)->group(function () {
        Route::get('config', 'configuration');
        Route::get('business-pages', 'getBusinessPagesList');
    });

    /*
     * Deep links. Unauthenticated because a campaign link arrives before the customer has logged
     * in — that is the whole point of one — and because the association files that publish the same
     * path list are already fetched unauthenticated by every phone. Throttled because resolve()
     * reads a campaign row per call and is reachable by anybody.
     */
    Route::group(['prefix' => 'deep-link', 'middleware' => ['throttle:60,1']], function () {
        Route::controller(DeepLinkController::class)->group(function () {
            Route::get('config', 'config')->name('api.v1.deep-link.config');
            Route::get('resolve', 'resolve')->name('api.v1.deep-link.resolve');
        });
    });

    /*
     * App health. Unauthenticated because an app that has just crashed cannot refresh a token, and
     * requiring one would mean the only sessions ever reported are the healthy ones — the exact
     * bias that makes a crash-free figure worthless. It accepts three counters and nothing else,
     * stores no free-form text, and always answers 204.
     *
     * Throttled per IP: this is a public write into a monitoring table, and the limit is what keeps
     * "how many sessions" from being decided by whoever calls it most.
     */
    Route::post('app-health', AppHealthController::class)
        ->middleware('throttle:30,1')
        ->name('api.v1.app-health');

    /*
     * What only the app can see. Unauthenticated for the same reason the storefront's beacon is:
     * a banner is shown to guests, and an endpoint that counted only signed-in shoppers would
     * report a biased subset while looking complete. Only allow-listed event names are accepted,
     * none of them carry money, and it always answers 204.
     *
     * Throttled per IP, like the beacon: a public write into an analytics table must not let
     * whoever calls it most decide what the reports say.
     */
    Route::post('analytics/events', AnalyticsEventController::class)
        ->middleware('throttle:' . (int) config('analytics.beacon.rate_limit_per_minute', 60) . ',1')
        ->name('api.v1.analytics.events');

    /*
     * Rate limited: login, OTP verification/resend and password reset are credential-stuffing and
     * OTP-brute-force targets. The only limit before this was the global api group's
     * throttle:3000,1 — effectively none.
     */
    Route::group(['prefix' => 'auth', 'middleware' => ['throttle:auth']], function () {
        Route::controller(PassportAuthController::class)->group(function () {
            Route::get('logout', 'logout')->middleware('auth:api');
        });

        Route::controller(CustomerAPIAuthController::class)->group(function () {
            Route::post('register', 'register');
            Route::post('login', 'login');
            Route::post('check-email', 'checkEmail');
            Route::post('check-phone', 'checkPhone');
            Route::post('firebase-auth-verify', 'firebaseAuthVerify');
            Route::post('firebase-auth-token-store', 'firebaseAuthTokenStore');
            Route::post('verify-otp', 'verifyOTP');
            Route::post('verify-email', 'verifyEmail');
            Route::post('verify-phone', 'verifyPhone');
            Route::post('registration-with-otp', 'registrationWithOTP');
            Route::post('existing-account-check', 'existingAccountCheck');
            Route::post('registration-with-social-media', 'registrationWithSocialMedia');
            Route::post('forgot-password', 'passwordResetRequest');
        });

        Route::group(['middleware' => 'apiGuestCheck'], function () {
            Route::controller(CustomerAPIAuthController::class)->group(function () {
                Route::post('verify-profile-info', 'verifyProfileInfo');
            });
        });

        Route::controller(PhoneVerificationController::class)->group(function () {
            Route::post('resend-otp-check-phone', 'resend_otp_check_phone');
        });
        Route::controller(EmailVerificationController::class)->group(function () {
            Route::post('resend-otp-check-email', 'resend_otp_check_email');
        });
        Route::controller(ForgotPasswordController::class)->group(function () {
            Route::post('verify-token', 'tokenVerificationSubmit');
            Route::put('reset-password', 'reset_password_submit');
        });
        Route::controller(SocialAuthController::class)->group(function () {
            Route::post('social-login', 'social_login');
            Route::post('update-phone', 'update_phone');
            Route::post('social-customer-login', 'customerSocialLogin');
            Route::post('existing-account-check', 'existingAccountCheck');
            Route::post('registration-with-social-media', 'registrationWithSocialMedia');
        });
    });

    Route::group(['prefix' => 'shipping-method', 'middleware' => 'apiGuestCheck'], function () {
        Route::controller(ShippingMethodController::class)->group(function () {
            Route::get('detail/{id}', 'get_shipping_method_info');
            Route::get('by-seller/{id}/{seller_is}', 'shipping_methods_by_seller');
            Route::post('choose-for-order', 'choose_for_order');
            Route::get('chosen', 'chosen_shipping_methods');
            Route::get('check-shipping-type', 'check_shipping_type');
        });
    });

    Route::group(['prefix' => 'cart', 'middleware' => 'apiGuestCheck'], function () {
        Route::controller(CartController::class)->group(function () {
            Route::get('/', 'getCartList');
            Route::post('add', 'addToCart');
            Route::put('update', 'update_cart');
            Route::delete('remove', 'remove_from_cart');
            Route::delete('remove-all', 'remove_all_from_cart');
            Route::post('select-cart-items', 'updateCheckedCartItems');
            Route::post('product-restock-request', 'addProductRestockRequest');
            Route::post('get-referral-discount-redeem', 'getReferralDiscountRedeem');
            Route::post('get-merge-guest-cart', 'getMergeGuestCart');
        });
        // Additive: tells the app whether to show the "may arrive in separate shipments" notice.
        Route::get('fulfillment-notice', [FulfillmentController::class, 'cartNotice']);
    });

    Route::group(['prefix' => 'customer/order', 'middleware' => 'apiGuestCheck'], function () {
        Route::controller(CustomerController::class)->group(function () {
            Route::get('get-order-by-id', 'getOrderById');
        });
        // Additive: the shipments of one order (the existing order-details endpoint returns a bare array).
        Route::get('fulfillments', [FulfillmentController::class, 'orderFulfillments']);
    });

    Route::group(['prefix' => 'notifications'], function () {
        Route::controller(NotificationController::class)->group(function () {
            Route::get('/', 'list');
            Route::get('/seen', 'notification_seen')->middleware('auth:api');
        });
    });

    Route::group(['prefix' => 'attributes'], function () {
        Route::controller(AttributeController::class)->group(function () {
            Route::get('/', 'get_attributes');
        });
    });

    Route::group(['prefix' => 'flash-deals'], function () {
        Route::controller(FlashDealController::class)->group(function () {
            Route::get('/', 'getFlashDeal');
            Route::get('products/{deal_id}', 'getFlashDealProducts');
        });
    });

    Route::group(['prefix' => 'deals'], function () {
        Route::controller(DealController::class)->group(function () {
            Route::get('featured', 'getFeaturedDealProducts');
        });
    });

    Route::group(['prefix' => 'dealsoftheday'], function () {
        Route::controller(DealOfTheDayController::class)->group(function () {
            Route::get('deal-of-the-day', 'getDealOfTheDayProduct');
        });
    });

    Route::group(['prefix' => 'products'], function () {
        Route::controller(ProductController::class)->group(function () {
            Route::get('reviews/{slug}', 'get_product_reviews');
            Route::get('rating/{product_id}', 'get_product_rating');
            Route::get('counter/{slug}', 'counter');
            Route::get('shipping-methods', 'get_shipping_methods');
            Route::get('social-share-link/{product_id}', 'socialShareLink');
            Route::post('reviews/submit', 'submit_product_review')->middleware('auth:api');
            Route::put('review/update', 'updateProductReview')->middleware('auth:api');
            Route::get('review/{product_id}/{order_id}', 'getProductReviewByOrder')->middleware('auth:api');
            Route::delete('review/delete-image', 'deleteReviewImage')->middleware('auth:api');
        });
    });

    Route::group(['middleware' => 'apiGuestCheck'], function () {
        Route::group(['prefix' => 'products'], function () {
            Route::controller(ProductController::class)->group(function () {
                Route::get('latest', 'get_latest_products');
                Route::get('new-arrival', 'getNewArrivalProducts');
                Route::get('featured', 'getFeaturedProductsList');
                Route::get('top-rated', 'getTopRatedProducts');
                Route::any('search', 'get_searched_products');
                Route::post('filter', 'getProductsFilter');
                Route::any('suggestion-product', 'get_suggestion_product');
                Route::get('details/{slug}', 'getProductDetails');
                Route::get('related-products/{slug}', 'get_related_products');
                Route::get('best-sellings', 'getBestSellingProducts');
                Route::get('home-categories', 'get_home_categories');
                Route::get('discounted-product', 'get_discounted_product');
                Route::get('most-demanded-product', 'get_most_demanded_product');
                Route::get('shop-again-product', 'getShopAgainProduct')->middleware('auth:api');
                Route::get('just-for-you', 'just_for_you');
                Route::get('most-searching', 'getMostSearchingProductsList');
                Route::get('digital-author-list', 'getDigitalProductsAuthorList');
                Route::get('digital-publishing-house-list', 'getDigitalPublishingHouseList');
                Route::get('clearance-sale', 'getClearanceSale');
                // Exactly these products in exactly this order — the endpoint behind a theme
                // section whose product source is "manual".
                Route::get('by-ids', 'getProductsByIds');
                // A merchant-defined dynamic collection — the endpoint behind a theme section
                // whose product source is "collection". Same response dialect as its siblings.
                Route::get('theme-collection', 'getThemeCollectionProducts');
            });
        });

        Route::group(['prefix' => 'customer'], function () {
            Route::put('language-change', [CustomerController::class, 'language_change']);
        });

        Route::group(['prefix' => 'seller'], function () {
            Route::controller(SellerController::class)->group(function () {
                Route::get('{slug}/products', 'getVendorProducts');
                Route::get('{slug}/seller-best-selling-products', 'get_seller_best_selling_products');
                Route::get('{slug}/seller-featured-product', 'get_sellers_featured_product');
                Route::get('{slug}/seller-recommended-products', 'get_sellers_recommended_products');
            });
        });

        Route::group(['prefix' => 'categories'], function () {
            Route::controller(CategoryController::class)->group(function () {
                Route::get('/', 'get_categories');
                Route::get('products/{category_id}', 'get_products');
                Route::get('page-header/{category_id}', 'getPageHeader');
                Route::get('/find-what-you-need', 'find_what_you_need');
            });
        });

        Route::group(['prefix' => 'brands'], function () {
            Route::controller(BrandController::class)->group(function () {
                Route::get('/', 'get_brands');
                Route::get('page-header/{brand_id}', 'getPageHeader');
                Route::get('products/{brand_id}', 'get_products');
            });
        });

        Route::group(['prefix' => 'customer'], function () {
            Route::controller(CustomerController::class)->group(function () {
                Route::put('cm-firebase-token', 'update_cm_firebase_token');
                Route::get('get-restricted-country-list', 'get_restricted_country_list');
                Route::get('get-restricted-zip-list', 'get_restricted_zip_list');
            });

            Route::group(['prefix' => 'address'], function () {
                Route::controller(CustomerController::class)->group(function () {
                    Route::post('add', 'add_new_address');
                    Route::get('list', 'address_list');
                    Route::delete('/', 'delete_address');
                    Route::post('update', 'update_address');
                });
            });

            Route::group(['prefix' => 'order'], function () {
                Route::controller(OrderController::class)->group(function () {
                    Route::get('place', 'place_order');
                    Route::get('offline-payment-method-list', 'offline_payment_method_list');
                    Route::post('place-by-offline-payment', 'placeOrderByOfflinePayment');
                });
                Route::controller(CustomerController::class)->group(function () {
                    Route::get('details', 'get_order_details');
                    Route::get('generate-invoice', 'getOrderInvoice');
                });
                Route::group(['prefix' => 'deliveryman-review'], function () {
                    Route::controller(ReviewController::class)->group(function () {
                        Route::get('', 'getReview');
                        Route::post('update', 'updateDeliveryManReview');
                    });
                });
            });
        });
    });

    Route::group(['prefix' => 'customer', 'middleware' => 'auth:api'], function () {
        Route::controller(CustomerController::class)->group(function () {
            Route::get('info', 'info');
            Route::put('update-profile', 'update_profile');
            Route::get('account-delete/{id}', 'account_delete');
        });

        Route::group(['prefix' => 'address'], function () {
            Route::controller(CustomerController::class)->group(function () {
            });
        });

        Route::group(['prefix' => 'support-ticket'], function () {
            Route::controller(CustomerController::class)->group(function () {
                Route::post('create', 'create_support_ticket');
                Route::get('get', 'get_support_tickets');
                Route::get('conv/{ticket_id}', 'get_support_ticket_conv');
                Route::post('reply/{ticket_id}', 'reply_support_ticket');
                Route::get('close/{id}', 'support_ticket_close');
            });
        });

        Route::group(['prefix' => 'compare'], function () {
            Route::controller(CompareController::class)->group(function () {
                Route::get('list', 'list');
                Route::post('product-store', 'compare_product_store');
                Route::delete('clear-all', 'clear_all');
                Route::get('product-replace', 'compare_product_replace');
            });
        });

        Route::group(['prefix' => 'wish-list'], function () {
            Route::controller(CustomerController::class)->group(function () {
                Route::get('/', 'wish_list');
                Route::post('add', 'add_to_wishlist');
                Route::delete('remove', 'remove_from_wishlist');
            });
        });

        Route::group(['prefix' => 'restock-requests'], function () {
            Route::controller(CustomerRestockRequestController::class)->group(function () {
                Route::get('list', 'restockRequestsList');
                Route::post('delete', 'deleteRestockRequests');
            });
        });

        Route::group(['prefix' => 'order'], function () {
            Route::controller(OrderController::class)->group(function () {
                Route::get('place-by-wallet', 'placeOrderByWallet');
                Route::get('refund', 'refund_request');
                Route::post('refund-store', 'store_refund');
                Route::get('refund-details', 'refund_details');
                Route::post('again', 'order_again');
            });

            Route::controller(CustomerController::class)->group(function () {
                Route::get('list', 'get_order_list');
            });

            Route::controller(ProductController::class)->group(function () {
                Route::post('deliveryman-reviews/submit', 'submit_deliveryman_review')->middleware('auth:api');
            });
        });

        // Chatting
        Route::group(['prefix' => 'chat'], function () {
            Route::controller(ChatController::class)->group(function () {
                Route::get('list/{type}', 'list');
                Route::get('get-messages/{type}/{id}', 'get_message');
                Route::post('send-message/{type}', 'send_message');
                Route::post('seen-message/{type}', 'seen_message');
                Route::get('search/{type}', 'search');
            });
        });

        //wallet
        Route::group(['prefix' => 'wallet'], function () {
            Route::controller(UserWalletController::class)->group(function () {
                Route::get('list', 'list');
                Route::get('bonus-list', 'bonus_list');
            });
        });

        //loyalty
        Route::group(['prefix' => 'loyalty'], function () {
            Route::controller(UserLoyaltyController::class)->group(function () {
                Route::get('list', 'list');
                Route::post('loyalty-exchange-currency', 'loyalty_exchange_currency');
            });
        });
    });

    Route::group(['prefix' => 'customer', 'middleware' => 'apiGuestCheck'], function () {
        Route::group(['prefix' => 'order'], function () {
            Route::controller(OrderController::class)->group(function () {
                Route::get('digital-product-download/{id}', 'digital_product_download');
                Route::get('digital-product-download-otp-verify', 'digital_product_download_otp_verify');
                Route::post('digital-product-download-otp-resend', 'digital_product_download_otp_resend');
            });
        });
    });

    Route::group(['prefix' => 'digital-payment', 'middleware' => 'apiGuestCheck'], function () {
        Route::post('/', [PaymentController::class, 'payment']);
    });

    Route::group(['prefix' => 'add-to-fund', 'middleware' => 'auth:api'], function () {
        Route::post('/', [PaymentController::class, 'customer_add_to_fund_request']);
    });

    Route::group(['prefix' => 'order'], function () {
        Route::controller(OrderController::class)->group(function () {
            Route::get('track', 'track_by_order_id');
            Route::get('track-order-details', 'track_order_details_history');
            Route::get('cancel-order', 'order_cancel')->middleware('auth:api');
            Route::post('track-order', 'track_order');
        });
    });

    Route::group(['prefix' => 'edit-order', 'middleware' => 'apiGuestCheck'], function () {
        Route::controller(OrderEditController::class)->group(function () {
            Route::post('due-payment-by-offline-payment', 'duePaymentByOfflinePayment');
            Route::post('due-payment-by-wallet', 'duePaymentByWallet');
            Route::post('due-payment-by-cod', 'duePaymentByCod');
            Route::post('due-payment-by-digital-payment', 'duePaymentByDigitalPayment');
        });
    });

    /*
     * The published theme, for the mobile app. Public because the storefront it mirrors is, and
     * throttled because it walks the theme structure per call; the renderer behind it caches per
     * page, so the throttle is about politeness, not protection.
     */
    Route::group(['prefix' => 'theme', 'middleware' => ['throttle:60,1']], function () {
        Route::controller(ThemeSectionController::class)->group(function () {
            Route::get('sections', 'sections')->name('api.v1.theme.sections');

            /*
             * The versioned pair the app syncs against. `version` is deliberately separate and
             * deliberately tiny: every cold start and every resume of every installed app asks
             * "has anything changed", and that question must not cost a home page to answer.
             */
            Route::get('home', 'home')->name('api.v1.theme.home');
            Route::get('version', 'version')->name('api.v1.theme.version');
        });
    });

    Route::group(['prefix' => 'banners'], function () {
        Route::controller(BannerController::class)->group(function () {
            Route::get('/', 'getBannerList');
            Route::get('category-sections', 'getCategorySectionBanners');
            Route::get('home-promos', 'getHomePromoBanners');
        });
    });

    Route::group(['prefix' => 'seller'], function () {
        Route::controller(SellerController::class)->group(function () {
            Route::get('/', 'get_seller_info');
            Route::get('list/{type}', 'getSellerList');
            Route::get('more', 'more_sellers');
        });
    });

    Route::group(['prefix' => 'coupon', 'middleware' => 'auth:api'], function () {
        Route::controller(CouponController::class)->group(function () {
            Route::get('apply', 'apply');
        });
    });


    Route::controller(CouponController::class)->group(function () {
        Route::get('coupon/list', 'list')->middleware('auth:api');
        Route::get('coupon/applicable-list', 'applicable_list')->middleware('auth:api');
        Route::get('coupons/{slug}/seller-wise-coupons', 'getSellerWiseCoupon');
    });

    //map api
    Route::group(['prefix' => 'mapapi'], function () {
        Route::controller(MapApiController::class)->group(function () {
            Route::get('place-api-autocomplete', 'placeApiAutocomplete');
            Route::get('distance-api', 'distanceApi');
            Route::get('place-api-details', 'placeApiDetails');
            Route::get('geocode-api', 'geocode_api');
        });
    });

    Route::controller(GeneralController::class)->group(function () {
        Route::get('faq', 'faq');
        Route::get('get-guest-id', 'get_guest_id');
        Route::post('contact-us', 'contact_store');
    });
});