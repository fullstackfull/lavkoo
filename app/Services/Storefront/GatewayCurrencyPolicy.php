<?php

namespace App\Services\Storefront;

use App\Exceptions\StorefrontPaymentNotSupportedException;
use App\Services\Payments\PaymentGatewayCatalog;
use App\Traits\PaymentGatewayTrait;
use Illuminate\Container\Attributes\Singleton;

/**
 * Whether a payment gateway may take the money of a storefront that trades in its own currency (GCC-05). The amount of such a storefront is
 * in its own currency, so the gateway has to take that very currency: a payment is never converted to dollars, and never goes to a gateway
 * whose list of currencies does not have it. A currency with three decimals (KWD, BHD, OMR) goes only to a gateway that somebody has verified
 * for it (gcc.storefronts.currency.verified_three_decimal_gateways, empty at first), because most gateways multiply the amount by one hundred
 * and would ask for a tenth of the price. Every payment starts in Payment::generate_link(), which asks first.
 *
 * Nothing is refused while storefront currencies are off, for the default storefront, or for a request that is not served from a storefront.
 */
#[Singleton]
final class GatewayCurrencyPolicy
{
    use PaymentGatewayTrait;

    public function __construct(private readonly StorefrontCurrency $storefrontCurrency)
    {
    }

    public function assertAcceptable(string $gateway, string $currencyCode): void
    {
        if ($this->accepts(gateway: $gateway, currencyCode: $currencyCode)) {
            return;
        }

        throw new StorefrontPaymentNotSupportedException(
            gateway: PaymentGatewayCatalog::canonicalKey($gateway),
            currencyCode: strtoupper($currencyCode),
        );
    }

    /**
     * The same question as an answer rather than an exception, for the callers that LIST what a storefront may offer instead of starting
     * one payment (the config answer of the customer app, J-09.4). One rule, asked two ways: a method the list offers is a method
     * `assertAcceptable` would let through, and a method it hides is one that would have been refused at the customer's checkout.
     */
    public function accepts(string $gateway, string $currencyCode): bool
    {
        $storefrontCurrency = $this->storefrontCurrency->currencyCodeInForce();

        if ($storefrontCurrency === null) {
            return true;
        }

        // The spellings of one gateway are declared in one place, so a check here and a route there can never disagree about which
        // gateway a key names (PaymentGatewayCatalog).
        $gateway = PaymentGatewayCatalog::canonicalKey($gateway);
        $currency = strtoupper($currencyCode);

        return $currency === $storefrontCurrency
            && $this->isSupported(gateway: $gateway, currency: $currency)
            && $this->hasUsableDecimals(gateway: $gateway, currency: $currency);
    }

    private function isSupported(string $gateway, string $currency): bool
    {
        return array_key_exists($currency, $this->getPaymentGatewaySupportedCurrencies(key: $gateway));
    }

    private function hasUsableDecimals(string $gateway, string $currency): bool
    {
        return $this->storefrontCurrency->decimalsOf($currency) === 2
            || in_array($gateway, (array) config('gcc.storefronts.currency.verified_three_decimal_gateways'), true);
    }
}
