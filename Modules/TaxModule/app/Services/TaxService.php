<?php

namespace Modules\TaxModule\app\Services;

use Modules\TaxModule\app\Traits\VatTaxConfiguration;

class TaxService
{
    use VatTaxConfiguration;

    public static function getAddTax(object|array $request): array
    {
        $result = [
            'name' => $request['name'],
            'tax_rate' => $request['tax_rate'],
            'is_default' => true,
            'is_active' => $request['status'] ?? 0,
        ];
        if (self::getCountryType() != 'single') {
            $result['country_code'] = $request['country_code'];
            $result['is_default'] = false;
        }

        return $result;
    }
}
