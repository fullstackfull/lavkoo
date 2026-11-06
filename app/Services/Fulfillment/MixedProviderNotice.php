<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\DB;

/**
 * Tells a customer, before they pay, that one order may arrive in more than one shipment.
 *
 * Decided purely from where the products in the cart will be fulfilled from: if they come from two or more
 * sources (the store's own stock counts as one) the order will likely split. The customer needs no
 * explanation of providers — only that it is still ONE order and may arrive in separate parcels.
 */
class MixedProviderNotice
{
    /**
     * @param iterable<int|string> $productIds
     * @return array{show: true, message: string, sources: int}|null null when no notice is needed
     */
    public function forProducts(iterable $productIds): ?array
    {
        $ids = collect($productIds)->filter()->unique()->values();
        if ($ids->count() < 2 || !FulfillmentSchema::productColumnReady()) {
            return null;
        }

        $sources = DB::table('products')->whereIn('id', $ids)->pluck('fulfillment_provider')
            ->map(static fn (?string $provider): string => $provider ?: 'default')
            ->unique();

        return $sources->count() >= 2
            ? ['show' => true, 'message' => translate('mixed_provider_order_notice'), 'sources' => $sources->count()]
            : null;
    }
}
