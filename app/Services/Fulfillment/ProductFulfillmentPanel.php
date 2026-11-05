<?php

namespace App\Services\Fulfillment;

use App\Models\ProductProviderChange;
use App\Models\ProviderProductMapping;
use Illuminate\Support\Collection;

/** Everything the product form's "Fulfillment provider" card shows, in one read-only bundle. */
class ProductFulfillmentPanel
{
    private const HISTORY_LIMIT = 5;

    public function __construct(private readonly ProductProviderAssignmentService $assignments)
    {
    }

    /**
     * @return array{current: string, choices: list<array<string, mixed>>, mappings: Collection<int, ProviderProductMapping>, history: Collection<int, ProductProviderChange>}
     */
    public function for(?int $productId): array
    {
        return $this->assignments->choicesFor($productId) + [
            'mappings' => $productId === null
                ? new Collection()
                : ProviderProductMapping::where('product_id', $productId)->orderBy('provider')->orderBy('variant_key')->get(),
            'history' => $productId === null
                ? new Collection()
                : ProductProviderChange::where('product_id', $productId)->latest('id')->limit(self::HISTORY_LIMIT)->get(),
        ];
    }
}
