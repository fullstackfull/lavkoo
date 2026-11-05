<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

/**
 * Which parts of Torod Point's Fulfillment API v5 responses we read, and where we would look.
 *
 * The v5 document gives request shapes and one response example (the bulk product upsert) but NO response
 * schema for Get Product List, Add Order or Get Order List. In particular it does not say whether the
 * product list carries stock quantities. Candidates only seed a proposal shown beside a real sample; nothing
 * is used until an administrator verifies the map.
 */
final class TorodFieldMaps
{
    public const PRODUCTS = 'products';
    public const ORDERS = 'orders';

    public const REQUIRED = [
        self::PRODUCTS => ['id', 'name'],
        self::ORDERS => ['invoice_number', 'reference'],
    ];

    /** @var array<string, array<string, list<string>>> */
    public const CANDIDATES = [
        self::PRODUCTS => [
            'id' => ['id', 'productId'],
            'external_id' => ['externalId'],
            'name' => ['name', 'title'],
            'sku' => ['sku'],
            'barcode' => ['barcode'],
            'stock' => ['quantity', 'availableQuantity', 'available', 'availableStock', 'stock'],
            'price' => ['price'],
        ],
        self::ORDERS => [
            'invoice_number' => ['invoiceNumber', 'invoice_number'],
            'reference' => ['barcode', 'orderBarcode', 'id'],
            'status' => ['status'],
        ],
    ];

    /** Where the order reference (the WMS barcode the Fulfillment webhook reports) is looked for in an Add Order response. */
    public const CREATE_RESPONSE_REFERENCE_PATHS = ['barcode', 'data.barcode', 'orderBarcode', 'data.orderBarcode', '0.barcode', 'id', 'data.id', '0.id'];

    public const CREATE_RESPONSE_ID_PATHS = ['id', 'data.id', '0.id'];
}
