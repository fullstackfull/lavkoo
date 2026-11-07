<?php

namespace App\Services\SalesChannels\Inventory;

use Illuminate\Support\Facades\DB;

/** Does the storefront sell a product per variant? One figure for such a product cannot be spread over its variants (the rule the Fulfillment code applies to a provider's figure). */
class ProductVariations
{
    public function exist(int $productId): bool
    {
        $stored = DB::table('products')->where('id', $productId)->value('variation');
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) && array_filter($decoded, 'is_array') !== [];
    }
}
