<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogUserBrowsingNavigationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): Response $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->ajax() && $request->isMethod('get')) {

            $appHost = parse_url(url('/'), PHP_URL_HOST);
            if ($request->getHost() !== $appHost) {
                return $next($request);
            }

            $currentUrl = $request->fullUrl();

            $excludedPatterns = [
                'admin/*',
                'vendor/*',
                'login/*',
                'customer/auth/*',
                'customer/reward-points/*',
                'vendor/auth/*',
                'user-account',
                'user-profile',
                'account-oder*',
                'account-order-details*',
                'track-order/order-wise-result-view',
                'user-restock-requests',
                'wishlists',
                'product-compare*',
                'wallet',
                'loyalty',
                'chat*',
                'chat/vendor*',
                'chat/delivery-man*',
                'account-address*',
                'account-tickets',
                'support-ticket*',
                'refer-earn',
                'user-coupons',
                'set-payment-method*',
                'set-shipping-method*',
                'web-payment*',
                'payment-success*',
                'payment-fail*',
                'payment*',
                'authentication-failed*',
                'coupon*',
                'https://www.google.com/*',
            ];
            if (getCheckAddonPublishedStatus(moduleName: 'Auction')) {
                $excludedPatterns = array_merge($excludedPatterns, [
                    'auction/bids/list',
                    'auction/bids/list*',
                    'auction/profile-view/product/*',
                    'auction/profile-view/author-product/*',
                    'auction/list',
                    'auction/auctions-request-list',
                    'auction/saved-products-list',
                    'auction/sales-report',
                    'auction/transaction-history',
                ]);
            }

            if (Schema::hasTable('business_settings')) {
                if (!getWebConfig('guest_checkout')) {
                    $excludedPatterns += [
                        'shop-cart*',
                        'checkout-details*',
                        'checkout-payment*',
                    ];
                }
            }

            $urls = session('recent_user_routes_history', []);
            $urls = array_values(array_filter($urls, fn($url) => $url !== $currentUrl));
            if (!$request->is($excludedPatterns)) {
                $urls[] = $currentUrl;
                session()->put('keep_customer_login_redirect_url', $currentUrl);
            }
            session()->put('recent_user_routes_history', array_slice($urls, -10));
        }

        return $next($request);
    }
}
