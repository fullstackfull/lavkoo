<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostInvoiceItem;
use App\Models\ProviderFulfillment;
use Illuminate\Support\Facades\Schema;

/**
 * Finds what an invoice line is about from the reference the company wrote on it (its own order number, a tracking number, our invoice
 * number). A line is linked only when the reference identifies EXACTLY ONE shipment or order; an ambiguous or unknown reference leaves it
 * unlinked for a person, because a wrong link would charge one customer's order with another's cost.
 */
class InvoiceLinkResolver
{
    /** @return array{provider_fulfillment_id?: int, external_order_mapping_id?: int, order_id?: int} */
    public function resolve(string $providerCode, OperationalCostInvoiceItem $item): array
    {
        $reference = trim((string) $item->external_reference);
        if ($reference === '') {
            return [];
        }

        if (Schema::hasTable('provider_fulfillments')) {
            $matches = ProviderFulfillment::query()
                ->where('provider', $providerCode)
                ->where(static function ($query) use ($reference): void {
                    $query->where('provider_reference', $reference)
                        ->orWhere('provider_secondary_reference', $reference)
                        ->orWhere('tracking_number', $reference)
                        ->orWhere('reference', $reference);
                })
                ->limit(2)->get(['id', 'order_id']);
            if ($matches->count() === 1) {
                return ['provider_fulfillment_id' => (int) $matches[0]->id, 'order_id' => (int) $matches[0]->order_id];
            }
            if ($matches->count() > 1) {
                return [];
            }
        }

        if (Schema::hasTable('external_order_mappings')) {
            $matches = ExternalOrderMapping::query()
                ->where(static function ($query) use ($reference): void {
                    $query->where('external_order_id', $reference)
                        ->orWhere('shipment_reference', $reference)
                        ->orWhere('tracking_number', $reference);
                })
                ->limit(2)->get(['id', 'order_id']);
            if ($matches->count() === 1) {
                return ['external_order_mapping_id' => (int) $matches[0]->id] + ($matches[0]->order_id === null ? [] : ['order_id' => (int) $matches[0]->order_id]);
            }
        }

        return [];
    }
}
