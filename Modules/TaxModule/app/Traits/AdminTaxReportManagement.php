<?php

namespace Modules\TaxModule\app\Traits;


use Modules\TaxModule\app\Models\Tax;


trait AdminTaxReportManagement
{
    use VatTaxConfiguration;

    public static function getTaxRates($request): array
    {
        $allTaxIds = collect([
            ...($request->tax_rate ?? []),
            ...($request->tax_on_delivery_charge_commission ?? []),
            ...($request->tax_on_order_commission ?? []),
        ])->unique()->filter()->values();

        $taxRates = Tax::whereIn('id', $allTaxIds)->get()->keyBy('id');

        if ($request['calculate_tax_on'] == 'all_source') {
            $taxOnDeliveryChargeCommission = $taxRates->only($request['tax_rate'] ?? []);
            $taxOnOrderCommission = $taxRates->only($request['tax_rate'] ?? []);
        } else {
            $taxOnDeliveryChargeCommission = $taxRates->only($request['tax_on_delivery_charge_commission'] ?? []);
            $taxOnOrderCommission = $taxRates->only($request['tax_on_order_commission'] ?? []);
        }

        return [
            'tax_on_all_source' => $taxRates->only($request['tax_rate'] ?? []),
            'tax_on_delivery_charge_commission' => $taxOnDeliveryChargeCommission,
            'tax_on_order_commission' => $taxOnOrderCommission,
        ];
    }


    public static function getTaxReportDateRange($type = '', $dates = null): string
    {
        if ($type == 'this_fiscal_year') {
            $dateRange = now()->startOfYear()->format('m/d/Y') . ' - ' . now()->format('m/d/Y');
        } else {
            if ($dates) {
                [$start, $end] = explode(' - ', $dates);
                $startFormatted = \Carbon\Carbon::parse($start)->format('m/d/Y');
                $endFormatted = \Carbon\Carbon::parse($end)->format('m/d/Y');
                $dateRange = $startFormatted . ' - ' . $endFormatted;
            } else {
                $dateRange = now()->subDays(6)->format('m/d/Y') . ' - ' . now()->format('m/d/Y');
            }
        }
        return $dateRange;
    }


}
