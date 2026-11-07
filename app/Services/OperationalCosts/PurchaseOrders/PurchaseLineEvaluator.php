<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use App\Services\OperationalCosts\Support\StockLocationResolver;
use InvalidArgumentException;

/**
 * Whether the goods of a received purchase-order line may be made into a cost layer, and where. A line is made into a layer only when everything the layer needs is known:
 * a catalogue product of the platform's own that is physical and has no variants, a cost above zero, a currency with a rate to the reporting currency, and the place the sale will
 * take the units from. Otherwise it is refused for one named reason and nothing is written: there is never a layer at zero, at a guessed rate or at a guessed place.
 */
class PurchaseLineEvaluator
{
    public function __construct(
        private readonly FxConverter $fx,
        private readonly StockLocationResolver $locations,
    ) {
    }

    public function verdict(PurchaseLine $line, ?ProductFacts $product): LineVerdict
    {
        $refusal = match (true) {
            $line->orderSellerId !== null => PurchaseLineRefusal::VendorOrder,
            $line->productId === null || $product === null => PurchaseLineRefusal::NoProduct,
            !$product->platformOwned => PurchaseLineRefusal::VendorProduct,
            !$product->physical => PurchaseLineRefusal::NotPhysical,
            $product->hasVariants => PurchaseLineRefusal::VariantProduct,
            $product->fulfillmentProvider === ProviderCode::M5azn->value => PurchaseLineRefusal::M5aznAssigned,
            default => null,
        };
        if ($refusal !== null) {
            return LineVerdict::refused($refusal);
        }

        $cost = $this->cost($line);
        if ($cost === null) {
            return LineVerdict::refused(PurchaseLineRefusal::CostMissing);
        }
        $currency = CurrencyCode::canonical($line->currency);
        if ($currency === null) {
            return LineVerdict::refused(PurchaseLineRefusal::CurrencyUnknown);
        }
        if ($this->fx->convert(Money::of(1), $currency, $this->fx->baseCurrency()) === null) {
            return LineVerdict::refused(PurchaseLineRefusal::NoExchangeRate);
        }
        $location = $this->locations->forProduct($product->fulfillmentProvider);
        if ($location === null) {
            return LineVerdict::refused(PurchaseLineRefusal::NoLocation);
        }

        return LineVerdict::eligible($location, $cost, $currency);
    }

    /** The unit cost the order holds, when it is a number above zero: a line with no cost is stored as zero, and zero is not a cost. */
    private function cost(PurchaseLine $line): ?Money
    {
        try {
            $cost = Money::ofNullable($line->unitCost);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $cost !== null && $cost->isPositive() ? $cost : null;
    }
}
