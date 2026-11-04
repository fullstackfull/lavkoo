<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        '/pay-via-ajax', '/success', '/cancel', '/fail', '/ipn', '/bkash/*',
        '/paytabs-response', '/customer/choose-shipping-address', '/system_settings',
        '/paytm*', 'payment/paytabs/callback*',
        // The analytics beacon. navigator.sendBeacon cannot set a header, so a CSRF token cannot
        // reach this route — it is protected by an Origin check, an event-name allow-list that
        // contains nothing money-related, and a per-visitor rate limit instead.
        'analytics/collect',
    ];
}
