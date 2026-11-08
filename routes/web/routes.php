<?php

use App\Enums\ViewPaths\Web\ProductCompare;
use App\Enums\ViewPaths\Web\ShopFollower;
use App\Http\Controllers\Address\AddressCatalogController;
use App\Http\Controllers\Customer\Auth\CustomerAuthController;
use App\Http\Controllers\Customer\Auth\ForgotPasswordController;
use App\Http\Controllers\Customer\Auth\LoginController;
use App\Http\Controllers\Customer\Auth\RegisterController;
use App\Http\Controllers\Customer\Auth\SocialAuthController;
use App\Http\Controllers\Customer\PaymentController;
use App\Http\Controllers\Customer\RewardPointController;
use App\Http\Controllers\Customer\SystemController;
use App\Http\Controllers\Payment_Methods\BkashPaymentController;
use App\Http\Controllers\Payment_Methods\FlutterwaveV3Controller;
use App\Http\Controllers\Payment_Methods\LiqPayController;
use App\Http\Controllers\Payment_Methods\MercadoPagoController;
use App\Http\Controllers\Payment_Methods\MyFatoorahController;
use App\Http\Controllers\Payment_Methods\PaymobController;
use App\Http\Controllers\Payment_Methods\PaypalPaymentController;
use App\Http\Controllers\Payment_Methods\PaystackController;
use App\Http\Controllers\Payment_Methods\PaytabsController;
use App\Http\Controllers\Payment_Methods\PaymeraController;
use App\Http\Controllers\Payment_Methods\PaytmController;
use App\Http\Controllers\Payment_Methods\RazorPayController;
use App\Http\Controllers\Payment_Methods\SenangPayController;
use App\Http\Controllers\Payment_Methods\SslCommerzPaymentController;
use App\Http\Controllers\Payment_Methods\StripePaymentController;
use App\Http\Controllers\Web\CartController;
use App\Http\Controllers\Web\ChattingController;
use App\Http\Controllers\Web\CouponController;
use App\Http\Controllers\Web\CurrencyController;
use App\Http\Controllers\Web\DigitalProductDownloadController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\ProductCompareController;
use App\Http\Controllers\Web\ProductDetailsController;
use App\Http\Controllers\Web\ProductListController;
use App\Http\Controllers\Web\ReviewController;
use App\Http\Controllers\Web\Shop\ShopFollowerController;
use App\Http\Controllers\Web\ShopViewController;
use App\Http\Controllers\Web\UserLoyaltyController;
use App\Http\Controllers\Web\UserProfileController;
use App\Http\Controllers\Web\UserWalletController;
use App\Http\Controllers\Web\WebController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/
/*
 * Image proxy — hardened against SSRF.
 *
 * Previously this fetched ANY user-supplied URL and returned the full body with
 * Access-Control-Allow-Origin: *, i.e. an unauthenticated read-anything primitive: cloud
 * instance-metadata (169.254.169.254 -> IAM credentials), internal services, and cross-origin
 * exfiltration of the results. Now: scheme/destination validated by OutboundUrlGuard (public
 * addresses only), redirects disabled (a 302 to 169.254.169.254 would bypass the check),
 * image content-types only, a response-size cap, no wildcard CORS, and rate limited.
 */

Route::get('/image-proxy', function (\App\Services\Security\OutboundUrlGuard $guard) {
    $url = request('url');
    if (!$url) {
        abort(400, 'Missing url parameter');
    }

    $verdict = $guard->check($url);
    if (!$verdict['allowed']) {
        abort(403, 'URL not allowed');
    }

    try {
        $response = Http::withHeaders(['User-Agent' => 'Laravel-Image-Proxy'])
            ->withOptions(['allow_redirects' => false])   // a redirect could point back at private space
            ->timeout(5)
            ->get($url);
    } catch (\Throwable) {
        abort(502, 'Upstream fetch failed');
    }

    if (!$response->successful()) {
        abort(502, 'Upstream fetch failed');
    }

    $contentType = (string) $response->header('Content-Type');
    if (!str_starts_with(strtolower($contentType), 'image/')) {
        abort(415, 'Only image responses are proxied');
    }

    $body = $response->body();
    if (strlen($body) > 5 * 1024 * 1024) {
        abort(413, 'Image too large');
    }

    return response($body, 200)->header('Content-Type', $contentType);
})->middleware('throttle:30,1');

