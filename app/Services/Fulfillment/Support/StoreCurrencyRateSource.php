<?php

namespace App\Services\Fulfillment\Support;

use App\Models\BusinessSetting;
use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

/** Reads the store's own currency configuration, the same data `webCurrencyConverter()` uses. */
final class StoreCurrencyRateSource implements CurrencyRateSource
{
    /**
     * Asking never fills the platform's settings cache (the platform's reader does, on a cold start): a report that only reads the store's rates must not write anything, not even
     * there. Once the platform has filled it, its reader is used.
     */
    public function model(): string
    {
        $configured = Cache::has(CACHE_BUSINESS_SETTINGS_TABLE) ? getWebConfig(name: 'currency_model') : $this->storedCurrencyModel();

        return (string) ($configured ?? 'single_currency');
    }

    /** The setting as the platform's reader hands it out: JSON decoded when it is JSON, the stored text when it is not. */
    private function storedCurrencyModel(): ?string
    {
        $stored = BusinessSetting::where('type', 'currency_model')->value('value');

        return $stored === null ? null : (string) (json_decode((string) $stored, true) ?? $stored);
    }

    /**
     * The rate of a currency, read from the store's text column and accepted only as a plain positive decimal (see ExchangeRate). The row
     * carrying the ISO code is preferred; a row under the store's spelling of it ("KSA" for "SAR") stands in when there is none. Should a
     * code appear twice, the first row wins, as everywhere in the platform.
     */
    public function rate(string $code): ?float
    {
        $spellings = CurrencyCode::spellings($code);
        $rows = Currency::whereIn('code', $spellings)->orderBy('id')->get(['code', 'exchange_rate']);

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
