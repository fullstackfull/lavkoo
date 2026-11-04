<?php

namespace App\Enums\ViewPaths\Admin;

enum Customer
{
    const VIEW = [
        URI => 'view',
        VIEW => 'admin-views.customer.customer-view'
    ];

    const UPDATE = [
        URI => 'status-update',
        VIEW => 'admin-views.category.category-edit'
    ];

    const DELETE = [
        URI => 'delete/{id}',
        VIEW => ''
    ];

    const EXPORT = [
        URI => 'export',
        VIEW => ''
    ];

    const SEARCH = [
        URI => 'customer-list-search',
        VIEW => ''
    ];

    const ADD = [
        URI => 'add',
        VIEW => ''
    ];
}
