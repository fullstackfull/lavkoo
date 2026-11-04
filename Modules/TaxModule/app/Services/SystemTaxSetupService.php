<?php

namespace Modules\TaxModule\app\Services;

use Illuminate\Support\Facades\Cache;
use Modules\TaxModule\app\Traits\VatTaxConfiguration;

class SystemTaxSetupService
{
    use VatTaxConfiguration;

    public static function getSystemTaxSetupData(object|array $request): array
    {
        return [
            'tax_type' => $request['tax_type'] ?? 'order_wise',
            'tax_ids' => $request['tax_ids'],
            'is_included' => $request['tax_status'] == 'include' ? 1 : 0,
            'country_code' => self::getCountryType() !== 'single' ? $request['country_code'] ?? null : null,
        ];
    }

    public static function clearTaxSystemTypeCache(): void
    {
        $cacheKeys = Cache::get('cache_tax_system_types_and_config', []);
        foreach ($cacheKeys as $cacheKey) {
            Cache::forget($cacheKey);
        }
        Cache::forget('cache_tax_system_types_and_config');
    }

}
