<?php

namespace Modules\TaxModule\app\Traits;

use Devrabiul\ToastMagic\Facades\ToastMagic;
use Modules\TaxModule\app\Models\SystemTaxSetup;

trait VatTaxConfiguration
{

    public static function getAddInitSystemVatTax()
    {
        return SystemTaxSetup::create([
            'tax_type' => 'order_wise',
            'country_code' => null,
            'tax_payer' => 'vendor',
            'is_default' => true,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function getCountryType()
    {
        return config('taxmodule.country_type');
    }

    public static function getTaxModulePagination()
    {
        return config('taxmodule.pagination');
    }

    public static function getProjectName()
    {
        return config('taxmodule.project');
    }

    public static function getProjectWiseSystemData($key = null)
    {
        $allProjects = [
            '6ammart' => [
                'tax_calculate_from' => ['Calculate_Tax_on_Billing_Address_Location'],

                'tax_calculate_on' => ['order_wise', 'product_wise', 'category_wise'],
                'tax_calculate_on_rental_provider' => ['trip_wise'],
                'tax_calculate_on_parcel' => ['order_wise', 'category_wise'],
                'tax_calculate_on_prescription' => ['order_wise'],

                'additional_tax' => ['tax_on_additional_charge', 'tax_on_packaging_charge'],
                'additional_tax_rental_provider' => ['tax_on_additional_charge'],
                'additional_tax_parcel' => ['tax_on_additional_charge'],
                'additional_tax_prescription' => ['tax_on_additional_charge', 'tax_on_packaging_charge'],

                'payer_types' => ['vendor', 'rental_provider', 'parcel', 'prescription'],

            ],
            'stackfood' => [
                'tax_calculate_from' => ['Calculate_Tax_on_Billing_Address_Location'],
                'tax_calculate_on' => ['order_wise', 'product_wise', 'category_wise'],
                'additional_tax' => ['tax_on_packaging_charge'],
                'payer_types' => ['vendor'],
            ],
            '6valley' => [
                'tax_calculate_from' => ['Calculate_Tax_on_Billing_Address_Location', 'Calculate_Tax_on_Shipping_Address_Location'],
                'tax_calculate_on' => ['order_wise', 'product_wise', 'category_wise'],
                'additional_tax' => ['tax_on_delivery_charge'],
            ]
        ];

        return self::getDataFromProjectArray($allProjects, $key);
    }

    public static function getProjectWiseViewPath($name)
    {
        $allProjects = [
            '6ammart' => [
                'tax_list_export' => 'taxmodule::file-exports.tax_list_export',
                'tax_list' => 'taxmodule::tax.tax_list',
                'system_tax_setup' => 'taxmodule::tax.system_tax_setup',
            ],
            'stackfood' => [
                'tax_list_export' => 'taxmodule::file-exports.tax_list_export',
                'tax_list' => 'taxmodule::tax.tax_list',
                'system_tax_setup' => 'taxmodule::tax.system_tax_setup',
            ],
            '6valley' => [
                'tax_list' => 'taxmodule::6valley.tax.tax_list',
                'tax_list_export' => 'taxmodule::6valley.file-exports.tax_list_export',
                'admin_tax_report' => 'taxmodule::6valley.file-exports.admin_tax_report',
                'system_tax_setup' => 'taxmodule::6valley.tax.system_tax_setup',
            ],
        ];

        return self::getDataFromProjectArray($allProjects, $name);
    }

    public static function getClassNames($model)
    {
        $allProjects = [
            '6ammart' => [
                'product' => 'App\Models\Item',
                'category' => 'App\Models\Category',
                'addon' => 'App\Models\AddOn',
                'addon_category' => 'App\Models\AddonCategory',
                'store' => 'App\Models\Store',
                'order' => 'App\Models\Order',
                'trip' => 'Modules\Rental\Entities\Trips',
                'parcel_category' => 'App\Models\ParcelCategory',
                'campaign_product' => 'App\Models\ItemCampaign',
            ],
            '6valley' => [
                'product' => 'App\Models\Product',
                'category' => 'App\Models\Category',
                'order' => 'App\Models\Order',
            ],
        ];

        return self::getDataFromProjectArray($allProjects, $model);
    }

    private static function getDataFromProjectArray($array, $key = null)
    {
        $project = self::getProjectName();
        if ($project && array_key_exists($project, $array)) {
            return $key ? data_get($array[$project], $key, []) : $array[$project];
        }
        return $array;
    }

    public function showNotification($type, $message)
    {
        $class = ToastMagic::class;
        $methodTypes = [
            // message warning type => method name
            'successMessage' => 'success',
            'infoMessage' => 'info',
            'warningMessage' => 'warning',
            'errorMessage' => 'error'
        ];

        if (class_exists($class) && array_key_exists($type, $methodTypes)) {
            return call_user_func([$class, $methodTypes[$type]], $message);
        }
    }
}
