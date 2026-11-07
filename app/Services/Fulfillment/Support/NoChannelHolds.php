<?php

namespace App\Services\Fulfillment\Support;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use Carbon\CarbonInterface;

/** No sales channel sells this provider's stock, so nothing of it is promised elsewhere: the answer the fulfilment module gives on its own. */
final class NoChannelHolds implements ReportsChannelHolds
{
    public function unitsHeld(ProviderCode $provider, int $productId, string $variantKey, ?CarbonInterface $figureReadAt): int
    {
        return 0;
    }
}
