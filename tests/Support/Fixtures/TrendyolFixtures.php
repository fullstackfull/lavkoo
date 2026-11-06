<?php

namespace Tests\Support\Fixtures;

/**
 * Trendyol responses shaped after the published Order V2 package model (developers.trendyol.com, International / Gulf set, as of November 2026), the Product V2 lists,
 * the price-and-inventory batch result, the webhook model and the Current Account Statement. They are NOT recordings from a live seller account: every id, name and
 * amount is invented. `orderDate` carries the documented +3 hour shift.
 */
final class TrendyolFixtures
{
    public const SELLER = '1234567';

    /** 2026-11-05 08:00:00 UTC */
    public const CREATED_MS = 1_793_865_600_000;

    /** @param array<string, mixed> $overrides merged over the top level (lists are replaced, not merged) */
    public static function package(array $overrides = []): array
    {
        return array_replace([
            'shipmentPackageId' => 3012345678,
            'orderNumber' => '10234567890',
            'shipmentNumber' => 3012345678,
            'customerId' => 99887766,
            'supplierId' => (int) self::SELLER,
            'channelId' => 1,
            'status' => 'Created',
            'shipmentPackageStatus' => 'Created',
            'packageHistories' => [['createdDate' => self::CREATED_MS, 'status' => 'Created']],
            'orderDate' => self::CREATED_MS - 600_000 + 10_800_000,
            'lastModifiedDate' => self::CREATED_MS,
            'originShipmentDate' => self::CREATED_MS + 86_400_000,
            'agreedDeliveryDate' => self::CREATED_MS + 259_200_000,
            'currencyCode' => 'SAR',
            'packageGrossAmount' => 200.0,
            'packageSellerDiscount' => 20.0,
            'packageTyDiscount' => 10.0,
            'packageTotalDiscount' => 30.0,
            'packageTotalPrice' => 170.0,
            'discountDisplays' => [],
            'paymentMethod' => '',
            'isCod' => false,
            'orderCountryCode' => 'SA',
            'customerFirstName' => 'Test',
            'customerLastName' => 'Buyer',
            'customerEmail' => 'buyer-token@trendyolmail.example.test',
            'identityNumber' => '1000000000',
            'taxNumber' => '300000000000003',
            'commercial' => false,
            'shipmentAddress' => [
                'id' => 123, 'firstName' => 'Test', 'lastName' => 'Buyer', 'company' => '', 'address1' => '1 Test Street', 'address2' => '', 'city' => 'Riyadh', 'cityCode' => 3,
                'district' => '', 'postalCode' => '', 'countryCode' => 'SA', 'stateName' => 'Riyadh Region', 'shortAddress' => 'RRRD1234', 'phone' => '+966500000000',
                'fullAddress' => '1 Test Street, Riyadh', 'fullName' => 'Test Buyer',
            ],
            'invoiceAddress' => ['id' => 124, 'firstName' => 'Test', 'lastName' => 'Buyer', 'address1' => '1 Test Street', 'city' => 'Riyadh', 'countryCode' => 'SA', 'phone' => '+966500000000', 'fullAddress' => '1 Test Street, Riyadh'],
            'cargoProviderName' => 'Aramex',
            'cargoTrackingNumber' => '7440123456',
            'cargoTrackingLink' => 'https://tracking.example.test/7440123456',
            'invoiceStatus' => 'NotInvoiced',
            'deliveryType' => 'normal',
            'deliveryAddressType' => 'Shipment',
            'fastDelivery' => false,
            'fastDeliveryType' => '',
            'deliveredByService' => false,
            'micro' => false,
            'createdBy' => 'order-creation',
            'originPackageIds' => null,
            'lines' => [self::line()],
        ], $overrides);
    }

    /** One line of two units: 100.00 each, 10.00 seller discount and 5.00 Trendyol discount per unit. */
    public static function line(array $overrides = []): array
    {
        return array_replace([
            'lineId' => 4400000001,
            'contentId' => 998877,
            'barcode' => '6281234567890',
            'stockCode' => 'SKU-1',
            'productName' => 'Test product',
            'productSize' => '',
            'productColor' => '',
            'productCategoryId' => 1162,
            'quantity' => 2,
            'currencyCode' => 'SAR',
            'vatRate' => 15,
            'commission' => 12.5,
            'businessUnit' => 'Beauty',
            'lineGrossAmount' => 100.0,
            'lineSellerDiscount' => 10.0,
            'lineTyDiscount' => 5.0,
            'lineTotalDiscount' => 15.0,
            'lineUnitPrice' => 85.0,
            'discountDetails' => [
                ['lineItemId' => 5500000001, 'lineItemPrice' => 85.0, 'lineItemSellerDiscount' => 10.0, 'lineItemTyDiscount' => 5.0],
                ['lineItemId' => 5500000002, 'lineItemPrice' => 85.0, 'lineItemSellerDiscount' => 10.0, 'lineItemTyDiscount' => 5.0],
            ],
            'orderLineItemStatusName' => 'Created',
        ], $overrides);
    }

