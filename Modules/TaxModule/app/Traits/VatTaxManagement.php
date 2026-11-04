<?php

namespace Modules\TaxModule\app\Traits;


use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;
use Modules\TaxModule\app\Models\Taxable;
use Illuminate\Support\Facades\Cache;

trait VatTaxManagement
{
    use VatTaxConfiguration;

    public static function getTaxSystemType($getTaxVatList = true, $tax_payer = 'vendor'): array
    {
        $cacheKey = "tax_system_type_{$tax_payer}_" . ($getTaxVatList ? 'with_vat' : 'no_vat');

        $cacheKeys = Cache::get('cache_tax_system_types_and_config', []);
        if (!in_array($cacheKey, $cacheKeys)) {
            $cacheKeys[] = $cacheKey;
            Cache::put('cache_tax_system_types_and_config', $cacheKeys, 60 * 60 * 24 * 7);
        }

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($getTaxVatList, $tax_payer) {
            if (getCheckAddonPublishedStatus('TaxModule')) {
                $systemTaxVat = SystemTaxSetup::where('is_active', 1)
                    ->with(['additionalData' => function ($query) {
                        return $query->where('is_active', 1);
                    }])
                    ->where('tax_payer', $tax_payer)
                    ->where('is_default', 1)
                    ->first();

                if (!$systemTaxVat) {
                    $systemTaxVat = self::getAddInitSystemVatTax();
                    return [
                        'SystemTaxVat' => $systemTaxVat ?? null,
                        'SystemTaxVatType' => $systemTaxVat?->tax_type ?? 'order_wise',
                        'is_included' => $systemTaxVat?->is_included ?? 0,
                        'productWiseTax' => false,
                        'categoryWiseTax' => false,
                        'taxVats' => []
                    ];
                }

                if ($getTaxVatList) {
                    $taxVats = Tax::where('is_active', 1)->where('is_default', 1)->get();
                }

                if ($systemTaxVat?->tax_type == 'product_wise') {
                    $productWiseTax = true;
                } elseif ($systemTaxVat?->tax_type == 'category_wise') {
                    $categoryWiseTax = true;
                }
            }

            return [
                'SystemTaxVat' => $systemTaxVat ?? null,
                'SystemTaxVatType' => $systemTaxVat?->tax_type ?? 'order_wise',
                'is_included' => $systemTaxVat?->is_included ?? 0,
                'productWiseTax' => $productWiseTax ?? false,
                'categoryWiseTax' => $categoryWiseTax ?? false,
                'taxVats' => $taxVats ?? collect([])
            ];
        });
    }


    public static function getAddTaxData($taxableType, $taxableId, $taxIds = [], $taxFor = 'tax_for_orders'): void
    {
        if (getCheckAddonPublishedStatus(moduleName: 'TaxModule')) {
            $SystemTaxVat = SystemTaxSetup::where('is_active', 1)->where('is_default', 1)->first();
            foreach ($taxIds as $tax_id) {
                Taxable::create([
                    'taxable_type' => $taxableType,
                    'taxable_id' => $taxableId,
                    'system_tax_setup_id' => $SystemTaxVat->id,
                    'tax_id' => $tax_id,
                    'tax_for' => $taxFor,
                ]);
            }
        }
    }


    public static function getUpdateTaxData($taxableType, $taxableId, $taxIds = [], $oldTaxIds = [], $taxFor = 'tax_for_orders'): void
    {
        if (getCheckAddonPublishedStatus(moduleName: 'TaxModule')) {
            $newTaxVatIds = array_map('intval', $taxIds ?? []);
            sort($newTaxVatIds);
            sort($oldTaxIds);

            if ($newTaxVatIds != $oldTaxIds) {
                Taxable::whereIn('tax_id', $oldTaxIds)
                    ->where('taxable_type', $taxableType)
                    ->where('tax_for', $taxFor)
                    ->delete();
                $SystemTaxVat = SystemTaxSetup::where('is_active', 1)->where('is_default', 1)->first();
                foreach ($taxIds as $tax_id) {
                    Taxable::create([
                        'taxable_type' => $taxableType,
                        'taxable_id' => $taxableId,
                        'system_tax_setup_id' => $SystemTaxVat->id,
                        'tax_id' => $tax_id,
                        'tax_for' => $taxFor,
                    ]);
                }
            }
        }
    }

}
