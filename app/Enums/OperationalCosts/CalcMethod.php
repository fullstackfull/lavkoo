<?php

namespace App\Enums\OperationalCosts;

/** How a contract rule turns a service into an amount. The figures live in the rule's `params`; nothing here has a default price. */
enum CalcMethod: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';
    case PercentPlusFixed = 'percent_plus_fixed';
    case PerUnit = 'per_unit';
    case PerShipment = 'per_shipment';
    case PerOrder = 'per_order';
    case PerDay = 'per_day';
    case ByWeight = 'by_weight';
    case ByVolume = 'by_volume';
    case ByZone = 'by_zone';
    case TieredQuantity = 'tiered_quantity';
    case TieredValue = 'tiered_value';

    public function labelKey(): string
    {
        return 'calc_method_' . $this->value;
    }
}
