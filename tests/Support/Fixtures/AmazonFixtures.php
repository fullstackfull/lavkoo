<?php

namespace Tests\Support\Fixtures;

/**
 * Selling Partner API responses shaped after the official models (selling-partner-api-models: orders_2026-01-01, listingsItems_2021-08-01, fbaInventory,
 * finances_2024-06-19) and their own sandbox samples. They are NOT recordings of a live account: every identifier, name and amount is invented.
 * The Saudi marketplace id and the SAR currency are the ones the real API uses for amazon.sa.
 */
final class AmazonFixtures
{
    public const MARKETPLACE = 'A17E79C6D8DWNP';

    /** @param array<string, mixed> $overrides merged over the top level of the order */
    public static function order(string $orderId = '171-0000001-0000001', string $fulfilledBy = 'MERCHANT', string $status = 'UNSHIPPED', array $overrides = []): array
    {
        return array_replace_recursive([
            'orderId' => $orderId,
            'orderAliases' => [['aliasId' => 'SELLER-' . $orderId, 'aliasType' => 'SELLER_ORDER_ID']],
            'createdTime' => '2026-11-05T08:00:00Z',
            'lastUpdatedTime' => '2026-11-05T09:30:00Z',
            'programs' => ['PRIME'],
            'associatedOrders' => [],
            'salesChannel' => ['channelName' => 'AMAZON', 'marketplaceId' => self::MARKETPLACE, 'marketplaceName' => 'Amazon.sa'],
            'recipient' => ['deliveryAddress' => ['name' => 'Test Buyer', 'addressLine1' => '1 Test Street', 'city' => 'Riyadh', 'postalCode' => '11564', 'countryCode' => 'SA', 'phone' => '+966500000000', 'addressType' => 'RESIDENTIAL']],
            'buyer' => ['buyerName' => 'Test Buyer', 'buyerEmail' => 'buyer-token@marketplace.example.test'],
            'proceeds' => ['grandTotal' => ['amount' => '230.00', 'currencyCode' => 'SAR']],
            'payment' => ['paymentExecutions' => [['paymentMethod' => 'CreditCard', 'paymentAmount' => ['amount' => '230.00', 'currencyCode' => 'SAR']]]],
            'fulfillment' => ['fulfillmentStatus' => $status, 'fulfilledBy' => $fulfilledBy, 'fulfillmentServiceLevel' => 'STANDARD'],
            'orderItems' => [[
                'orderItemId' => '44000000000001',
                'quantityOrdered' => 2,
                'product' => [
                    'asin' => 'B0TESTASIN1', 'title' => 'Test product', 'sellerSku' => 'SKU-1',
                    'condition' => ['conditionType' => 'NEW'],
                    'price' => ['unitPrice' => ['amount' => '100.00', 'currencyCode' => 'SAR']],
                ],
                'proceeds' => [
                    'proceedsTotal' => ['amount' => '230.00', 'currencyCode' => 'SAR'],
                    'breakdowns' => [
                        ['type' => 'ITEM', 'subtotal' => ['amount' => '200.00', 'currencyCode' => 'SAR']],
                        ['type' => 'SHIPPING', 'subtotal' => ['amount' => '10.00', 'currencyCode' => 'SAR']],
                        ['type' => 'DISCOUNT', 'subtotal' => ['amount' => '-5.00', 'currencyCode' => 'SAR']],
                        ['type' => 'TAX', 'subtotal' => ['amount' => '25.00', 'currencyCode' => 'SAR']],
                    ],
                ],
                'fulfillment' => ['quantityFulfilled' => 0, 'quantityUnfulfilled' => 2],
            ]],
        ], $overrides);
    }

    public static function searchOrders(array $orders, ?string $nextToken = null): array
    {
        return array_filter(['orders' => $orders, 'pagination' => $nextToken === null ? null : ['nextToken' => $nextToken]], static fn ($value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function listing(string $sku = 'SKU-1', array $channels = ['DEFAULT'], string $lastUpdated = '2026-11-04T10:00:00Z'): array
    {
        $availability = array_map(static fn (string $code): array => $code === 'DEFAULT' ? ['fulfillmentChannelCode' => $code, 'quantity' => 12] : ['fulfillmentChannelCode' => $code], $channels);

        return [
            'sku' => $sku,
            'summaries' => [['marketplaceId' => self::MARKETPLACE, 'asin' => 'B0TESTASIN1', 'productType' => 'PRODUCT', 'conditionType' => 'new_new', 'status' => ['BUYABLE', 'DISCOVERABLE'], 'fnSku' => 'X00TESTFNSKU', 'itemName' => 'Test product', 'createdDate' => '2026-01-01T00:00:00Z', 'lastUpdatedDate' => $lastUpdated]],
            'offers' => [['marketplaceId' => self::MARKETPLACE, 'offerType' => 'B2C', 'price' => ['currencyCode' => 'SAR', 'amount' => '99.50']]],
            'fulfillmentAvailability' => $availability,
        ];
    }

    public static function inventorySummary(string $sku = 'SKU-1', int $fulfillable = 7): array
    {
        return [
            'asin' => 'B0TESTASIN1', 'fnSku' => 'X00TESTFNSKU', 'sellerSku' => $sku, 'condition' => 'NewItem', 'productName' => 'Test product',
            'lastUpdatedTime' => '2026-11-05T06:00:00Z', 'totalQuantity' => $fulfillable + 3,
            'inventoryDetails' => [
                'fulfillableQuantity' => $fulfillable, 'inboundWorkingQuantity' => 1, 'inboundShippedQuantity' => 2, 'inboundReceivingQuantity' => 0,
                'reservedQuantity' => ['totalReservedQuantity' => 3, 'pendingCustomerOrderQuantity' => 2, 'pendingTransshipmentQuantity' => 1, 'fcProcessingQuantity' => 0],
                'unfulfillableQuantity' => ['totalUnfulfillableQuantity' => 1, 'customerDamagedQuantity' => 1],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function transaction(string $id = 'TX-1', string $type = 'Shipment', ?array $breakdowns = null, string $posted = '2026-11-06T10:00:00Z'): array
    {
        return [
            'sellingPartnerMetadata' => ['sellingPartnerId' => 'A1SELLER', 'accountType' => 'Standard Orders', 'marketplaceId' => self::MARKETPLACE],
            'relatedIdentifiers' => [['relatedIdentifierName' => 'ORDER_ID', 'relatedIdentifierValue' => '171-0000001-0000001']],
            'transactionType' => $type,
            'transactionId' => $id,
            'transactionStatus' => 'RELEASED',
            'description' => 'Order Payment',
            'postedDate' => $posted,
            'totalAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '170.00'],
            'items' => [[
                'description' => 'Test product',
                'totalAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '170.00'],
                'contexts' => [['contextType' => 'ProductContext', 'asin' => 'B0TESTASIN1', 'sku' => 'SKU-1', 'quantityShipped' => 2]],
                'breakdowns' => $breakdowns ?? [
                    ['breakdownType' => 'Sales', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '200.00'], 'breakdowns' => [
                        ['breakdownType' => 'Product Charges', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '200.00'], 'breakdowns' => []],
                    ]],
                    ['breakdownType' => 'Expenses', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-30.00'], 'breakdowns' => [
                        ['breakdownType' => 'Commission', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-20.00'], 'breakdowns' => []],
                        ['breakdownType' => 'FBA fees', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-10.00'], 'breakdowns' => []],
                    ]],
                ],
            ]],
        ];
    }
}
