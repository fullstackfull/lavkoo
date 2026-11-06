<?php

namespace App\Services\Fulfillment\Support;

use App\Models\Currency;

/** Reads the store's own currency configuration, the same data `webCurrencyConverter()` uses. */
final class StoreCurrencyRateSource implements CurrencyRateSource
{
    public function model(): string
    {
        return (string) (getWebConfig(name: 'currency_model') ?? 'single_currency');
    }

    public function rate(string $code): ?float
    {
        $rate = Currency::where('code', $code)->value('exchange_rate');

        return $rate === null ? null : (float) $rate;
    }

    public function defaultCode(): ?string
    {
        $id = getWebConfig(name: 'system_default_currency');

        return $id === null ? null : Currency::where('id', $id)->value('code');
    }

    public function decimals(): int
    {
        return (int) (getWebConfig(name: 'decimal_point_settings') ?? 2);
    }
}
