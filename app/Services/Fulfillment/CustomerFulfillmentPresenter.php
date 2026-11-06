<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\SecretRedactor;

/**
 * What a customer is allowed to see about the shipments of their order.
 *
 * The customer does not need to understand the fulfillment infrastructure, so this exposes shipment
 * number, plain-language status, the items in it and a tracking number — and nothing about providers:
 * no provider name, no internal reference, no error text.
 */
class CustomerFulfillmentPresenter
{
    private const MIXED_NOTICE_KEY = 'mixed_provider_order_notice';

    public function __construct(private readonly FulfillmentAggregator $aggregator)
    {
    }

    /**
     * @return array{notice: string|null, status: string, status_label: string, shipments: list<array<string, mixed>>}|null
     *         null when the order has no provider shipments (the screens then render exactly as before)
     */
    public function forOrder(int $orderId): ?array
    {
        if (!FulfillmentSchema::ready()) {
            return null;
        }

        $summary = $this->aggregator->summarize($orderId);
        if (!$summary->hasProviderFulfillments()) {
            return null;
        }

        $shipments = [];
        $number = 0;
        foreach ($summary->fulfillments->where('status', '!=', FulfillmentStatus::Cancelled) as $fulfillment) {
            $number++;
            $shipments[] = $this->shipment($fulfillment, $number);
        }

        return [
            'notice' => count($shipments) > 1 || $summary->mixed ? translate(self::MIXED_NOTICE_KEY) : null,
            'status' => $summary->status,
            'status_label' => translate('fulfillment_summary_' . $summary->status),
            'shipments' => $shipments,
        ];
    }

    /** @return array<string, mixed> */
    private function shipment(ProviderFulfillment $fulfillment, int $number): array
    {
        $status = $fulfillment->status;

        return [
            'shipment_number' => $number,
            'status' => $this->customerStatus($status),
            'status_label' => translate('fulfillment_customer_status_' . $this->customerStatus($status)),
            'tracking_number' => $status->isAcceptedByProvider() ? $fulfillment->tracking_number : null,
            'tracking_url' => $status->isAcceptedByProvider() ? $fulfillment->tracking_url : null,
            'shipped_at' => $fulfillment->shipped_at?->toIso8601String(),
            'delivered_at' => $fulfillment->delivered_at?->toIso8601String(),
            'items' => $fulfillment->items->map(static fn (ProviderFulfillmentItem $item): array => [
                'name' => SecretRedactor::truncate($item->name, 191),
                'quantity' => $item->quantity,
            ])->values()->all(),
        ];
    }

    /** Provider-side detail collapsed to the few states a customer understands. */
    private function customerStatus(FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Delivered => 'delivered',
            FulfillmentStatus::Returned => 'returned',
            FulfillmentStatus::InTransit, FulfillmentStatus::OutForDelivery, FulfillmentStatus::PartiallyDelivered => 'on_the_way',
            FulfillmentStatus::DeliveryPostponed, FulfillmentStatus::DeliveryFailed, FulfillmentStatus::NeedsAttention => 'delayed',
            FulfillmentStatus::Submitted, FulfillmentStatus::Processing => 'preparing',
            default => 'confirmed',
        };
    }
}
