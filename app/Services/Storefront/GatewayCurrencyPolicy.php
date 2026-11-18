<?php

namespace App\Services\Storefront;

use App\Exceptions\StorefrontPaymentNotSupportedException;
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

    /** The names the same gateway is stored under. */
    private const GATEWAY_ALIASES = ['my_fatoorah' => 'fatoorah'];

    public function __construct(private readonly StorefrontCurrency $storefrontCurrency)
    {
    }

    public function assertAcceptable(string $gateway, string $currencyCode): void
    {
        $storefrontCurrency = $this->storefrontCurrency->currencyCodeInForce();

        if ($storefrontCurrency === null) {
            return;
        }

        $gateway = self::GATEWAY_ALIASES[$gateway] ?? $gateway;
        $currency = strtoupper($currencyCode);

        if ($currency === $storefrontCurrency && $this->isSupported(gateway: $gateway, currency: $currency) && $this->hasUsableDecimals(gateway: $gateway, currency: $currency)) {
            return;
        }

        throw new StorefrontPaymentNotSupportedException(gateway: $gateway, currencyCode: $currency);
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
