<?php

namespace App\Services\SalesChannels\Contracts;

use App\Models\SalesChannelAccount;

/** A channel that applies price and stock changes asynchronously and says, per item, what became of a batch (Trendyol). Without it, a change is confirmed by reading the listing back. */
interface ReportsPublishResults
{
    /** @return array{complete: bool, items: array<string, array{status: string, reasons: list<string>}>} the outcome per barcode */
    public function batchResult(SalesChannelAccount $account, string $batchId): array;
}
