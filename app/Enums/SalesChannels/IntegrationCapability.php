<?php

namespace App\Enums\SalesChannels;

/**
 * The nine steps of switching a channel account on. An account starts with none of them (disabled); each is turned on on
 * its own, and the ones that change something at the channel are separately gated, separately approved and separately
 * logged. Reading and writing are never one switch.
 */
enum IntegrationCapability: string
{
    case ConnectionTest = 'connection_test';
    case ReadOnlySync = 'read_only_sync';
    case ProductSync = 'product_sync';
    case InventoryPublishing = 'inventory_publishing';
    case PricePublishing = 'price_publishing';
    case OrderImport = 'order_import';
    case FulfillmentDispatch = 'fulfillment_dispatch';
    case FinancialReconciliation = 'financial_reconciliation';

    /** Does using this capability change anything AT THE CHANNEL (or at a fulfilment provider on its account's behalf)? */
    public function isExternalWrite(): bool
    {
        return in_array($this, [self::InventoryPublishing, self::PricePublishing, self::FulfillmentDispatch], true);
    }

    /** Needs a recorded approval by a person before it can be switched on. */
    public function needsApproval(): bool
    {
        return $this->isExternalWrite();
    }

    /** @return list<self> the capabilities that must already be on before this one may be turned on */
    public function requires(): array
    {
        return match ($this) {
            self::ConnectionTest => [],
            self::ReadOnlySync => [self::ConnectionTest],
            self::ProductSync, self::OrderImport, self::FinancialReconciliation => [self::ConnectionTest, self::ReadOnlySync],
            self::InventoryPublishing, self::PricePublishing => [self::ConnectionTest, self::ReadOnlySync, self::ProductSync],
            self::FulfillmentDispatch => [self::ConnectionTest, self::ReadOnlySync, self::OrderImport],
        };
    }

    public function labelKey(): string
    {
        return 'integration_capability_' . $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }
}
