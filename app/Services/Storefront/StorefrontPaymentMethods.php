<?php

namespace App\Services\Storefront;

use App\Exceptions\StorefrontPaymentMethodNotOfferedException;
use Illuminate\Container\Attributes\Singleton;
use InvalidArgumentException;

/**
 * Which of the three ways to pay a storefront offers (requirement D2-V2): cash on delivery, digital payment and offline payment. The platform switches each of them
 * on and off with a business setting of the same name, for all six markets at once. A country may switch any of them off for its own storefront
 * (gcc.storefronts.payment_methods.switched_off) and can do nothing else: a method the platform has off stays off in every storefront.
 *
 * It is the one place that knows. getWebConfig() asks it for the three settings, so every reader of them (the checkout, the account pages, the answer to the apps) is
 * told "switched off" in a storefront that has switched the method off, without a change of its own. The few readers that never looked at the status ask
 * isSwitchedOff(), and the routes that take an order or start a payment by one of the three refuse it there before anything is written (storefront.method).
 *
 * While the switch is off, for a country that lists nothing, and for a request that is served from no storefront (a panel, a seller API, a queue, the console), the
 * platform's own settings are read as they always were.
 */
#[Singleton]
final class StorefrontPaymentMethods
{
    public const CASH_ON_DELIVERY = 'cash_on_delivery';

    public const DIGITAL_PAYMENT = 'digital_payment';

    public const OFFLINE_PAYMENT = 'offline_payment';

    /** The business settings that switch a way to pay on and off. Each is stored as {"status": 0|1}. */
    public const METHODS = [self::CASH_ON_DELIVERY, self::DIGITAL_PAYMENT, self::OFFLINE_PAYMENT];

    private const SWITCHED_OFF = ['status' => 0];

    /** What a customer chooses to pay from the wallet, which no storefront switches off here: the wallet has its own setting. */
    private const WALLET = 'wallet';

    private bool $readsThePlatform = false;

    /**
     * What a reader of the method's setting is told: the platform's own setting, with its status off when the storefront of the request has switched the method off.
     * A platform that holds no setting for the method has nothing to switch off, so a storefront never turns a missing setting into one.
     */
    public function settingInStorefront(string $method, mixed $platformSetting): mixed
    {
        return is_array($platformSetting) && $this->isSwitchedOff(method: $method)
            ? self::SWITCHED_OFF + $platformSetting
            : $platformSetting;
    }

    /**
     * The platform's own setting, whatever storefront the request is served from: for a reader that judges the platform and not what one storefront offers. The readiness
     * of a gateway is one: it is also asked on the gateway's own page, which names no storefront, for a payment that another storefront started.
     */
    public function platformSetting(string $method): mixed
    {
        $this->readsThePlatform = true;

        try {
            return getWebConfig(name: $method);
        } finally {
            $this->readsThePlatform = false;
        }
    }

    /** Whether the storefront of the request has switched the method off. Never while the switch is off, nor for a request that is served from no storefront. */
    public function isSwitchedOff(string $method): bool
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("[{$method}] is not a way to pay that a storefront can switch off.");
        }

        if ($this->readsThePlatform || !(bool) config('gcc.storefronts.payment_methods.enabled')) {
            return false;
        }

        $country = StorefrontCatalog::country();

        return $country !== null && in_array($method, $this->switchedOffBy(country: $country), true);
    }

    /** Refuses a method the storefront of the request has switched off. An order and a payment ask before anything is written. */
    public function assertNotSwitchedOff(string $method): void
    {
        if ($this->isSwitchedOff(method: $method)) {
            throw new StorefrontPaymentMethodNotOfferedException(method: $method, countryCode: (string) StorefrontCatalog::country());
        }
    }

    /**
     * Refuses what a customer chose at a payment step when the storefront of the request has switched it off. Cash on delivery and offline payment are chosen by their
     * names, the wallet is none of the three, and anything else is the name of a gateway, which is a digital payment.
     */
    public function assertChoiceIsNotSwitchedOff(string $choice): void
    {
        $method = match ($choice) {
            self::CASH_ON_DELIVERY, self::OFFLINE_PAYMENT => $choice,
            self::WALLET => null,
            default => self::DIGITAL_PAYMENT,
        };

        if ($method !== null) {
            $this->assertNotSwitchedOff(method: $method);
        }
    }

    /**
     * A name that is not one of the three would leave a method on where it was meant to be off, so it fails loudly, and only for the storefront that lists it.
     *
     * @return list<string> the methods the country has switched off
     */
    private function switchedOffBy(string $country): array
    {
        $byCountry = array_change_key_case((array) config('gcc.storefronts.payment_methods.switched_off'), CASE_UPPER);
        $methods = array_values((array) ($byCountry[$country] ?? []));
        $unknown = array_diff($methods, self::METHODS);

        if ($unknown !== []) {
            throw new InvalidArgumentException("Storefront [{$country}] switches off payment methods that do not exist: " . implode(', ', $unknown) . '.');
        }

        return $methods;
    }
}
