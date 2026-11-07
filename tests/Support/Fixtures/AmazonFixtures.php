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
    /** One breakdown node with a single amount (no children). */
    public static function leaf(string $type, string $amount): array
    {
        return ['breakdownType' => $type, 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => $amount], 'breakdowns' => []];
    }

    /**
     * A Shipment transaction for two items. Amazon's model gives a transaction its own breakdowns (what the whole transaction adds up to) and each item its own: with
     * `$transactionLevel` both are present and describe the same money; without it only the items carry breakdowns.
     */
    public static function twoItemTransaction(bool $transactionLevel, string $id = 'TX-2ITEMS'): array
    {
        $item = static fn (string $sku, string $itemId): array => [
            'description' => 'Test product ' . $sku,
            'totalAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '80.00'],
            'relatedIdentifiers' => [['relatedIdentifierName' => 'ORDER_ADJUSTMENT_ITEM_ID', 'relatedIdentifierValue' => $itemId]],
            'contexts' => [['contextType' => 'ProductContext', 'asin' => 'B0' . $sku, 'sku' => $sku, 'quantityShipped' => 1]],
            'breakdowns' => [self::leaf('Product Charges', '100.00'), ['breakdownType' => 'Expenses', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-20.00'], 'breakdowns' => [self::leaf('Commission', '-20.00')]]],
        ];
        $transaction = self::transaction($id);
        $transaction['totalAmount'] = ['currencyCode' => 'SAR', 'currencyAmount' => '160.00'];
        $transaction['items'] = [$item('SKU-A', 'ITEM-A'), $item('SKU-B', 'ITEM-B')];
        if ($transactionLevel) {
            $transaction['breakdowns'] = [self::leaf('Product Charges', '200.00'), ['breakdownType' => 'Expenses', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-40.00'], 'breakdowns' => [self::leaf('Commission', '-40.00')]]];
        }

        return $transaction;
    }

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

    /**
     * One refunded item of a Refund transaction: what went back to the buyer (negative: goods, shipping, tax; a promotion that was taken off is the other way) and the fees Amazon
     * reverses or charges on the refund (a commission credited back is positive, a fee charged on the refund negative).
     *
     * @param array{principal?: string, shipping?: string, tax?: string, promotion?: string, commission?: string, refund_commission?: string, other?: string} $amounts
     * @return array<string, mixed>
     */
    public static function refundItem(string $sku = 'SKU-1', string $adjustmentId = 'ADJ-1', array $amounts = []): array
    {
        $amounts += ['principal' => '-100.00', 'shipping' => '0', 'tax' => '-15.00', 'promotion' => '0', 'commission' => '20.00', 'refund_commission' => '0', 'other' => '0'];
        $sales = array_filter([
            self::leaf('Product Charges', $amounts['principal']),
            $amounts['shipping'] === '0' ? null : self::leaf('Shipping', $amounts['shipping']),
            $amounts['tax'] === '0' ? null : self::leaf('Tax', $amounts['tax']),
            $amounts['promotion'] === '0' ? null : self::leaf('Promotions', $amounts['promotion']),
            $amounts['other'] === '0' ? null : self::leaf('Gift wrap', $amounts['other']),
        ]);
        $expenses = array_filter([
            $amounts['commission'] === '0' ? null : self::leaf('Commission', $amounts['commission']),
            $amounts['refund_commission'] === '0' ? null : self::leaf('RefundCommission', $amounts['refund_commission']),
        ]);

        return [
            'description' => 'Refunded item ' . $sku,
            'totalAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '0.00'],
            'relatedIdentifiers' => [['itemRelatedIdentifierName' => 'ORDER_ADJUSTMENT_ITEM_ID', 'itemRelatedIdentifierValue' => $adjustmentId]],
            'contexts' => [['contextType' => 'ProductContext', 'asin' => 'B0' . $sku, 'sku' => $sku, 'quantityShipped' => 1]],
            'breakdowns' => array_values(array_filter([
                ['breakdownType' => 'Sales', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '0.00'], 'breakdowns' => array_values($sales)],
                $expenses === [] ? null : ['breakdownType' => 'Expenses', 'breakdownAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '0.00'], 'breakdowns' => array_values($expenses)],
            ])),
        ];
    }

    /**
     * A Refund transaction of one order: the refund's own id and one or more refunded items.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    public static function refund(string $transactionId = 'TX-REFUND-1', string $refundId = 'REFUND-1', string $orderId = '171-0000001-0000001', ?array $items = null, string $posted = '2026-11-08T10:00:00Z', string $status = 'RELEASED'): array
    {
        return [
            'sellingPartnerMetadata' => ['sellingPartnerId' => 'A1SELLER', 'accountType' => 'Standard Orders', 'marketplaceId' => self::MARKETPLACE],
            'relatedIdentifiers' => [['relatedIdentifierName' => 'ORDER_ID', 'relatedIdentifierValue' => $orderId], ['relatedIdentifierName' => 'REFUND_ID', 'relatedIdentifierValue' => $refundId]],
            'transactionType' => 'Refund',
            'transactionId' => $transactionId,
            'transactionStatus' => $status,
            'description' => 'Refund Order',
            'postedDate' => $posted,
            'totalAmount' => ['currencyCode' => 'SAR', 'currencyAmount' => '-95.00'],
            'items' => $items ?? [self::refundItem()],
        ];
    }
}
