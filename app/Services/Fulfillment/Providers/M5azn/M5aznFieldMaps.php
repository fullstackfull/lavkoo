<?php

namespace App\Services\Fulfillment\Providers\M5azn;

/**
 * Which parts of M5AZN's responses we read, and where we would look for them.
 *
 * M5AZN's official documentation specifies request shapes but NO response schemas for its catalog and
 * order endpoints. So nothing here is trusted: the candidates only seed a PROPOSAL shown beside a real
 * sample fetched from the provider, and a field map is not used for anything until an administrator
 * verifies it (see FieldMapStore).
 */
final class M5aznFieldMaps
{
    public const CATALOG = 'catalog';
    public const INVENTORY = 'inventory';
    public const ORDERS = 'orders';

    /** Logical fields that must resolve before a map may be verified. */
    public const REQUIRED = [
        self::CATALOG => ['id', 'name'],
        self::INVENTORY => ['id', 'stock'],
        self::ORDERS => ['reference', 'status'],
    ];

    /** @var array<string, array<string, list<string>>> */
    public const CANDIDATES = [
        self::CATALOG => [
            'id' => ['id', 'product_id', 'productId'],
            'name' => ['name', 'title', 'product_name'],
            'sku' => ['sku', 'code', 'product_code'],
            'barcode' => ['barcode', 'ean', 'upc'],
            'description' => ['description', 'details'],
            'stock' => ['quantity', 'qty', 'stock', 'current_stock'],
            'cost' => ['cost', 'cost_price', 'wholesale_price', 'purchase_price', 'seller_price'],
            'retail_price' => ['price', 'selling_price', 'sale_price', 'retail_price', 'unit_price'],
            'thumbnail' => ['thumbnail', 'image', 'main_image', 'image_url'],
            'images' => ['images', 'gallery'],
            'variants' => ['variants', 'variations', 'skus'],
        ],
        self::INVENTORY => [
            'id' => ['id', 'product_id', 'productId'],
            'stock' => ['quantity', 'qty', 'stock', 'current_stock'],
            'variant_sku' => ['sku', 'variant_sku'],
        ],
        self::ORDERS => [
            'reference' => ['id', 'order_id', 'orderId', 'order_number'],
            'external_order_id' => ['platform_order_id', 'external_id', 'external_order_id', 'store_order_id'],
            'status' => ['status', 'order_status', 'state'],
        ],
    ];

    /** Where the order reference is looked for in a CREATE-order response when no orders map is verified. */
    public const CREATE_RESPONSE_REFERENCE_PATHS = ['data.id', 'data.order_id', 'data.orderId', 'data.order.id', 'order.id', 'order_id', 'id'];

    /** Variant-level fields, relative to ONE entry of the catalog `variants` array. */
    public const VARIANT_CANDIDATES = [
        'id' => ['id', 'variant_id', 'sku_id'],
        'sku' => ['sku', 'code'],
        'name' => ['name', 'title', 'option', 'value'],
        'stock' => ['quantity', 'qty', 'stock'],
        'retail_price' => ['price', 'selling_price', 'retail_price'],
        'cost' => ['cost', 'cost_price', 'wholesale_price'],
    ];
}
