<?php

namespace App\Enums\OperationalCosts;

/**
 * Why a received purchase-order line is NOT made into a cost layer. Every one of them is a line the module leaves alone and says so: nothing is ever layered at a cost of zero, at
 * a guessed cost, in a guessed currency or at a guessed place.
 */
enum PurchaseLineRefusal: string
{
    /** A description-only line, or a product the catalogue no longer has. */
    case NoProduct = 'no_product';
    /** The order belongs to a vendor: vendor stock and its cost are the vendor's. */
    case VendorOrder = 'vendor_order';
    case VendorProduct = 'vendor_product';
    case NotPhysical = 'not_physical';
    /** An order line names no variant and a receipt raises the product's stock only, while a sale takes its units from the layers of its own variant. */
    case VariantProduct = 'variant_product';
    /** The goods of a product assigned to M5AZN are never ours: its cost is the supplier's price per order. */
    case M5aznAssigned = 'm5azn_assigned';
    case CostMissing = 'cost_missing';
    case CurrencyUnknown = 'currency_unknown';
    case NoExchangeRate = 'no_exchange_rate';
    /** The place the sale will take the goods from does not exist (yet) as an inventory location. */
    case NoLocation = 'no_location';
    /** With a cutover date: the line was not received in one go and nothing says how many of its units came in before the date. */
    case DateUncertain = 'date_uncertain';

    /** What it means, in the words the report prints. */
    public function meaning(): string
    {
        return match ($this) {
            self::NoProduct => 'the line names no catalogue product (a description only, or the product no longer exists)',
            self::VendorOrder => 'the purchase order belongs to a vendor: vendor stock is the vendor\'s',
            self::VendorProduct => 'the product belongs to a vendor, not to the platform',
            self::NotPhysical => 'the product is not a physical product',
            self::VariantProduct => 'the product has variants: a purchase order line names no variant, so its units cannot be put in the right variant\'s layer',
            self::M5aznAssigned => 'the product is assigned to M5AZN: its goods are bought per order from the supplier, not kept as stock',
            self::CostMissing => 'the line has no unit cost, or a cost of zero',
            self::CurrencyUnknown => 'the purchase order has no (recognisable) currency',
            self::NoExchangeRate => 'the purchase order\'s currency has no exchange rate to the reporting currency',
            self::NoLocation => 'the stock location the goods would sit at does not exist yet',
            self::DateUncertain => 'the cutover date falls inside what was received and the receipt dates cannot be told apart',
        };
    }
}
