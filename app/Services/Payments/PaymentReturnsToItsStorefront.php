<?php

namespace App\Services\Payments;

use App\Models\PaymentRequest;
use App\Services\Storefront\PaymentStorefront;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;

/**
 * The storefront a customer is returned to after the gateway: the one their payment was STARTED in (D2-14).
 *
 * A payment is already finished in the storefront it started in, whatever request the gateway's callback arrives
 * as -- that half is built and proved. The page the customer then LANDS on is not: `payment-success` and
 * `payment-fail` are unprefixed routes, so a Kuwaiti shopper who has just paid in dinars is answered the default
 * market's page, with its money, its legal texts and its catalogue.
 *
 * The storefront is read from the payment's own record, never from the request. That is the whole point of this
 * part of the platform: the request the gateway sends back carries nothing about the shop, and the browser that
 * follows it may be a new session entirely.
 *
 * **WHICH URL FORM IS THE OWNER'S OPEN DECISION, so only one of the two is built.**
 *
 *  - `query` appends `storefront=<code>`, which every route of the website already resolves
 *    ({@see StorefrontResolver}), so it needs no route, no redirect map and no change to a published URL;
 *  - the PATH-PREFIX form (`/kw/payment-success`) is deliberately NOT built. Emitting one means deciding the public
 *    URL shape of every return page on every market, and that is the decision D2-14 records as open. An
 *    unrecognised setting reads as `unchanged`, so a value typed in hope changes nothing.
 *
 * `unchanged` is the default and is today's URL exactly.
 */
#[Singleton]
final class PaymentReturnsToItsStorefront
{
    /** Today's behaviour: the return page is the one it has always been. */
    public const UNCHANGED = 'unchanged';

    /** `?storefront=<code>`, which the website already resolves on any route. */
    public const QUERY = 'query';

    public function form(): string
    {
        return (string) config('gcc.storefronts.payment_return.url_form') === self::QUERY ? self::QUERY : self::UNCHANGED;
    }

    /**
     * The extra route parameters that put the customer back in the storefront they paid in; empty when the form is
     * unchanged, when the payment recorded no storefront, or when what it recorded is not a country code.
     *
     * @return array<string, string>
     */
    public function parametersFor(PaymentRequest|array|null $payment): array
    {
        if ($this->form() !== self::QUERY) {
            return [];
        }

        $country = $this->countryOf(payment: $payment);

        return $country === null ? [] : ['storefront' => $country];
    }

    /** The storefront the payment recorded, or null for a payment that recorded none -- which is every payment while storefronts are off. */
    public function countryOf(PaymentRequest|array|null $payment): ?string
    {
        if ($payment === null) {
            return null;
        }

        $raw = is_array($payment) ? ($payment['additional_data'] ?? null) : $payment->additional_data;
        $additionalData = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $recorded = is_array($additionalData) ? ($additionalData[PaymentStorefront::KEY] ?? null) : null;

        return StorefrontResolver::fromConfig()->contextOfRecordedCountry(is_string($recorded) ? $recorded : null)?->country;
    }
}
