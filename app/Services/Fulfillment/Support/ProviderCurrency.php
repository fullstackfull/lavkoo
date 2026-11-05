<?php

namespace App\Services\Fulfillment\Support;

/**
 * Converts an amount as the platform STORES it into the currency a provider is paid and collects in.
 *
 * The platform stores prices and order totals in a USD-based unit and converts for display
 * (`webCurrencyConverter`). A provider collecting cash must be told exactly the figure the customer saw,
 * so this applies the same rate — selected currency over USD — with the same rounding. It refuses
 * (returns null) rather than guess when the rate is unknown: a fulfillment with an unconvertible amount
 * is held, never sent with a wrong COD figure.
 */
final class ProviderCurrency
{
    public function __construct(private readonly CurrencyRateSource $rates)
    {
    }

    public function code(): string
    {
        return strtoupper((string) config('fulfillment.currency', 'SAR'));
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
        } elseif (strtoupper((string) $this->rates->defaultCode()) !== $target) {
            return null;
        }

        return round($amount, 2, PHP_ROUND_HALF_UP);
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

        return strtoupper((string) $this->rates->defaultCode()) === $target ? $amount : null;
    }
}
