<?php

namespace App\Services\Fulfillment\Contracts;

use App\Enums\Fulfillment\ProviderCode;
use Carbon\CarbonInterface;

/**
 * Units of a provider's stock that open sales-channel orders have already promised and the provider's own figure does not show yet.
 *
 * The storefront and the channels (Amazon FBM, Trendyol) sell ONE provider figure between them, so a unit an open channel order holds has to
 * come off what the storefront is told it has, exactly as a store order the provider has not taken on yet does. The sales-channel module
 * answers this question; where it is absent or its migrations have not run the answer is 0 and the storefront's stock is what it always was.
 */
interface ReportsChannelHolds
{
    /**
     * @param string $variantKey the variant the figure belongs to, '' for the whole product
     * @param CarbonInterface|null $figureReadAt when the provider's figure was read: units the provider had already taken off by then are in the figure
     */
    public function unitsHeld(ProviderCode $provider, int $productId, string $variantKey, ?CarbonInterface $figureReadAt): int;
}
