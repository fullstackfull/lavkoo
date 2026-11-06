<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\RemoteOrderLine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns an Orders API v2026-01-01 `Order` into the application's own order shape.
 *
 * What it decides, and why:
 *  - FBA or FBM comes from `fulfillment.fulfilledBy` (request FULFILLMENT, or it is absent) together with `salesChannel.channelName`. Only
 *    MERCHANT on an AMAZON channel is FBM, the one kind we may ship; AMAZON is FBA; anything else (a non-Amazon channel's order fulfilled through Amazon,
 *    a missing field) is UNKNOWN and is never treated as FBM.
 *  - Money comes from the item `proceeds.breakdowns`. When a TAX breakdown exists the ITEM figure excludes tax; when none does it includes it.
 *  - A buyer's name, address, phone and e-mail are never copied: only the city and country, to price a delivery.
 */
class AmazonOrderMapper
{
    /** @param array<string, mixed> $order one element of `orders[]` (or the `order` of getOrder) */
    public function map(array $order, SalesChannelAccount $account): RemoteOrder
    {
        $fulfillment = (array) ($order['fulfillment'] ?? []);
        $items = array_values(array_filter((array) ($order['orderItems'] ?? []), 'is_array'));
        $packages = array_values(array_filter((array) ($order['packages'] ?? []), 'is_array'));
        $externalStatus = (string) ($fulfillment['fulfillmentStatus'] ?? 'UNKNOWN');
        $mode = $this->mode($fulfillment, (array) ($order['salesChannel'] ?? []));

        $currency = $this->currency($order, $items) ?? (string) ($account->currency ?? '');
        $hasTax = false;
        $itemsTotal = Money::zero();
        $shippingTotal = Money::zero();
        $discountTotal = Money::zero();
        $taxTotal = Money::zero();
        $lines = [];
        $orderCancelled = $externalStatus === 'CANCELLED';

        foreach ($items as $item) {
            $parts = $this->breakdown($item);
            $hasTax = $hasTax || $parts['has_tax'];
            $itemsTotal = $itemsTotal->plus($parts['item']);
            $shippingTotal = $shippingTotal->plus($parts['shipping']);
            $discountTotal = $discountTotal->plus($parts['discount']);
            $taxTotal = $taxTotal->plus($parts['tax']);

            $quantity = (int) ($item['quantityOrdered'] ?? 0);
            $executed = (array) ($item['cancellation']['cancellationExecution'] ?? []);
            $cancelled = $orderCancelled || $executed !== [] ? $quantity : 0;
            $product = (array) ($item['product'] ?? []);

            $lines[] = new RemoteOrderLine(
                lineId: (string) ($item['orderItemId'] ?? ''),
                sku: $this->nullable($product['sellerSku'] ?? null),
                barcode: null,
                externalProductId: $this->nullable($product['asin'] ?? null),
                title: (string) ($product['title'] ?? ''),
                quantity: $quantity,
                quantityCancelled: $cancelled,
                lineTotal: $parts['item'],
                discount: $parts['discount'],
                tax: $parts['tax'],
                shipping: $parts['shipping'],
                status: $cancelled >= $quantity && $quantity > 0 ? 'cancelled' : 'open',
                snapshot: array_filter([
                    'asin' => $product['asin'] ?? null,
                    'condition' => $product['condition']['conditionType'] ?? null,
                    'quantity_fulfilled' => $item['fulfillment']['quantityFulfilled'] ?? null,
                    'quantity_unfulfilled' => $item['fulfillment']['quantityUnfulfilled'] ?? null,
                    'cancelled_by' => $executed['cancelledBy'] ?? null,
                    'cancel_reason' => $executed['cancelReason'] ?? null,
                ], static fn ($value): bool => $value !== null),
            );
        }

        $grandTotal = $order['proceeds']['grandTotal']['amount'] ?? null;
        $orderTotal = $grandTotal !== null ? Money::of((string) $grandTotal) : $itemsTotal->plus($shippingTotal)->minus($discountTotal)->plus($hasTax ? $taxTotal : Money::zero());
        $tracking = $packages[0] ?? [];
        $shippedAt = $this->earliest(array_map(fn (array $package): ?CarbonImmutable => $this->time($package['shipTime'] ?? null), $packages));
        $delivered = $packages !== [] && count(array_filter($packages, static fn (array $package): bool => ($package['packageStatus']['status'] ?? null) === 'DELIVERED')) === count($packages);

        return new RemoteOrder(
            orderId: (string) $order['orderId'],
            packageId: '',
            externalStatus: $externalStatus,
            status: $delivered ? ChannelOrderStatus::Delivered : $this->status($externalStatus),
            fulfillmentMode: $mode,
            fulfillmentChannelRaw: match ($fulfillment['fulfilledBy'] ?? null) {
                'MERCHANT' => 'MFN',
                'AMAZON' => 'AFN',
                default => null,
            },
            currency: $currency,
            itemsTotal: $itemsTotal,
            discountTotal: $discountTotal,
            shippingTotal: $shippingTotal,
            taxTotal: $taxTotal,
            orderTotal: $orderTotal,
            pricesIncludeTax: !$hasTax,
            purchasedAt: $this->time($order['createdTime'] ?? null),
            updatedAt: $this->time($order['lastUpdatedTime'] ?? null),
            lines: $lines,
            isCod: $this->isCashOnDelivery($order),
            paymentMethod: $this->paymentMethod($order),
            carrier: $this->nullable($tracking['carrier'] ?? null),
            trackingNumber: $this->nullable($tracking['trackingNumber'] ?? null),
            shippedAt: $shippedAt,
            deliveredAt: $delivered ? $this->latest(array_map(fn (array $package): ?CarbonImmutable => $this->time($package['shipTime'] ?? null), $packages)) : null,
            cancelledAt: $orderCancelled ? $this->time($order['lastUpdatedTime'] ?? null) : null,
            destination: array_filter([
                'city' => $order['recipient']['deliveryAddress']['city'] ?? null,
                'country' => $order['recipient']['deliveryAddress']['countryCode'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            snapshot: array_filter([
                'amazon_order_id' => $order['orderId'],
                'marketplace_id' => $order['salesChannel']['marketplaceId'] ?? null,
                'channel_name' => $order['salesChannel']['channelName'] ?? null,
                'fulfilled_by' => $fulfillment['fulfilledBy'] ?? null,
                'service_level' => $fulfillment['fulfillmentServiceLevel'] ?? null,
                'ship_by' => $fulfillment['shipByWindow'] ?? null,
                'deliver_by' => $fulfillment['deliverByWindow'] ?? null,
                'programs' => $order['programs'] ?? null,
                'associated_orders' => $order['associatedOrders'] ?? null,
                'packages' => array_map(static fn (array $package): array => array_filter([
                    'reference' => $package['packageReferenceId'] ?? null,
                    'status' => $package['packageStatus']['status'] ?? null,
                    'carrier' => $package['carrier'] ?? null,
                    'tracking' => $package['trackingNumber'] ?? null,
                ]), $packages),
            ], static fn ($value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * @param array<string, mixed> $fulfillment
     * @param array<string, mixed> $salesChannel
     */
    private function mode(array $fulfillment, array $salesChannel): FulfillmentMode
    {
        $fulfilledBy = $fulfillment['fulfilledBy'] ?? null;
        $channel = $salesChannel['channelName'] ?? null;

        return match (true) {
            $fulfilledBy === 'MERCHANT' && $channel === 'AMAZON' => FulfillmentMode::Fbm,
            $fulfilledBy === 'AMAZON' && $channel === 'AMAZON' => FulfillmentMode::Fba,
            default => FulfillmentMode::Unknown,
        };
    }

    private function status(string $fulfillmentStatus): ChannelOrderStatus
    {
        return match ($fulfillmentStatus) {
            'PENDING_AVAILABILITY', 'PENDING' => ChannelOrderStatus::Pending,
            'UNSHIPPED' => ChannelOrderStatus::New,
            'PARTIALLY_SHIPPED' => ChannelOrderStatus::Processing,
            'SHIPPED' => ChannelOrderStatus::Shipped,
            'CANCELLED' => ChannelOrderStatus::Cancelled,
            'UNFULFILLABLE' => ChannelOrderStatus::Failed,
            default => ChannelOrderStatus::AwaitingReview,
        };
    }

    /**
     * @param array<string, mixed> $item
     * @return array{item: Money, shipping: Money, discount: Money, tax: Money, has_tax: bool}
     */
    private function breakdown(array $item): array
    {
        $sums = ['ITEM' => Money::zero(), 'SHIPPING' => Money::zero(), 'DISCOUNT' => Money::zero(), 'TAX' => Money::zero()];
        $hasTax = false;
        foreach ((array) ($item['proceeds']['breakdowns'] ?? []) as $breakdown) {
            $type = (string) ($breakdown['type'] ?? '');
            $amount = isset($breakdown['subtotal']['amount']) ? Money::of((string) $breakdown['subtotal']['amount']) : null;
            if ($amount === null || !isset($sums[$type])) {
                continue;
            }
            $sums[$type] = $sums[$type]->plus($type === 'DISCOUNT' ? $amount->abs() : $amount);
            $hasTax = $hasTax || $type === 'TAX';
        }

        // With no proceeds in the response (includedData did not ask for them) fall back to the unit price shown on the site.
        if ($sums['ITEM']->isZero() && isset($item['product']['price']['unitPrice']['amount'])) {
            $sums['ITEM'] = Money::of((string) $item['product']['price']['unitPrice']['amount'])->times((int) ($item['quantityOrdered'] ?? 0));
        }

        return ['item' => $sums['ITEM'], 'shipping' => $sums['SHIPPING'], 'discount' => $sums['DISCOUNT'], 'tax' => $sums['TAX'], 'has_tax' => $hasTax];
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    private function currency(array $order, array $items): ?string
    {
        $code = $order['proceeds']['grandTotal']['currencyCode'] ?? null;
        foreach ($items as $item) {
            $code ??= $item['proceeds']['proceedsTotal']['currencyCode'] ?? $item['product']['price']['unitPrice']['currencyCode'] ?? null;
        }

        return $code === null ? null : strtoupper((string) $code);
    }

    /** @param array<string, mixed> $order */
    private function isCashOnDelivery(array $order): bool
    {
        foreach ((array) ($order['payment']['paymentExecutions'] ?? []) as $execution) {
            if (strcasecmp((string) ($execution['paymentMethod'] ?? ''), 'CashOnDelivery') === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $order */
    private function paymentMethod(array $order): ?string
    {
        $method = $order['payment']['paymentExecutions'][0]['paymentMethod'] ?? null;

        return $method === null ? null : (string) $method;
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value);
    }

    /** @param list<CarbonImmutable|null> $times */
    private function earliest(array $times): ?CarbonInterface
    {
        $times = array_filter($times);

        return $times === [] ? null : collect($times)->sort()->first();
    }

    /** @param list<CarbonImmutable|null> $times */
    private function latest(array $times): ?CarbonInterface
    {
        $times = array_filter($times);

        return $times === [] ? null : collect($times)->sort()->last();
    }
}
