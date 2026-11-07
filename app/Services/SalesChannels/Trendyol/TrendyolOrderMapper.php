<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\RemoteOrderLine;
use Carbon\CarbonImmutable;

/**
 * Turns a Trendyol shipment package (the Order V2 model, as the `/v2/orders`, `/orders/stream` and webhook payloads carry it) into the application's own order shape.
 *
 * What it decides, and why:
 *  - One package is one record: `orderNumber` + `shipmentPackageId`. A split or a partial cancellation gives the remainder a NEW package id; the package that was replaced
 *    (`UnPacked`, `Cancelled`, `UnSupplied`) is terminal and all its lines count as cancelled, so the same goods are never sold twice.
 *  - Money. `lineGrossAmount` is the unit price before discounts and `lineUnitPrice` the unit price after them. The seller's own discount reduces revenue. A discount Trendyol
 *    funds (`TyDiscount`) is reimbursed to the seller in the settlement, so it is NOT deducted here (kept in the snapshot); the settlement rows show the actual figure and
 *    the profit report separates estimated from actual. Prices on Trendyol are consumer prices: tax-inclusive unless the account says otherwise.
 *  - Per-line discounts come from `discountDetails` (one element per unit) when every line has them in full; otherwise the package totals are shared out in proportion to
 *    the line's gross, so the order's totals are exact and only the split between lines is approximate.
 *  - Whether Trendyol or the seller pays the carrier is read per package: `whoPays = 1` is the seller-pays agreement; no field means Trendyol pays.
 *  - A buyer's name, e-mail, phone, identity and tax numbers and street address are never copied; only the city and country, to price a delivery.
 *  - `orderDate` is documented as GMT+3 while every other timestamp is UTC; the configured offset corrects it (verify on a stage order).
 */
class TrendyolOrderMapper
{
    /** @var list<string> */
    private const TERMINAL_UNSOLD = ['Cancelled', 'UnSupplied', 'UnPacked'];

