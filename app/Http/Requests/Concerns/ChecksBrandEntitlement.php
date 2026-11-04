<?php

namespace App\Http\Requests\Concerns;

use App\Services\Marketplace\BrandRegistryService;
use Illuminate\Validation\Validator;

/**
 * The brand registry's gate, in the panel's own validation flow.
 *
 * The seller API applies the same check at the same point in its own validator, so a listing refused
 * in the app is refused in the panel and for the same stated reason. Two gates in two places with
 * one service behind them is the only arrangement where they cannot drift apart.
 *
 * Only ever applies to a signed-in seller. Marketplace staff adding the marketplace's own stock are
 * not claiming anybody's brand, and a gate that stopped them would be enforcing the registry against
 * the people who administer it.
 */
trait ChecksBrandEntitlement
{
    protected function validateBrandEntitlement(Validator $validator): void
    {
        $seller = auth('seller')->user();

        if (!$seller) {
            return;
        }

        $brandId = $this->input('product_type') === 'physical' ? $this->input('brand_id') : null;

        if (!$brandId) {
            return;
        }

        $registry = app(BrandRegistryService::class);

        // Advisory until the marketplace switches enforcement on. Until then the mismatch is
        // reported by the brand detector, where a seller can act on it before it costs them a
        // listing they were making yesterday.
        if (!$registry->isEnforcing() || $registry->mayList($seller->id, $brandId)) {
            return;
        }

        $validator->errors()->add(
            'brand_id',
            translate('brand_claim_required_to_list_under_this_brand') . '!',
        );
    }
}
