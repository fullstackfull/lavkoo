<?php

return [
    [
        'name' => 'Admin Tax Report',
        'url' => route('admin.report.get-tax-report'),
        'path' => 'admin/report/get-tax-report',
        'sub_routes' => [
            [
                'name' => 'Tax Report Details',
                'url' => route('admin.report.getTaxDetails'),
                'path' => 'admin/report/get-tax-details',
            ]
        ]
    ],
    [
        'name' => 'Vendor_Vat_Report',
        'url' => route('admin.report.vendor-wise-taxes'),
        'path' => 'admin/report/vendor-wise-taxes',
        'sub_routes' => [
            [
                'name' => 'Vendor Tax Details',
                'url' => route('admin.report.vendorTax'),
                'path' => 'admin/report/vendor-tax-report',
            ]
        ]
    ]
];
