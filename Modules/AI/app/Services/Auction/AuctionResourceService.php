<?php

namespace Modules\AI\app\Services\Auction;

use Modules\AI\app\Services\ProductResourceService;
use Modules\TaxModule\app\Traits\VatTaxManagement;

class AuctionResourceService
{
    use VatTaxManagement;

    public function __construct(
        private readonly ProductResourceService $productResourceService = new ProductResourceService()
    ) {
    }

    public function generalSetupData(): array
    {
        $productResources = $this->productResourceService->productGeneralSetupData();

        return [
            'categories' => $productResources['categories'],
            'brands' => $productResources['brands'],
            'product_types' => ['physical'],
            'item_conditions' => \Modules\Auction\app\Enums\ItemCondition::ALL,
        ];
    }

    public function taxOptions(): array
    {
        $taxData = $this->getTaxSystemType();
        $taxVats = $taxData['taxVats'];

        if (is_array($taxVats)) {
            $taxVats = collect($taxVats);
        }

        return $taxVats
            ->mapWithKeys(function ($tax) {
                $name = strtolower((string) ($tax->name ?? $tax['name'] ?? ''));
                $id = (int) ($tax->id ?? $tax['id'] ?? 0);

                return $name !== '' && $id > 0 ? [$name => $id] : [];
            })
            ->toArray();
    }
}
