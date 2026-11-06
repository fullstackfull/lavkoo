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

    /**
     * The rate of a currency, read from the store's text column and accepted only as a plain positive decimal (see ExchangeRate). The row
     * carrying the ISO code is preferred; a row under the store's spelling of it ("KSA" for "SAR") stands in when there is none.
     */
    public function rate(string $code): ?float
    {
        $spellings = CurrencyCode::spellings($code);
        $rows = Currency::whereIn('code', $spellings)->get(['code', 'exchange_rate']);

        foreach ($spellings as $spelling) {
            $row = $rows->first(static fn (Currency $currency): bool => strtoupper(trim((string) $currency->code)) === $spelling);
            if ($row !== null) {
                return ExchangeRate::parse($row->exchange_rate);
            }
        }

        return null;
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