/*
| The analytics beacon.
|
| Public and unauthenticated, because the storefront is. It is safe to be so: only allow-listed
| event names are accepted, none of them carry money, and nothing the client sends is trusted for
| anything but a page identity. It always answers 204, so a prober learns nothing from it.
|
| The rate limit is PER IP, which is what an unnamed throttle does — not per visitor. On a network
| behind one address, an office or a campus, the limit is shared. That is the right trade for a
| beacon: keying on the visitor cookie would let anyone lift the ceiling by clearing it.
*/
Route::post(trim((string) config('analytics.beacon.path', 'analytics/collect'), '/'),
    \App\Http\Controllers\Web\AnalyticsCollectController::class)
    ->middleware('throttle:' . (int) config('analytics.beacon.rate_limit_per_minute', 60) . ',1')
    ->name('analytics.collect');

/*
| Campaign short links.
|
| Public and unauthenticated by design — these get printed on posters and pasted into WhatsApp.
| The redirect target is never taken from the request: it is a row an administrator created, whose
| destination is re-validated against the allow-list on every click, so this cannot become an open
| redirect. Throttled per IP because a short-link endpoint is a cheap thing to hammer.
*/
Route::get(trim((string) config('analytics.campaigns.path', 'go'), '/') . '/{code}',
    \App\Http\Controllers\Web\CampaignRedirectController::class)
    // Case-insensitive on purpose: codes are minted lowercase, and CampaignService folds case
    // on lookup, so a code read off a poster in capitals has to reach the controller rather
    // than being refused by the router before the folding can happen.
    ->where('code', '[A-Za-z0-9]{4,24}')
    ->middleware('throttle:' . (int) config('analytics.campaigns.click_rate_limit', 120) . ',1')
    ->name('campaign.go');

Route::controller(WebController::class)->group(function () {
    Route::get('maintenance-mode', 'maintenance_mode')->name('maintenance-mode');
});

Route::group(['prefix' => 'address', 'as' => 'address.', 'middleware' => ['throttle:120,1,address-catalog']], function () {
    Route::get('regions', [AddressCatalogController::class, 'regions'])->name('regions');
    Route::get('cities', [AddressCatalogController::class, 'cities'])->name('cities');
});

Route::group(['namespace' => 'Web', 'middleware' => ['maintenance_mode', 'guestCheck']], function () {
    Route::group(['prefix' => 'product-compare', 'as' => 'product-compare.'], function () {
        Route::controller(ProductCompareController::class)->group(function () {
            Route::get(ProductCompare::INDEX[URI], 'index')->name('index')->middleware('customer');
            Route::post(ProductCompare::INDEX[URI], 'add');
            Route::get(ProductCompare::DELETE[URI], 'delete')->name('delete');
            Route::get(ProductCompare::DELETE_ALL[URI], 'deleteAllCompareProduct')->name('delete-all');
        });
    });
    Route::post(ShopFollower::SHOP_FOLLOW[URI], [ShopFollowerController::class, 'followOrUnfollowShop'])->name('shop-follow');
});