    /**
     * @param array<string, mixed> $package one element of `content[]`
     * @throws \InvalidArgumentException when the package has no id to identify it by
     */
    public function map(array $package, SalesChannelAccount $account): RemoteOrder
    {
        $packageId = (string) ($package['shipmentPackageId'] ?? '');
        $orderNumber = (string) ($package['orderNumber'] ?? '');
        if ($packageId === '' || $orderNumber === '') {
            throw new \InvalidArgumentException('A Trendyol package needs an orderNumber and a shipmentPackageId.');
        }

        $externalStatus = (string) ($package['shipmentPackageStatus'] ?? $package['status'] ?? 'Unknown');
        $packageSold = !in_array($externalStatus, self::TERMINAL_UNSOLD, true);
        $rawLines = array_values(array_filter((array) ($package['lines'] ?? []), 'is_array'));
        $currency = strtoupper((string) ($package['currencyCode'] ?? $rawLines[0]['currencyCode'] ?? $account->currency ?? ''));
        $includesTax = (bool) $account->setting('prices_include_tax', true);

        $discounts = $this->discounts($package, $rawLines);
        $lines = [];
        $itemsTotal = Money::zero();
        $sellerDiscountTotal = Money::zero();
        $tyDiscountTotal = Money::zero();
        $taxTotal = Money::zero();

        foreach ($rawLines as $index => $line) {
            $quantity = max(0, (int) ($line['quantity'] ?? 0));
            $gross = Money::of((string) ($line['lineGrossAmount'] ?? 0))->times($quantity);
            $sellerDiscount = $discounts[$index]['seller'];
            $tyDiscount = $discounts[$index]['ty'];
            $tax = $this->includedTax($gross->minus($sellerDiscount), $line['vatRate'] ?? null, $includesTax);
            $lineStatus = (string) ($line['orderLineItemStatusName'] ?? '');
            $cancelled = !$packageSold || in_array($lineStatus, self::TERMINAL_UNSOLD, true) ? $quantity : 0;

            $itemsTotal = $itemsTotal->plus($gross);
            $sellerDiscountTotal = $sellerDiscountTotal->plus($sellerDiscount);
            $tyDiscountTotal = $tyDiscountTotal->plus($tyDiscount);
            $taxTotal = $taxTotal->plus($tax);

            $lines[] = new RemoteOrderLine(
                lineId: (string) ($line['lineId'] ?? ''),
                sku: $this->nullable($line['stockCode'] ?? null),
                barcode: $this->nullable($line['barcode'] ?? null),
                externalProductId: $this->nullable($line['contentId'] ?? null),
                title: (string) ($line['productName'] ?? ''),
                quantity: $quantity,
                quantityCancelled: $cancelled,
                lineTotal: $gross,
                discount: $sellerDiscount,
                tax: $tax,
                shipping: null,
                status: $lineStatus !== '' ? strtolower($lineStatus) : strtolower($externalStatus),
                snapshot: array_filter([
                    'content_id' => $line['contentId'] ?? null,
                    'vat_rate' => $line['vatRate'] ?? null,
                    'commission_rate' => $line['commission'] ?? null,
                    'ty_discount' => $tyDiscount->isZero() ? null : $tyDiscount->toStorage(),
                    'unit_price_after_discounts' => $line['lineUnitPrice'] ?? null,
                    'business_unit' => $line['businessUnit'] ?? null,
                    'cancelled_by' => $line['cancelledBy'] ?? null,
                    'cancel_reason' => $line['cancelReason'] ?? null,
                    'cancel_reason_code' => $line['cancelReasonCode'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== ''),
            );
        }

        $history = $this->history($package);
        $sellerPays = (int) ($package['whoPays'] ?? 0) === 1;

        return new RemoteOrder(
            orderId: $orderNumber,
            packageId: $packageId,
            externalStatus: $externalStatus,
            status: $this->status($externalStatus),
            fulfillmentMode: $this->mode($package),
            fulfillmentChannelRaw: $sellerPays ? 'seller_pays' : 'trendyol_pays',
            currency: $currency,
            itemsTotal: $itemsTotal,
            discountTotal: $sellerDiscountTotal,
            shippingTotal: Money::zero(),
            taxTotal: $taxTotal,
            orderTotal: isset($package['packageTotalPrice']) ? Money::of((string) $package['packageTotalPrice']) : $itemsTotal->minus($sellerDiscountTotal)->minus($tyDiscountTotal),
            pricesIncludeTax: $includesTax,
            purchasedAt: $this->orderDate($package['orderDate'] ?? null),
            updatedAt: $this->instant($package['lastModifiedDate'] ?? null),
            lines: $lines,
            isCod: (bool) ($package['isCod'] ?? false),
            paymentMethod: $this->nullable($package['paymentMethod'] ?? null),
            carrier: $this->nullable($package['cargoProviderName'] ?? null),
            trackingNumber: $this->nullable($sellerPays ? ($package['cargoSenderNumber'] ?? $package['cargoTrackingNumber'] ?? null) : ($package['cargoTrackingNumber'] ?? null)),
            trackingUrl: $this->nullable($package['cargoTrackingLink'] ?? null),
            shippedAt: $history['Shipped'] ?? null,
            deliveredAt: $history['Delivered'] ?? null,
            cancelledAt: $history['Cancelled'] ?? $history['UnSupplied'] ?? null,
            destination: array_filter([
                'city' => $package['shipmentAddress']['city'] ?? null,
                'country' => $package['shipmentAddress']['countryCode'] ?? $package['orderCountryCode'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            snapshot: array_filter([
                'shipment_package_id' => $packageId,
                'order_number' => $orderNumber,
                'shipment_number' => $package['shipmentNumber'] ?? null,
                'channel_id' => $package['channelId'] ?? null,
                'who_pays' => $sellerPays ? 'seller' : 'trendyol',
                'cargo_provider' => $package['cargoProviderName'] ?? null,
                'cargo_tracking_number' => $package['cargoTrackingNumber'] ?? null,
                'cargo_sender_number' => $package['cargoSenderNumber'] ?? null,
                'delivery_address_type' => $package['deliveryAddressType'] ?? null,
                'fast_delivery_type' => ($package['fastDeliveryType'] ?? '') !== '' ? $package['fastDeliveryType'] : null,
                'delivered_by_service' => $package['deliveredByService'] ?? null,
                'created_by' => $package['createdBy'] ?? null,
                'origin_package_ids' => $package['originPackageIds'] ?? null,
                'invoice_status' => $package['invoiceStatus'] ?? null,
                'commercial' => $package['commercial'] ?? null,
                'micro_export' => $package['micro'] ?? null,
                'is_4p' => $package['is4P'] ?? null,
                'three_p_by_trendyol' => $package['3pByTrendyol'] ?? null,
                'agreed_delivery_date' => $this->instant($package['agreedDeliveryDate'] ?? null)?->toIso8601String(),
                'package_gross' => $package['packageGrossAmount'] ?? null,
                'package_seller_discount' => $package['packageSellerDiscount'] ?? null,
                'package_ty_discount' => $package['packageTyDiscount'] ?? null,
                'package_total_price' => $package['packageTotalPrice'] ?? null,
                'discount_source' => $discounts['source'],
            ], static fn ($value): bool => $value !== null && $value !== []),
            replacesPackageId: $this->replaces($package),
        );
    }

    public function status(string $packageStatus): ChannelOrderStatus
    {
        return match ($packageStatus) {
            'Awaiting', 'Verified' => ChannelOrderStatus::Pending,
            'Created' => ChannelOrderStatus::New,
            'Picking', 'Invoiced' => ChannelOrderStatus::Processing,
            'Shipped', 'AtCollectionPoint' => ChannelOrderStatus::Shipped,
            'Delivered' => ChannelOrderStatus::Delivered,
            'Returned' => ChannelOrderStatus::Returned,
            'UnDelivered' => ChannelOrderStatus::Failed,
            'Cancelled', 'UnSupplied', 'UnPacked' => ChannelOrderStatus::Cancelled,
            default => ChannelOrderStatus::AwaitingReview,
        };
    }

    /**
     * A package Trendyol or a partner handles end to end is never ours to ship. The Gulf seller model has the seller prepare and hand over the parcel (to its own carrier, or
     * to the one Trendyol sends), so an ordinary package is merchant-managed; a package carrying a flag whose meaning the documentation does not state is Unknown, which no rule routes.
     *
     * @param array<string, mixed> $package
     */
    private function mode(array $package): FulfillmentMode
    {
        if (($package['is4P'] ?? false) === true || ($package['3pByTrendyol'] ?? false) === true || ($package['micro'] ?? false) === true) {
            return FulfillmentMode::Unknown;
        }

        return FulfillmentMode::MerchantManaged;
    }

    /**
     * @param array<string, mixed> $package
     * @param list<array<string, mixed>> $lines
     * @return array<int|string, array{seller: Money, ty: Money}|string> the per-line discounts by index, and 'source' => how they were derived
     */
    private function discounts(array $package, array $lines): array
    {
        $perUnit = [];
        foreach ($lines as $index => $line) {
            $details = array_values(array_filter((array) ($line['discountDetails'] ?? []), 'is_array'));
            if ($details === [] || count($details) !== max(0, (int) ($line['quantity'] ?? 0))) {
                $perUnit = null;
                break;
            }
            $perUnit[$index] = [
                'seller' => Money::sum(array_map(static fn (array $detail): Money => Money::of((string) ($detail['lineItemSellerDiscount'] ?? 0)), $details)),
                'ty' => Money::sum(array_map(static fn (array $detail): Money => Money::of((string) ($detail['lineItemTyDiscount'] ?? 0)), $details)),
            ];
        }
        if ($perUnit !== null && $lines !== []) {
            return $perUnit + ['source' => 'per_unit_details'];
        }

        $weights = array_map(static fn (array $line): Money => Money::of((string) ($line['lineGrossAmount'] ?? 0))->times(max(0, (int) ($line['quantity'] ?? 0))), $lines);
        $sellerShares = Money::of((string) ($package['packageSellerDiscount'] ?? 0))->allocate($weights);
        $tyShares = Money::of((string) ($package['packageTyDiscount'] ?? 0))->allocate($weights);
        $shared = [];
        foreach (array_keys($lines) as $index) {
            $shared[$index] = ['seller' => $sellerShares[$index], 'ty' => $tyShares[$index]];
        }

        return $shared + ['source' => 'package_allocated'];
    }

    /** The VAT contained in a tax-inclusive amount: amount × rate ÷ (100 + rate). Zero when prices exclude tax here or the rate is unknown. */
    private function includedTax(Money $amount, mixed $vatRate, bool $includesTax): Money
    {
        if (!$includesTax || $vatRate === null || !is_numeric($vatRate)) {
            return Money::zero();
        }

        return $amount->times((string) $vatRate)->dividedBy(100 + (float) $vatRate);
    }

    /**
     * The first time each status was reached, from `packageHistories` (UTC).
     *
     * @param array<string, mixed> $package
     * @return array<string, CarbonImmutable>
     */
    private function history(array $package): array
    {
        $reached = [];
        foreach ((array) ($package['packageHistories'] ?? []) as $entry) {
            $status = (string) ($entry['status'] ?? '');
            $time = $this->instant($entry['createdDate'] ?? null);
            if ($status !== '' && $time !== null && (!isset($reached[$status]) || $time->lt($reached[$status]))) {
                $reached[$status] = $time;
            }
        }

        return $reached;
    }

    /** @param array<string, mixed> $package */
    private function replaces(array $package): ?string
    {
        if (!in_array($package['createdBy'] ?? null, ['split', 'cancel', 'transfer'], true)) {
            return null;
        }
        $origin = array_values((array) ($package['originPackageIds'] ?? []));

        return $origin === [] ? null : (string) $origin[0];
    }

    private function orderDate(mixed $milliseconds): ?CarbonImmutable
    {
        $instant = $this->instant($milliseconds);

        return $instant?->subMilliseconds((int) config('sales_channels.trendyol.order_date_offset_milliseconds', 0));
    }

    private function instant(mixed $milliseconds): ?CarbonImmutable
    {
        return is_numeric($milliseconds) ? CarbonImmutable::createFromTimestampMsUTC((int) $milliseconds) : null;
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
