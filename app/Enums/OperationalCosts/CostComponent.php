<?php

namespace App\Enums\OperationalCosts;

/**
 * Every kind of cost the module knows. A contract rule prices one of these; a ledger entry records one; a report groups by it.
 * New kinds are added here, nowhere else.
 */
enum CostComponent: string
{
    // ---- product cost (what the goods cost us) ----
    case Cogs = 'cogs';
    case SupplierCost = 'supplier_cost';
    case InboundFreight = 'inbound_freight';

    // ---- fulfilment provider services ----
    case Receiving = 'receiving';
    case Picking = 'picking';
    case Packing = 'packing';
    case OrderProcessing = 'order_processing';
    case PackagingMaterial = 'packaging_material';
    case Stickers = 'stickers';
    case Printing = 'printing';
    case Shipping = 'shipping';
    case ExtraWeight = 'extra_weight';
    case ZoneSurcharge = 'zone_surcharge';
    case ReturnFee = 'return_fee';
    case CancellationFee = 'cancellation_fee';
    case CodCollection = 'cod_collection';
    case SpecialFee = 'special_fee';

    // ---- storage ----
    case StorageDaily = 'storage_daily';
    case StorageMonthly = 'storage_monthly';
    case StoragePerUnit = 'storage_per_unit';

    // ---- payment ----
    case PaymentGateway = 'payment_gateway';

    // ---- marketplaces ----
    case MarketplaceCommission = 'marketplace_commission';
    case FbaFulfillment = 'fba_fulfillment';
    case FbaStorage = 'fba_storage';
    case FbaReturn = 'fba_return';
    case MarketplaceServiceFee = 'marketplace_service_fee';
    case MarketplaceShipping = 'marketplace_shipping';
    case MarketplaceRefund = 'marketplace_refund';
    case MarketplaceAdjustment = 'marketplace_adjustment';

    case Other = 'other';

    public function costClass(): CostClass
    {
        return match ($this) {
            self::Cogs, self::SupplierCost, self::InboundFreight => CostClass::ProductCost,
            self::Receiving, self::Picking, self::Packing, self::OrderProcessing, self::PackagingMaterial, self::Stickers,
            self::Printing, self::Shipping, self::ExtraWeight, self::ZoneSurcharge, self::ReturnFee, self::CancellationFee,
            self::CodCollection, self::SpecialFee => CostClass::Fulfillment,
            self::StorageDaily, self::StorageMonthly, self::StoragePerUnit, self::FbaStorage => CostClass::Storage,
            self::PaymentGateway => CostClass::PaymentFee,
            self::MarketplaceCommission, self::FbaFulfillment, self::FbaReturn, self::MarketplaceServiceFee,
            self::MarketplaceShipping, self::MarketplaceRefund, self::MarketplaceAdjustment => CostClass::MarketplaceFee,
            self::Other => CostClass::Other,
        };
    }

    /** The components a contract can price (the ones a company can bill us for by a rule). */
    public function isContractPriced(): bool
    {
        return !in_array($this, [self::Cogs, self::SupplierCost, self::MarketplaceRefund, self::MarketplaceAdjustment, self::Other], true);
    }

    /** Charged for the whole shipment/order, not per item: when it covers several items it is allocated, never multiplied. */
    public function isShipmentLevel(): bool
    {
        return in_array($this, [
            self::OrderProcessing, self::Packing, self::Shipping, self::ExtraWeight, self::ZoneSurcharge, self::CodCollection,
            self::CancellationFee, self::Stickers, self::Printing, self::PackagingMaterial, self::SpecialFee, self::ReturnFee,
            self::PaymentGateway,
        ], true);
    }

    public function labelKey(): string
    {
        return 'cost_component_' . $this->value;
    }

    /** @return list<self> */
    public static function contractPriced(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $component): bool => $component->isContractPriced()));
    }
}
