<?php

namespace App\Services\SalesChannels\Data;

use App\Services\OperationalCosts\Support\Money;

/** What we want a listing to show: any of the fields may be null, and a null is "leave it alone", never "set it to nothing". */
final readonly class PublishUpdate
{
    public function __construct(
        public int $mappingId,
        public string $listingKey,
        public ?string $sku,
        public ?string $barcode,
        public ?int $quantity = null,
        public ?Money $price = null,
        public ?Money $listPrice = null,
        public ?string $currency = null,
        public ?string $fulfillmentMode = null,
    ) {
    }
}
