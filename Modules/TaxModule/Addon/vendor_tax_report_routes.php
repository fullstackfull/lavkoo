<?php

return [
    [
        'name' => 'VAT Report',
        'url' => route('vendor.report.get-vat-report'),
        'path' => 'vendor/report/get-vat-report',
        'sub_routes' => [
            [
                'name' => 'Vat Report Details',
                'url' => route('vendor.report.get-vat-report-export'),
                'path' => 'vendor/report/get-vat-report-export',
            ]
        ]
    ]
];
