<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Sales\SaleDocument;

/**
 * One source of cost entries for a sale: the cost of its goods, the shipments that carry it, the gateway that took the payment, the
 * marketplace that sold it. Each writes only through CostLedger and is safe to run again; CostRefresher runs them all.
 *
 * A contributor that expects a cost for the sale and cannot determine it records it as MISSING, with the reason: after it has run, every
 * cost a sale should have is either an amount or an explicit gap, never an absence nobody can see.
 */
interface CostContributor
{
    /** @return list<RecordOutcome> */
    public function contribute(SaleDocument $document): array;
}
