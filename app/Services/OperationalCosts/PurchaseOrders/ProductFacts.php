<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

/** What the layers need to know about a catalogue product: whose it is, whether it is physical, whether it has variants and who fulfils it. Read from the platform's products table. */
final readonly class ProductFacts
{
    public function __construct(
        public int $id,
        public ?string $name,
        public int $currentStock,
        public bool $physical,
        public bool $platformOwned,
        public bool $hasVariants,
        public ?string $fulfillmentProvider,
    ) {
    }
}
