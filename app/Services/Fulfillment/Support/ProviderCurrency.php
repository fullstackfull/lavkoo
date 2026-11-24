<?php

namespace App\Services\Fulfillment\Support;

use App\Services\Storefront\StorefrontCurrency;

/**
 * Converts an amount as the platform STORES it into the currency a provider is paid and collects in.
 *
 * The platform stores prices and order totals in a USD-based unit and converts for display
 * (`webCurrencyConverter`). A provider collecting cash must be told exactly the figure the customer saw,
 * so this applies the same rate — selected currency over USD — with the same rounding. It refuses
 * (returns null) rather than guess when the rate is unknown: a fulfillment with an unconvertible amount
 * is held, never sent with a wrong COD figure.
 *
 * A single-currency store whose default currency is the provider currency under another spelling (this store's riyal is "KSA")
 * needs no conversion: see CurrencyCode.
 *
 * **It rounds to the decimals the currency actually has** (C-15). The riyal has two and every amount this class has ever carried was a
 * riyal amount, so rounding to two was rounding to the riyal's smallest coin. The Kuwaiti and Bahraini dinar and the Omani rial have
 * three: rounding one of those to two decimals asks a courier for up to five fils that the order does not owe, and that figure is the one
 * the customer pays in cash. The decimals come from the one place that holds them, `gcc.storefronts.currency.decimals`, read through
 * {@see StorefrontCurrency::decimalsOf()} -- which answers two for every currency it is not told about, the riyal included.
 */
final class ProviderCurrency
{
    public function __construct(
        private readonly CurrencyRateSource $rates,
        private readonly StorefrontCurrency $currencies,
    ) {
    }

    /** How many decimals the currency a provider is paid in has: two for the riyal, three for the dinar and the rial. */
    public function decimals(): int
    {
        return $this->currencies->decimalsOf($this->code());
    }

    /** The ISO code a provider is paid in. Never the store's own spelling of it ("KSA" for this store's riyal). */
    public function code(): string
    {
        return CurrencyCode::provider();
    }

    public function fromStored(float|int|string|null $stored): ?float
    {
        $amount = (float) $stored;
        $target = $this->code();

        if ($this->rates->model() === 'multi_currency') {
            $usd = $this->rates->rate('USD');
            $targetRate = $this->rates->rate($target);
            if ($usd === null || $usd <= 0.0 || $targetRate === null || $targetRate <= 0.0) {
                return null;
            }
            $amount = $amount * ($targetRate / $usd);
        } elseif (CurrencyCode::canonical($this->rates->defaultCode()) !== $target) {
            return null;
        }

        return round($amount, $this->decimals(), PHP_ROUND_HALF_UP);
    }

    /**
     * The inverse of fromStored(): an amount in the provider currency as the platform would STORE it.
     * Used only when a provider's price becomes one of our own (an imported product's price).
     */
    public function toStored(float|int|string|null $providerAmount): ?float
    {
        $amount = (float) $providerAmount;
        $target = $this->code();

        if ($this->rates->model() === 'multi_currency') {
            $usd = $this->rates->rate('USD');
            $targetRate = $this->rates->rate($target);
            if ($usd === null || $usd <= 0.0 || $targetRate === null || $targetRate <= 0.0) {
                return null;
            }

            return $amount * ($usd / $targetRate);
        }

        return CurrencyCode::canonical($this->rates->defaultCode()) === $target ? $amount : null;
    }
}
