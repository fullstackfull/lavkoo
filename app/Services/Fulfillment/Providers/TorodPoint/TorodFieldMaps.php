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

    /**
     * Required fields that only have to be PRESENT in the records, because the provider legitimately returns them empty.
     *
     * Most warehouse orders in the live account carry `invoiceNumber: null`: they were not created through this
     * integration. A null there is the provider's way of saying "no invoice number on this order"; it is a different
     * thing from a path that does not exist, which is what a wrong path looks like, so the first is accepted and the
     * second is not. `reference` (the order barcode) is not in this list: it stays strict.
     *
     * Passing is a statement about the shape of the response. It does not say that a record we create comes back with
     * the invoice number we sent — that is what the invoice round-trip confirmation is for.
     */
    public const NULLABLE = [
        self::ORDERS => ['invoice_number'],
    ];

    /** @var array<string, array<string, list<string>>> */
    public const CANDIDATES = [
        self::PRODUCTS => [
            'id' => ['id', 'productId'],
            'external_id' => ['externalId'],
            'name' => ['name', 'title'],
            'sku' => ['sku'],
            'barcode' => ['barcode'],
            // No candidate is proposed. The warehouse reports several quantities (on hand, sellable, committed, allocated, ...) and
            // which of them is the figure the storefront may sell from is a business decision to confirm with LogesTechs, not
            // something to pick because its name looks right or because it is the first one filled in.
            'stock' => [],
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
