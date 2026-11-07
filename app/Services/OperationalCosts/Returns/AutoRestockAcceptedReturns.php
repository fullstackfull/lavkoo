<?php

namespace App\Services\OperationalCosts\Returns;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\OperationalCostsSwitch;
use App\Services\SalesChannels\Data\RemoteReturn;

/**
 * An owner's decision, off by default (the account setting `auto_restock_accepted_returns`, and the cost module's master switch): the units of a claim the channel has ACCEPTED go
 * back on the shelf at the location the line was sold from, at the cost they left at, without a person looking at each one. Every unit of the claim is put back, damaged or not: only
 * a person can tell a damaged unit, so an owner who turns this on accepts that.
 *
 * It decides exactly what a person would (ReturnDecisionService, with the same caps and the same audit, marked automatic) and gives up quietly on what cannot be decided yet (the
 * goods were not recognised as sold, the line has no location): that claim stays on the list of returns awaiting a decision, for a person.
 */
class AutoRestockAcceptedReturns
{
    public function __construct(private readonly ReturnDecisionService $decisions)
    {
    }

    /** @return int the decisions made now */
    public function forReturn(SalesChannelAccount $account, ExternalOrderMapping $mapping, RemoteReturn $return): int
    {
        if (!OperationalCostsSwitch::enabled() || !(bool) $account->setting('auto_restock_accepted_returns', false) || $return->claimId === '') {
            return 0;
        }

        $made = 0;
        foreach (array_keys($return->acceptedUnits) as $lineId) {
            $line = ExternalOrderLine::where('mapping_id', $mapping->id)->where('external_line_id', (string) $lineId)->first();
            $next = $line === null || $line->inventory_location_id === null ? null : $this->decisions->nextClaimDecision($line, $return->claimId);
            if ($next === null) {
                continue;
            }
            try {
                $result = $this->decisions->decide(
                    line: $line, returnEvent: $next['event'], restock: $next['units'], writeOff: 0,
                    locationId: (int) $line->inventory_location_id, adminId: null, note: 'Put back automatically: the channel accepted the return.', automatic: true,
                );
                $made += $result->alreadyDecided ? 0 : 1;
            } catch (InvalidReturnDecision) {
                // Not decidable yet: the claim stays on the list of returns awaiting a decision.
            }
        }

        return $made;
    }
}