    public static function stream(array $packages, ?string $nextCursor = null): array
    {
        return ['hasMore' => $nextCursor !== null, 'nextCursor' => $nextCursor, 'size' => count($packages), 'content' => $packages];
    }

    public static function approvedPage(array $contents, int $page = 0, int $totalPages = 1, ?string $nextPageToken = null): array
    {
        return ['totalElements' => count($contents), 'totalPages' => $totalPages, 'page' => $page, 'size' => 100, 'nextPageToken' => $nextPageToken, 'content' => $contents];
    }

    public static function content(string $barcode = '6281234567890', string $stockCode = 'SKU-1', array $variant = []): array
    {
        return [
            'contentId' => 998877,
            'productMainId' => 'MAIN-1',
            'brand' => ['id' => 10, 'name' => 'Brand'],
            'category' => ['id' => 1162, 'name' => 'Skin care'],
            'creationDate' => self::CREATED_MS - 86_400_000,
            'lastModifiedDate' => self::CREATED_MS,
            'title' => 'Test product',
            'description' => '<p>Test</p>',
            'images' => [['url' => 'https://img.example.test/1.jpg']],
            'attributes' => [],
            'variants' => [array_replace_recursive([
                'variantId' => 7700001, 'supplierId' => (int) self::SELLER, 'barcode' => $barcode, 'commission' => 12.5, 'attributes' => [], 'productUrl' => 'https://www.trendyol.example.test/p/1',
                'onSale' => true, 'channels' => ['CORE'], 'deliveryOption' => ['deliveryDuration' => 1],
                'stock' => ['quantity' => 14, 'lastModifiedDate' => self::CREATED_MS],
                'price' => ['salePrice' => 99.5, 'listPrice' => 120.0, 'priceSeenByCustomer' => 99.5],
                'stockCode' => $stockCode, 'vatRate' => 15, 'sellerCreatedDate' => self::CREATED_MS - 86_400_000, 'sellerModifiedDate' => self::CREATED_MS,
                'locked' => false, 'archived' => false, 'blacklisted' => false,
            ], $variant)],
        ];
    }

    public static function unapprovedProduct(string $barcode = '6281111111111', string $status = 'pendingApproval'): array
    {
        return [
            'supplierId' => (int) self::SELLER, 'productMainId' => 'MAIN-2', 'status' => $status, 'createDateTime' => self::CREATED_MS, 'lastUpdateDate' => self::CREATED_MS,
            'brand' => ['id' => 10, 'name' => 'Brand'], 'category' => ['id' => 1162, 'name' => 'Skin care'], 'barcode' => $barcode, 'title' => 'Draft product',
            'quantity' => 5, 'listPrice' => 60.0, 'salePrice' => 55.0, 'vatRate' => 15, 'stockCode' => 'SKU-2',
            'rejectReasonDetails' => $status === 'rejected' ? [['rejectReason' => 'Image quality', 'rejectReasonDetail' => 'Images are too small']] : [],
        ];
    }

    public static function settlement(string $id = 'row-1', float $commission = -25.0, float $credit = 170.0, array $overrides = []): array
    {
        return array_replace([
            'id' => $id, 'transactionDate' => self::CREATED_MS, 'barcode' => '6281234567890', 'transactionType' => 'Sale', 'receiptId' => 66001,
            'description' => 'Sale', 'debt' => 0.0, 'credit' => $credit, 'paymentPeriod' => 30, 'commissionRate' => 12.5, 'commissionAmount' => $commission,
            'commissionInvoiceSerialNumber' => null, 'sellerRevenue' => $credit + $commission, 'orderNumber' => '10234567890', 'paymentOrderId' => null, 'paymentDate' => null,
            'sellerId' => (int) self::SELLER, 'storeId' => 1, 'storeName' => 'Test store', 'country' => 'SA', 'orderDate' => self::CREATED_MS - 600_000, 'affiliate' => 'TRENDYOLGULF',
            'shipmentPackageId' => 3012345678,
        ], $overrides);
    }

    public static function statement(array $rows, int $page = 0, int $totalPages = 1): array
    {
        return ['page' => $page, 'size' => 1000, 'totalPages' => $totalPages, 'totalElements' => count($rows), 'content' => $rows];
    }
}
