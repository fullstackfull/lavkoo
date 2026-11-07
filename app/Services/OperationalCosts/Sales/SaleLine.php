<?php

namespace App\Services\OperationalCosts\Sales;

use App\Services\OperationalCosts\Support\Money;

/**
 * One line of a sale, in the reporting currency, whatever the sale came from (a storefront order line or a channel order line).
 *
 * `revenue` is what the customer paid for the goods of this line, after the line's own discount and without the tax in it. `refunded` is
 * the part of that handed back. `costBasis` says where the cost of the goods comes from: our own stock (cost layers), a supplier who
 * charges us per order (dropshipping), or nowhere we know of.
 */
final readonly class SaleLine
{
    public const BASIS_OWNED_STOCK = 'owned_stock';
    public const BASIS_SUPPLIER = 'supplier_cost';
    /** The goods are a vendor's, not the platform's: a line of a channel order that was made into an internal order for a vendor's product. Never costed from our layers or estimated. */
    public const BASIS_VENDOR_STOCK = 'vendor_stock';
    public const BASIS_UNKNOWN = 'unknown';

    public function __construct(
        public string $targetType,
        public int $targetId,
        public ?int $productId,
        public string $variantKey,
        public ?string $sku,
        public int $quantity,
        public Money $revenue,
        public Money $refunded,
        public ?int $inventoryLocationId,
        public string $costBasis,
        public ?string $provider = null,
        public ?Money $weightKg = null,
        public int $quantityReturned = 0,
    ) {
    }

    public function netRevenue(): Money
    {
        return $this->revenue->minus($this->refunded);
    }
}
