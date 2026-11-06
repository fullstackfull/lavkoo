<?php

namespace App\Services\SalesChannels\Routing;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;

/** Everything a routing decision may look at, and nothing else: the decision is a function of this, so it can be explained and repeated. */
final readonly class RoutingContext
{
    /** @param list<RoutingLine> $lines */
    public function __construct(
        public int $mappingId,
        public string $channel,
        public int $accountId,
        public FulfillmentMode $mode,
        public ChannelOrderStatus $status,
        public ?string $channelPaysCarrier,
        public bool $isCod,
        public bool $allowCod,
        public ?string $country,
        public ?string $city,
        public ?string $orderTotal,
        public ?string $currentExecutionPolicy,
        public bool $alreadyDispatched,
        public array $lines,
        public ExecutionPolicy $accountPolicy = ExecutionPolicy::ManualReview,
    ) {
    }

    /** @return list<RoutingLine> */
    public function activeLines(): array
    {
        return array_values(array_filter($this->lines, static fn (RoutingLine $line): bool => !$line->cancelled));
    }

    /** @return array<string, mixed> the facts that went into the decision, for the record */
    public function toInputs(): array
    {
        return [
            'channel' => $this->channel, 'account_id' => $this->accountId, 'fulfillment_mode' => $this->mode->value, 'order_status' => $this->status->value,
            'account_policy' => $this->accountPolicy->value, 'carrier_payer' => $this->channelPaysCarrier, 'cod' => $this->isCod, 'country' => $this->country, 'city' => $this->city, 'order_total' => $this->orderTotal,
            'lines' => array_map(static fn (RoutingLine $line): array => [
                'line' => $line->externalLineId, 'product_id' => $line->productId, 'sku' => $line->sku, 'quantity' => $line->quantity, 'location_id' => $line->locationId,
                'provider' => $line->provider, 'stock_confirmed' => $line->stockConfirmed, 'cancelled' => $line->cancelled,
            ], $this->lines),
        ];
    }
}
