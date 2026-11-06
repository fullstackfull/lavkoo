<?php

namespace App\Enums\OperationalCosts;

/** The family a cost component belongs to; the reports group by it. */
enum CostClass: string
{
    case ProductCost = 'product_cost';
    case Fulfillment = 'fulfillment';
    case Storage = 'storage';
    case MarketplaceFee = 'marketplace_fee';
    case PaymentFee = 'payment_fee';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'cost_class_' . $this->value;
    }
}