Route::group(['namespace' => 'Web', 'middleware' => ['maintenance_mode', 'guestCheck']], function () {

    Route::controller(HomeController::class)->group(function () {
        Route::get('/', 'index')->name('home');
    });

    Route::controller(WebController::class)->group(function () {
        Route::get('quick-view', 'getQuickView')->name('quick-view');
        Route::get('searched-products', 'getSearchedProducts')->name('searched-products');
    });

    Route::group(['middleware' => ['customer']], function () {
        Route::controller(ReviewController::class)->group(function () {
            Route::post('review', 'add')->name('review.store');
            Route::post('submit-deliveryman-review', 'addDeliveryManReview')->name('submit-deliveryman-review');
            Route::post('review-delete-image', 'deleteReviewImage')->name('delete-review-image');
        });
    });

    Route::controller(WebController::class)->group(function () {
        Route::get('checkout-details', 'checkout_details')->name('checkout-details');
        Route::get('checkout-payment', 'checkout_payment')->name('checkout-payment');
        // POST, not GET: these two place orders. As GETs they were CSRF-exposed
        // and a link prefetch could create an order.
        Route::post('checkout-complete', 'getCashOnDeliveryCheckoutComplete')->name('checkout-complete');
        Route::post('offline-payment-checkout-complete', 'getOfflinePaymentCheckoutComplete')->name('offline-payment-checkout-complete');
        Route::get('order-placed', 'order_placed')->name('order-placed');
        Route::get('order-placed-success', 'getOrderPlaceView')->name('order-placed-success');
        Route::get('shop-cart', 'shop_cart')->name('shop-cart');
        Route::post('order_note', 'order_note')->name('order_note');
        Route::get('digital-product-download/{id}', 'getDigitalProductDownload')->name('digital-product-download');
        Route::post('digital-product-download-otp-verify', 'getDigitalProductDownloadOtpVerify')->name('digital-product-download-otp-verify');
        Route::post('digital-product-download-otp-reset', 'getDigitalProductDownloadOtpReset')->name('digital-product-download-otp-reset');
        Route::get('pay-offline-method-list', 'pay_offline_method_list')->name('pay-offline-method-list')->middleware('guestCheck');
        Route::post('checkout-complete-wallet', 'checkout_complete_wallet')->name('checkout-complete-wallet');

        Route::post('subscription', 'subscription')->name('subscription');
        // The all-vendors action, which already filters on shop_name. It used to point at a
        // search_shop() that handed the store list a `$sellers` variable the view stopped reading —
        // so this URL was a 500 for every visitor and every input, not only a malformed one.
        Route::get('search-shop', 'getAllVendorsView')->name('search-shop');

        Route::get('categories', 'getAllCategoriesView')->name('categories');
        Route::get('category-ajax/{id}', 'categories_by_category')->name('category-ajax');

        Route::get('brands', 'getAllBrandsView')->name('brands');
        Route::get('vendors', 'getAllVendorsView')->name('vendors');
        Route::get('seller-profile/{id}', 'seller_profile')->name('seller-profile');
    });

    Route::controller(PageController::class)->group(function () {
        Route::get('business-page/{slug}', 'getPageView')->name('business-page.view');
        Route::get('contacts', 'getContactView')->name('contacts');
        Route::get('helpTopic', 'getHelpTopicView')->name('helpTopic');
    });

    /*
     * A page the merchant composed in the App Builder. Prefixed so it can never collide with a
     * product slug or a category slug — those own their own namespaces, and a custom page that
     * shadowed one would be a shop losing a product to a typo.
     */
    Route::get('p/{slug}', [\App\Http\Controllers\Web\ExperiencePageController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('experience-page');

    Route::controller(ProductDetailsController::class)->group(function () {
        Route::get('/product/{slug}', 'index')->name('product');
    });

    Route::controller(ProductListController::class)->group(function () {
        Route::get('products', 'products')->name('products');
        Route::get('flash-deals/{id}', 'getFlashDealsView')->name('flash-deals');
        Route::post('flash-deals/{id}', 'getFlashDealsProducts');

        Route::get('brand/{slug}', 'getBrandProductsView')->name('brand-products');
        Route::get('category/{slug}', 'getCategoryProductsView')->name('category-products');
        Route::get('featured-products', 'getFeaturedProductsView')->name('featured-products');
        Route::get('featured-deal-products', 'getFeaturedDealProductsView')->name('featured-deal-products');
        Route::get('latest-products', 'getLatestProductsView')->name('latest-products');
        Route::get('best-selling-products', 'getBestSellingProductsView')->name('best-selling-products');
        Route::get('top-rated-products', 'getTopRatedProductsView')->name('top-rated-products');
        Route::get('most-favorite-products', 'getMostFavoriteProductsView')->name('most-favorite-products');
        Route::get('discounted-products', 'getDiscountedProductsView')->name('discounted-products');
        Route::get('clearance-sale-products', 'getClearanceSaleProductsView')->name('clearance-sale-products');
    });

    Route::controller(ShopViewController::class)->group(function () {
        Route::post('ajax-filter-products', 'filterProductsAjaxResponse')->name('ajax-filter-products');
    });

    Route::controller(WebController::class)->group(function () {
        Route::post('/products-view-style', 'product_view_style')->name('product_view_style');
        Route::post('review-list-product', 'review_list_product')->name('review-list-product');
        Route::post('review-list-shop', 'getShopReviewList')->name('review-list-shop'); // theme fashion
        Route::get('wishlists', 'viewWishlist')->name('wishlists')->middleware('customer');
        Route::post('store-wishlist', 'storeWishlist')->name('store-wishlist');
        Route::post('delete-wishlist', 'deleteWishlist')->name('delete-wishlist');
        Route::get('delete-wishlist-all', 'deleteAllWishListItems')->name('delete-wishlist-all')->middleware('customer');
        Route::get('searched-products-for-compare', 'getSearchedProductsForCompareList')->name('searched-products-compare'); // theme fashion compare list
    });

    Route::controller(CurrencyController::class)->group(function () {
        Route::post('/currency', 'changeCurrency')->name('currency.change');
    });

    Route::controller(UserProfileController::class)->group(function () {
        Route::group(['prefix' => 'support-ticket', 'as' => 'support-ticket.', 'middleware' => 'customer'], function () {
            Route::get('{id}', 'single_ticket')->name('index');
            Route::post('{id}', 'comment_submit')->name('comment');
            Route::get('delete/{id}', 'support_ticket_delete')->name('delete');
            Route::get('close/{id}', 'support_ticket_close')->name('close');
        });
    });

    Route::controller(UserProfileController::class)->group(function () {
        Route::group(['prefix' => 'track-order', 'as' => 'track-order.'], function () {
            Route::get('', 'track_order')->name('index');
            Route::get('result-view', 'track_order_result')->name('result-view');
            Route::get('last', 'track_last_order')->name('last');
            Route::any('result', 'track_order_result')->name('result');
            Route::get('order-wise-result-view', 'track_order_wise_result')->name('order-wise-result-view');
        });
    });

    Route::controller(UserProfileController::class)->group(function () {
        Route::redirect('user-profile', '/user-account')->name('user-profile');
        Route::get('user-account', 'user_account')->name('user-account')->middleware('customer');
        Route::post('user-account-update', 'getUserProfileUpdate')->name('user-update')->middleware('customer');
        Route::get('account-address-add', 'account_address_add')->name('account-address-add');
        Route::get('account-address', 'account_address')->name('account-address');
        Route::post('account-address-store', 'address_store')->name('address-store');
        Route::get('account-address-delete', 'address_delete')->name('address-delete');
        ROute::get('account-address-edit/{id}', 'address_edit')->name('address-edit');
        Route::post('account-address-update', 'address_update')->name('address-update');
        Route::get('account-payment', 'account_payment')->name('account-payment');
        Route::get('account-oder', 'account_order')->name('account-oder')->middleware('customer');
        Route::get('account-order-details', 'account_order_details')->name('account-order-details')->middleware('customer');
        Route::get('account-order-details-vendor-info', 'account_order_details_seller_info')->name('account-order-details-vendor-info')->middleware('customer');
        Route::get('account-order-details-delivery-man-info', 'account_order_details_delivery_man_info')->name('account-order-details-delivery-man-info')->middleware('customer');
        Route::get('account-order-details-reviews', 'getAccountOrderDetailsReviewsView')->name('account-order-details-reviews')->middleware('customer');
        // No 'customer' middleware: the order-success modal links here for GUEST checkouts too, so this
        // must stay reachable without a customer login. generate_invoice() enforces owner-only access
        // itself (logged-in customer by customer_id, guest by their session guest_id).
        Route::get('generate-invoice/{id}', 'generate_invoice')->name('generate-invoice');
        Route::get('account-wishlist', 'account_wishlist')->name('account-wishlist'); //add to card not work
        Route::get('refund-request/{id}', 'refund_request')->name('refund-request')->middleware('customer');
        Route::get('refund-details/{id}', 'refund_details')->name('refund-details')->middleware('customer');
        Route::post('refund-store', 'store_refund')->name('refund-store')->middleware('customer');
        Route::get('account-tickets', 'account_tickets')->name('account-tickets');
        Route::get('order-cancel/{id}', 'order_cancel')->name('order-cancel')->middleware('customer');
        Route::post('ticket-submit', 'submitSupportTicket')->name('ticket-submit');
        Route::get('account-delete/{id}', 'account_delete')->name('account-delete')->middleware('customer');
        Route::get('refer-earn', 'refer_earn')->name('refer-earn')->middleware('customer');
        Route::get('user-coupons', 'user_coupons')->name('user-coupons')->middleware('customer');
        Route::get('user-restock-requests', 'restockRequestsView')->name('user-restock-requests')->middleware('customer');
        Route::get('user-restock-request-delete', 'deleteRestockRequest')->name('user-restock-request-delete')->middleware('customer');
    });

    Route::controller(ChattingController::class)->group(function () {
        Route::get('chat/{type}', 'index')->name('chat')->middleware('customer');
        Route::get('message', 'getMessageByUser')->name('messages');
        Route::post('message', 'addMessage');
    });

    Route::controller(UserWalletController::class)->group(function () {
        Route::get('wallet-account', 'myWalletAccount')->name('wallet-account'); //theme fashion
        Route::get('wallet', 'index')->name('wallet')->middleware('customer');
    });

    Route::controller(UserLoyaltyController::class)->group(function () {
        Route::get('loyalty', 'index')->name('loyalty')->middleware('customer');
        Route::post('loyalty-exchange-currency', 'getLoyaltyExchangeCurrency')->name('loyalty-exchange-currency');
        Route::get('ajax-loyalty-currency-amount', 'getLoyaltyCurrencyAmount')->name('ajax-loyalty-currency-amount');
    });

    Route::controller(DigitalProductDownloadController::class)->group(function () {
        Route::group(['prefix' => 'digital-product-download-pos', 'as' => 'digital-product-download-pos.'], function () {
            Route::get('/', 'index')->name('index');
        });
    });

    Route::controller(ShopViewController::class)->group(function () {
        Route::get('vendor-shop/{slug}', 'seller_shop')->name('vendor-shop');
        Route::get('ajax-shop-vacation-check', 'ajax_shop_vacation_check')->name('ajax-shop-vacation-check');
    });

    Route::controller(WebController::class)->group(function () {
        Route::post('vendor-shop/{id}', 'seller_shop_product');
    });

    Route::group(['prefix' => 'contact', 'as' => 'contact.'], function () {
        Route::controller(WebController::class)->group(function () {
            Route::post('store', 'contact_store')->name('store');
        });
    });
});

Route::group(['prefix' => 'cart', 'as' => 'cart.', 'namespace' => 'Web'], function () {
    Route::controller(CartController::class)->group(function () {
        Route::post('variant_price', 'getVariantPrice')->name('variant_price');
        Route::post('add', 'addToCart')->name('add');
        Route::post('add-all-to-cart', 'addAllToCartFromWishtList')->name('add-all-to-cart');
        Route::post('update-variation', 'update_variation')->name('update-variation'); //theme fashion
        Route::post('remove', 'removeFromCart')->name('remove');
        Route::get('remove-all', 'remove_all_cart')->name('remove-all'); //theme fashion
        Route::post('nav-cart-items', 'updateNavCart')->name('nav-cart');
        Route::post('floating-nav-cart-items', 'update_floating_nav')->name('floating-nav-cart-items'); // theme fashion floating nav
        Route::post('updateQuantity', 'updateQuantity')->name('updateQuantity');
        Route::post('updateQuantity-guest', 'updateQuantity_guest')->name('updateQuantity.guest');
        Route::post('order-again', 'orderAgain')->name('order-again')->middleware('customer');
        Route::post('select-cart-items', 'updateCheckedCartItems')->name('select-cart-items');
        Route::post('product-restock-request', 'addProductRestockRequest')->name('product-restock-request');
    });

    // The link inside the abandoned-cart email. `signed` is what makes the reminder id in the URL
    // safe to trust: without it anyone could walk the sequence and mark other people's reminders.
    Route::get('recover/{reminder}', [\App\Http\Controllers\Web\AbandonedCartRecoveryController::class, 'recover'])
        ->name('recover')
        ->middleware('signed');
});

Route::group(['prefix' => 'coupon', 'as' => 'coupon.', 'namespace' => 'Web'], function () {
    Route::controller(CouponController::class)->group(function () {
        Route::post('apply', 'apply')->name('apply');
        Route::get('remove', 'removeCoupon')->name('remove');
    });
});

Route::get('authentication-failed', function () {
    $errors = [];
    array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
    return response()->json([
        'errors' => $errors
    ], 401);
})->name('authentication-failed');

Route::group(['namespace' => 'Customer', 'prefix' => 'customer', 'as' => 'customer.'], function () {

    Route::group(['namespace' => 'Auth', 'prefix' => 'auth', 'as' => 'auth.'], function () {

        Route::controller(CustomerAuthController::class)->group(function () {
            Route::get('login', 'loginView')->name('login');
            Route::post('login', 'loginSubmit')->middleware('throttle:auth');
            Route::get('login/verify-account', 'loginVerifyPhone')->name('login.verify-account');
            Route::post('login/verify-account/submit', 'verifyAccount')->name('login.verify-account.submit');
            Route::get('login/update-info', 'updateInfo')->name('login.update-info');
            Route::post('login/update-info', 'updateInfoSubmit');
            Route::post('login/resend-otp-code', 'resendOTPCode')->name('resend-otp-code');
        });

        Route::controller(LoginController::class)->group(function () {
            Route::get('logout', 'logout')->name('logout');
            Route::get('get-login-modal-data', 'getLoginModalView')->name('get-login-modal-data');
        });

        Route::controller(RegisterController::class)->group(function () {
            Route::get('sign-up', 'getRegisterView')->name('sign-up');
            Route::post('sign-up', 'submitRegisterData');
            Route::get('check-verification', 'verificationCheckView')->name('check-verification');
            Route::post('verify', 'verifyRegistration')->name('verify');
            Route::post('ajax-verify', 'ajax_verify')->name('ajax_verify');
            Route::post('resend-otp', 'resendOTPToCustomer')->name('resend_otp');
        });

        Route::controller(SocialAuthController::class)->group(function () {
            Route::get('login/{service}', 'redirectToProvider')->name('service-login');
            Route::get('login/{service}/callback', 'handleProviderCallback')->name('service-callback');
            Route::get('login/social/confirmation', 'socialLoginConfirmation')->name('social-login-confirmation');
            Route::post('login/social/confirmation/update', 'updateSocialLoginConfirmation')->name('social-login-confirmation.update');
            Route::post('login/social/verify-account', 'verifyAccount')->name('login.social.verify-account');
        });

        Route::controller(ForgotPasswordController::class)->group(function () {
            Route::get('recover-password', 'reset_password')->name('recover-password');
            Route::post('forgot-password', 'resetPasswordRequest')->name('forgot-password');
            Route::post('verify-recover-password', 'verifyRecoverPassword')->name('verify-recover-password');
            Route::get('otp-verification', 'otp_verification')->name('otp-verification');
            Route::post('otp-verification', 'otp_verification_submit');
            Route::get('reset-password', 'resetPasswordView')->name('reset-password');
            Route::post('reset-password', 'resetPasswordSubmit')->name('password-recovery');
            Route::post('resend-otp-reset-password', 'resendPhoneOTPRequest')->name('resend-otp-reset-password');
        });
    });

    Route::controller(SystemController::class)->group(function () {
        Route::get('set-payment-method/{name}', 'setPaymentMethod')->name('set-payment-method');
        Route::get('set-shipping-method', 'setShippingMethod')->name('set-shipping-method');
        Route::post('choose-shipping-address', 'getChooseShippingAddress')->name('choose-shipping-address');
        Route::post('choose-shipping-address-other', 'getChooseShippingAddressOther')->name('choose-shipping-address-other');
    });

    Route::group(['prefix' => 'reward-points', 'as' => 'reward-points.', 'middleware' => ['auth:customer']], function () {
        Route::get('convert', [RewardPointController::class, 'convert'])->name('convert');
    });
});

Route::group(['namespace' => 'Customer', 'prefix' => 'customer', 'as' => 'customer.'], function () {
    Route::controller(PaymentController::class)->group(function () {
        Route::post('web-payment-request', 'payment')->name('web-payment-request');
        Route::post('customer-add-fund-request', 'customer_add_to_fund_request')->name('add-fund-request');
        Route::post('customer-order-edit-pay-amount', 'customerOrderEditPayDueAmount')->name('customer-order-edit-pay-amount');
    });
});

Route::controller(PaymentController::class)->group(function () {
    Route::get('web-payment', 'web_payment_success')->name('web-payment-success');
    Route::get('payment-success', 'success')->name('payment-success');
    Route::get('payment-fail', 'fail')->name('payment-fail');
});

$isGatewayPublished = 0;
try {
    if (file_exists(base_path('Modules/Gateways/Addon/info.php'))) {
        $gatewayInfoData = include(base_path('Modules/Gateways/Addon/info.php'));
        $isGatewayPublished = $gatewayInfoData['is_published'] == 1 ? 1 : 0;
    }
} catch (Exception $exception) {
}

if (!$isGatewayPublished) {
    Route::group(['prefix' => 'payment'], function () {

        //SSLCOMMERZ
        Route::group(['prefix' => 'sslcommerz', 'as' => 'sslcommerz.'], function () {
            Route::get('pay', [SslCommerzPaymentController::class, 'index'])->name('pay');
            Route::post('success', [SslCommerzPaymentController::class, 'success'])
                ->withoutMiddleware([VerifyCsrfToken::class]);
            Route::post('failed', [SslCommerzPaymentController::class, 'failed'])
                ->withoutMiddleware([VerifyCsrfToken::class]);
            Route::post('canceled', [SslCommerzPaymentController::class, 'canceled'])
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });

        //STRIPE
        Route::group(['prefix' => 'stripe', 'as' => 'stripe.'], function () {
            Route::get('pay', [StripePaymentController::class, 'index'])->name('pay');
            Route::get('token', [StripePaymentController::class, 'payment_process_3d'])->name('token');
            Route::get('success', [StripePaymentController::class, 'success'])->name('success');
        });

        //RAZOR-PAY
        Route::group(['prefix' => 'razor-pay', 'as' => 'razor-pay.'], function () {
            Route::get('pay', [RazorPayController::class, 'index']);
            Route::post('payment', [RazorPayController::class, 'payment'])->name('payment')
                ->withoutMiddleware([VerifyCsrfToken::class]);
            Route::post('callback', [RazorPayController::class, 'callback'])->name('callback')
                ->withoutMiddleware([VerifyCsrfToken::class]);
            Route::any('cancel', [RazorPayController::class, 'cancel'])->name('cancel')
                ->withoutMiddleware([VerifyCsrfToken::class]);

            Route::any('create-order', [RazorPayController::class, 'createOrder'])->name('create-order')
                ->withoutMiddleware([VerifyCsrfToken::class]);
            Route::any('verify-payment', [RazorPayController::class, 'verifyPayment'])->name('verify-payment')
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });

        //PAYPAL
        Route::group(['prefix' => 'paypal', 'as' => 'paypal.'], function () {
            Route::get('pay', [PaypalPaymentController::class, 'payment']);
            Route::any('success', [PaypalPaymentController::class, 'success'])->name('success')
                ->withoutMiddleware([VerifyCsrfToken::class]);
            Route::any('cancel', [PaypalPaymentController::class, 'cancel'])->name('cancel')
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });

        //SENANG-PAY
        Route::group(['prefix' => 'senang-pay', 'as' => 'senang-pay.'], function () {
            Route::get('pay', [SenangPayController::class, 'index']);
            Route::any('callback', [SenangPayController::class, 'return_senang_pay']);
        });

        //PAYTM
        Route::group(['prefix' => 'paytm', 'as' => 'paytm.'], function () {
            Route::get('pay', [PaytmController::class, 'payment']);
            Route::any('response', [PaytmController::class, 'callback'])->name('response')
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });

        //FLUTTERWAVE
        Route::group(['prefix' => 'flutterwave-v3', 'as' => 'flutterwave-v3.'], function () {
            Route::get('pay', [FlutterwaveV3Controller::class, 'initialize'])->name('pay');
            Route::get('callback', [FlutterwaveV3Controller::class, 'callback'])->name('callback');
        });

        //PAYSTACK
        Route::group(['prefix' => 'paystack', 'as' => 'paystack.'], function () {
            Route::get('pay', [PaystackController::class, 'index'])->name('pay');
            Route::get('callback', [PaystackController::class, 'handleGatewayCallback'])->name('callback');
            Route::get('cancel', [PaystackController::class, 'cancel'])->name('cancel');
        });

        //BKASH
        Route::group(['prefix' => 'bkash', 'as' => 'bkash.'], function () {
            // Payment Routes for bKash
            Route::get('make-payment', [BkashPaymentController::class, 'make_tokenize_payment'])->name('make-payment');
            Route::any('callback', [BkashPaymentController::class, 'callback'])->name('callback');
        });

        //Liqpay
        Route::group(['prefix' => 'liqpay', 'as' => 'liqpay.'], function () {
            Route::get('payment', [LiqPayController::class, 'payment'])->name('payment');
            Route::any('callback', [LiqPayController::class, 'callback'])->name('callback');
        });

        //MERCADOPAGO
        Route::group(['prefix' => 'mercadopago', 'as' => 'mercadopago.'], function () {
            Route::get('pay', [MercadoPagoController::class, 'index'])->name('index');
            Route::post('make-payment', [MercadoPagoController::class, 'make_payment'])->name('make_payment');
            Route::any('callback', [MercadoPagoController::class, 'callback'])->name('callback');
        });

        //PAYMOB
        Route::group(['prefix' => 'paymob', 'as' => 'paymob.'], function () {
            Route::any('pay', [PaymobController::class, 'credit'])->name('pay');
            Route::any('callback', [PaymobController::class, 'callback'])->name('callback');
        });

        //PAYTABS
        Route::group(['prefix' => 'paytabs', 'as' => 'paytabs.'], function () {
            Route::any('pay', [PaytabsController::class, 'payment'])->name('pay');
            Route::any('callback', [PaytabsController::class, 'callback'])->name('callback');
            Route::any('response', [PaytabsController::class, 'response'])->name('response');
        });

        /*
         * MYFATOORAH (Saudi Arabia)
         *
         * `pay` reads the amount from the payment_requests row — never from the request — and redirects
         * to MyFatoorah's hosted page. `callback` is where the customer comes back; it carries only our
         * own reference and MyFatoorah's paymentId, and the controller verifies the outcome
         * server-to-server before showing anything. Nothing here is CSRF-exempt because both are GETs
         * from an external redirect, and neither changes state on the strength of its parameters.
         *
         * The `fatoorah` prefix is kept as an alias: it is the path the Payment trait has always
         * generated, so an old link, a queued job or an installed app build that still points at it
         * keeps working.
         */
        Route::group(['prefix' => 'myfatoorah', 'as' => 'myfatoorah.'], function () {
            Route::get('pay', [MyFatoorahController::class, 'pay'])->name('pay');
            Route::get('callback', [MyFatoorahController::class, 'callback'])->name('callback');
            // "We are confirming your payment". Its own page because the shared success/fail helper
            // cannot express "we do not know yet", and guessing either way is how a paid customer is
            // told their payment failed.
            Route::get('pending', [MyFatoorahController::class, 'pending'])->name('pending');
        });

        Route::group(['prefix' => 'fatoorah', 'as' => 'fatoorah.'], function () {
            Route::get('pay', [MyFatoorahController::class, 'pay'])->name('pay');
            Route::get('callback', [MyFatoorahController::class, 'callback'])->name('callback');
            Route::get('pending', [MyFatoorahController::class, 'pending'])->name('pending');
        });



// MTN CASH
Route::group(['prefix' => 'mtn-cash', 'as' => 'mtn-cash.'], function () {





Route::get('check-invoice', [
    \App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class,
    'checkInvoice'
])->name('check-invoice');



    Route::get('pay', [
        \App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class,
        'payment'
    ])->name('pay');

    Route::post('initiate', [
        \App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class,
        'initiate'
    ])->name('initiate');

    Route::post('confirm', [
        \App\Http\Controllers\Payment_Methods\MtnCashPaymentController::class,
        'confirm'
    ])->name('confirm');
});


        //PAYMERA
        Route::group(['prefix' => 'paymera', 'as' => 'paymera.'], function () {
            Route::any('pay', [PaymeraController::class, 'index'])->name('pay');
            // callbackURL == triggerURL. Paymera's server-to-server trigger carries no CSRF token, so a
            // POST trigger would be 419'd and only the browser return would finalize — exempt it like the
            // other gateway callbacks (sslcommerz/stripe/…).
            Route::any('callback', [PaymeraController::class, 'callback'])->name('callback')
                ->withoutMiddleware([VerifyCsrfToken::class]);
        });
    });
}