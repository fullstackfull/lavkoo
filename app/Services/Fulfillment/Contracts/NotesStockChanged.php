<?php

namespace App\Services\Fulfillment\Contracts;

use App\Enums\Fulfillment\ProviderCode;

/**
 * Says that a provider's figure for a product has changed, so that whoever offers that same shelf elsewhere can be told without waiting for a sweep.
 *
 * The storefront and the channels (Amazon FBM, Trendyol) sell ONE provider figure between them. When a sync writes a new figure, what the channels may be shown changes in the
 * same instant — but until now they only found out at the next scheduled publish, up to a quarter of an hour later, which is a quarter of an hour of offering units that are
 * gone. The provider sync says it here instead, and the sales-channel module records it in its outbox.
 *
 * It is a NOTE, not an instruction: this never sends anything to a channel, and nothing about whether a channel may be written to is decided by it or by its callers. Where the
 * sales-channel module is absent, or its migrations have not run, the note goes nowhere (`NoStockNotes`) and the storefront behaves exactly as it always did.
 */
interface NotesStockChanged
{
    /**
     * @param string $variantKey the variant whose figure changed, '' for the whole product
     * @param bool $figureChanged false when the figure came back the same: there is nothing to tell anybody
     */
    public function providerFigureChanged(ProviderCode $provider, int $productId, string $variantKey, bool $figureChanged = true): void;
}
