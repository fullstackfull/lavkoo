<?php

namespace App\Services\SalesChannels\Sync;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Returns\AutoRestockAcceptedReturns;
use App\Services\SalesChannels\Data\RemoteReturn;
use Illuminate\Support\Facades\DB;

/**
 * Records, on the order lines, the units a channel has accepted back. Each claim's contribution is SET from what the channel reports (never added to), and the line holds the
 * sum over its claims, so reading a claim twice, or after it changed, leaves the line right, two claims on one line add up, and the figure never exceeds the units sold. A return of an order we hold no record of is counted and ignored: it is read again
 * when the order arrives.
 *
 * Putting the returned units back on the shelf is a decision of its own (ReturnDecisionService). Only when the owner has switched it on for the account
 * (`auto_restock_accepted_returns`) is it made here, for the units of an accepted claim, by AutoRestockAcceptedReturns.
 */
class ChannelReturnIngestor
{
    public const APPLIED = 'applied';
    public const UNCHANGED = 'unchanged';
    public const UNKNOWN_ORDER = 'unknown_order';

    public function __construct(private readonly AutoRestockAcceptedReturns $autoRestock)
    {
    }

    /** @return string one of the constants above */
    public function apply(SalesChannelAccount $account, RemoteReturn $return): string
    {
        $mapping = ExternalOrderMapping::where('account_id', $account->id)->where('external_order_id', $return->orderId)->where('external_package_id', $return->packageId)->first();
        if ($mapping === null) {
            return self::UNKNOWN_ORDER;
        }

        $outcome = DB::transaction(function () use ($mapping, $return): string {
            $changed = false;
            foreach ($return->acceptedUnits as $lineId => $units) {
                $line = ExternalOrderLine::where('mapping_id', $mapping->id)->where('external_line_id', (string) $lineId)->lockForUpdate()->first();
                if ($line === null) {
                    continue;
                }
                $snapshot = (array) $line->snapshot;
                $snapshot['returned_by_claim'][$return->claimId !== '' ? $return->claimId : 'unknown'] = $units;
                $returned = min(array_sum($snapshot['returned_by_claim']), (int) $line->quantity);
                if ($line->quantity_returned !== $returned || ($line->snapshot['returned_by_claim'] ?? null) !== $snapshot['returned_by_claim']) {
                    $line->forceFill(['quantity_returned' => $returned, 'snapshot' => $snapshot])->save();
                    $changed = true;
                }
            }

            return $changed ? self::APPLIED : self::UNCHANGED;
        });
        $this->autoRestock->forReturn($account, $mapping, $return);

        return $outcome;
    }
}
