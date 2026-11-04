<?php

namespace App\Packages\AdvanceSearch\Traits;

trait ModelsWithRoutesTrait
{
    public function getModelPrefix(): string
    {
        return 'advanced_search_';
    }


    public function getModels(): array
    {
        $models = [
            'users' => [
                'model' => 'App\Models\User',
                'translationable_type' => 'App\Models\User',
                'type' => 'users',
                'column' => ['id', 'name', 'f_name', 'l_name', 'phone'],
                'routes' => ['admin/customer/list', 'admin/customer/view/{id}'],
                'access_type' => ['admin', 'vendor'],
                'relations' => [
                    'orders' => [
                        'columns' => ['id', 'customer_id', 'order_group_id', 'order_amount', 'payment_status', 'order_status'],
                        'admin_routes' => [
                            'admin/orders/details/{id}' => 'order_Details',
                        ],
                        'vendor_routes' => [
                            'vendor/orders/details/{id}' => 'order_Details',
                        ],
                    ]
                ]
            ],
            'sellers' => [
                'model' => 'App\Models\Seller',
                'translationable_type' => 'App\Models\Seller',
                'type' => 'sellers',
                'column' => ['id', 'f_name', 'l_name','phone','email'],
                'routes' => ['admin/vendors/list', 'admin/vendors/view/{id}'],
                'access_type' => ['admin', 'vendor']
            ],
            'categories' => [
                'model' => 'App\Models\Category',
                'translationable_type' => 'App\Models\Category',
                'type' => 'categories',
                'column' => ['id', 'name', 'slug'],
                'routes' => ['admin/category/view', 'admin/category/update/{id}'],
                'access_type' => ['admin', 'vendor']
            ],
            'brands' => [
                'model' => 'App\Models\Brand',
                'translationable_type' => 'App\Models\Brand',
                'type' => 'brands',
                'column' => ['id', 'name', 'image'],
                'routes' => ['admin/brand/list', 'admin/brand/update/{id}'],
                'access_type' => ['admin', 'vendor']
            ],
            'coupons' => [
                'model' => 'App\Models\Coupon',
                'translationable_type' => 'App\Models\Coupon',
                'type' => 'coupons',
                'column' => ['id', 'title', 'code'],
                'routes' => ['admin/coupon/add', 'admin/coupon/{id}'],
                'access_type' => ['admin', 'vendor'],

            ],
            'products' => [
                'model' => 'App\Models\Product',
                'translationable_type' => 'App\Models\Product',
                'type' => 'products',
                'column' => ['id', 'name', 'slug', 'thumbnail', 'meta_title', 'meta_description', 'code'],
                'routes' => ['admin/products/list/in-house', 'admin/products/update/{id}'],
                'access_type' => ['admin', 'vendor'],
            ],
            'blogs' => [
                'model' => 'Modules\Blog\app\Models\Blog',
                'translationable_type' => 'Modules\Blog\app\Models\Blog',
                'type' => 'blogs',
                'column' => ['id', 'title', 'slug'],
                'routes' => ['admin/blog/view'],
                'access_type' => ['admin', 'vendor']
            ],
            'delivery_men' => [
                'model' => 'App\Models\DeliveryMan',
                'translationable_type' => 'App\Models\DeliveryMan',
                'type' => 'delivery_men',
                'column' => ['id', 'f_name', 'l_name', 'phone'],
                'routes' => ['admin/delivery-man/list'],
                'access_type' => ['admin', 'vendor'],

            ],
            'orders' => [
                'model' => 'App\Models\Order',
                'translationable_type' => 'App\Models\Order',
                'type' => 'orders',
                'column' => ['id', 'order_group_id', 'order_amount', 'payment_status', 'order_status'],
                'routes' => ['admin/orders/list/all', 'admin/orders/details/{id}'],
                'access_type' => ['admin', 'vendor'],
            ],
            'refund_requests' => [
                'model' => 'App\Models\RefundRequest',
                'translationable_type' => 'App\Models\RefundRequest',
                'type' => 'refund_requests',
                'column' => ['id', 'refund_reason', 'status'],
                'routes' => ['admin/refund-section/refund/list/pending', 'admin/refund-section/refund/list/approved', 'admin/refund-section/refund/list/refunded', 'admin/refund-section/refund/list/rejected','refund-section/refund/details/{id}'],
                'access_type' => ['admin', 'vendor'],
            ],
            'contacts' => [
                'model' => 'App\Models\Contact',
                'translationable_type' => 'App\Models\Contact',
                'type' => 'contacts',
                'column' => ['id', 'name', 'email', 'subject', 'message'],
                'routes' => ['admin/contact/list'],
                'access_type' => ['admin', 'vendor'],
            ],
            'subscriptions' => [
                'model' => 'App\Models\Subscription',
                'translationable_type' => 'App\Models\Subscription',
                'type' => 'subscriptions',
                'column' => ['id', 'email'],
                'routes' => ['admin/customer/subscriber-list'],
                'access_type' => ['admin', 'vendor'],
            ],
            'business_pages' => [
                'model' => 'App\Models\BusinessPage',
                'translationable_type' => 'App\Models\BusinessPage',
                'type' => 'business_page',
                'column' => ['title', 'slug'],
                'routes' => ['admin/pages-and-media/list'],
                'access_type' => ['admin', 'vendor'],
            ],

        ];

        if (function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction')) {
            $models['auctions'] = [
                'model' => 'Modules\Auction\app\Models\AuctionProduct',
                'translationable_type' => 'Modules\Auction\app\Models\AuctionProduct',
                'type' => 'auctions',
                'column' => ['id', 'name'],
                'routes' => ['admin/auction/products/view?id={id}'],
                'access_type' => ['admin'],
            ];
        }

        return $models;
    }

    public function getRoutes()
    {
    }
}
