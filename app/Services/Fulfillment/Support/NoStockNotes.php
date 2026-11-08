<?php

namespace App\Services\Fulfillment\Support;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Contracts\NotesStockChanged;

/** Nobody else sells this shelf: the note goes nowhere, and the storefront's stock behaves exactly as it did before the sales-channel module existed. */
final class NoStockNotes implements NotesStockChanged
{
    public function providerFigureChanged(ProviderCode $provider, int $productId, string $variantKey, bool $figureChanged = true): void
    {
    }
}
