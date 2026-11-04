<?php

namespace App\Enums\ViewPaths\Admin;

enum Review
{
    const STATUS = [
        URI => 'status/{id}/{status}',
        VIEW => ''
    ];

    const SEARCH = [
        URI => 'customer-list-search',
        VIEW => ''
    ];

    const EXPORT = [
        URI => 'export',
        VIEW => ''
    ];
}
