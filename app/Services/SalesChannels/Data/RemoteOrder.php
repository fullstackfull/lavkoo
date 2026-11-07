<?php

namespace App\Services\SalesChannels\Data;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * An order (or one shipment package of an order) as a channel reports it, in the application's own vocabulary.
 *
 * `orderId` + `packageId` identify it ('' where the channel has no package id). The raw channel status is kept beside the normalised one. The snapshot holds what is worth
 * keeping for diagnosis with the buyer's personal details already removed; `destination` carries only a city and a country, for pricing a delivery.
 */
final readonly class RemoteOrder
{
    /**
     * @param list<RemoteOrderLine> $lines
     * @param array{city?: string|null, country?: string|null} $destination
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public string $orderId,
        public string $packageId,
        public string $externalStatus,
        public ChannelOrderStatus $status,
        public FulfillmentMode $fulfillmentMode,
        public ?string $fulfillmentChannelRaw,
        public string $currency,
        public Money $itemsTotal,
        public Money $discountTotal,
        public Money $shippingTotal,
        public Money $taxTotal,
        public Money $orderTotal,
        public bool $pricesIncludeTax,
        public ?CarbonInterface $purchasedAt,
        public ?CarbonInterface $updatedAt,
        public array $lines,
        public bool $isCod = false,
        public ?string $paymentMethod = null,
        public ?string $carrier = null,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public ?CarbonInterface $shippedAt = null,
        public ?CarbonInterface $deliveredAt = null,
        public ?CarbonInterface $cancelledAt = null,
        public array $destination = [],
        public array $snapshot = [],
        public ?string $replacesPackageId = null,
    ) {
    }

    /** What changed in the order from the channel's point of view: any change here is a reason to look at the order again. */
    public function hash(): string
    {
        return hash('sha256', json_encode([
            $this->externalStatus, $this->currency, $this->orderTotal->toStorage(), $this->trackingNumber, $this->carrier,
            array_map(static fn (RemoteOrderLine $line): array => [$line->lineId, $line->quantity, $line->quantityCancelled, $line->lineTotal->toStorage(), $line->status], $this->lines),
        ]));
    }
}
