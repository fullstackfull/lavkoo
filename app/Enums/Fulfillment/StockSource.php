<?php

namespace App\Enums\Fulfillment;

/** Where a provider stock number comes from — shown beside the number so a manual figure is never mistaken for a synced one. */
enum StockSource: string
{
    case ProviderSync = 'provider_sync';
    case Manual = 'manual';
    case None = 'none';
}
