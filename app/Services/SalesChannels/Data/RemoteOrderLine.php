<?php

namespace App\Services\SalesChannels\Data;

use App\Services\OperationalCosts\Support\Money;

/**
 * One line of a channel order. Amounts are for the whole line (unit price times quantity ordered), in the order's currency;
 * `discount` and `tax` are the parts of that. Cancelled units stay in `quantity` and are counted in `quantityCancelled`.
 */
final readonly class RemoteOrderLine
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $lineId,
        public ?string $sku,
        public ?string $barcode,
        public ?string $externalProductId,
        public string $title,
        public int $quantity,
        public int $quantityCancelled,
        public Money $lineTotal,
        public Money $discount,
        public Money $tax,
        public ?Money $shipping,
        public string $status,
        public ?Money $weightKg = null,
        public array $snapshot = [],
    ) {
    }

    public function unitPrice(): Money
    {
        return $this->quantity > 0 ? $this->lineTotal->dividedBy($this->quantity) : Money::zero();
    }
}
