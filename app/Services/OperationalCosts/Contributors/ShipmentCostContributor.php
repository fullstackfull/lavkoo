<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Shipments\ShipmentCostService;
use App\Services\OperationalCosts\Shipments\ShipmentFactsFactory;
use Illuminate\Support\Facades\Schema;

/**
 * The cost of the shipments that carry a sale: the ones the application sent to a provider, and, for a channel order whose shipment someone else
 * created at a provider, the shipment the application follows. A shipment that failed before it was ever accepted by the provider cost
 * nothing and is skipped.
 */
class ShipmentCostContributor implements CostContributor
{
    public function __construct(
        private readonly ShipmentCostService $costs,
        private readonly ShipmentFactsFactory $facts,
    ) {
    }

    public function contribute(SaleDocument $document): array
    {
        $outcomes = [];

        if ($document->orderId !== null && Schema::hasTable('provider_fulfillments')) {
            $fulfillments = ProviderFulfillment::where('order_id', $document->orderId)->whereNotNull('submitted_at')->get();
            foreach ($fulfillments as $fulfillment) {
                $outcomes = [...$outcomes, ...$this->costs->estimate($this->facts->forFulfillment($fulfillment))];
            }
        }

        if ($document->mappingId !== null && $document->orderId === null) {
            $mapping = ExternalOrderMapping::find($document->mappingId);
            if ($mapping !== null && $mapping->provider !== null && $mapping->shipment_reference !== null) {
                $outcomes = [...$outcomes, ...$this->costs->estimate($this->facts->forExternalShipment($mapping))];
            }
        }

        return $outcomes;
    }
}
