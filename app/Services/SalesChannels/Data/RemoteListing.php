<?php

namespace App\Services\SalesChannels\Data;

use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/** A listing as the channel reports it. Reading one never creates or changes a product of ours. */
final readonly class RemoteListing
{
    /**
     * @param array<string, mixed> $snapshot the parts of the channel's record worth keeping for diagnosis, without personal data
     */
    public function __construct(
        public string $listingKey,
        public ?string $sku,
        public ?string $externalId,
        public ?string $barcode,
        public ?string $parentId,
        public string $title,
        public ?string $fulfillmentMode,
        public ?string $status,
        public ?Money $price,
        public ?string $currency,
        public ?int $quantity,
        public ?CarbonInterface $updatedAt,
        public array $snapshot = [],
    ) {
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([$this->listingKey, $this->sku, $this->externalId, $this->barcode, $this->title, $this->status, $this->price?->toStorage(), $this->quantity, $this->fulfillmentMode]));
    }
}
