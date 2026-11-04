<?php

namespace App\Enums\ViewPaths\Admin;

enum Coupon
{
    const ADD = [
        URI => 'add',
        VIEW => 'admin-views.coupon.add-new',
        ROUTE => 'admin.coupon.add'
    ];

    const DELETE = [
        URI => 'delete',
        VIEW => ''
    ];

    const STATUS = [
        URI => 'status',
        VIEW => ''
    ];

    const UPDATE = [
        URI => 'update',
        VIEW => 'admin-views.coupon.edit'
    ];

    const EXPORT = [
        URI => 'export',
        VIEW => ''
    ];
}
