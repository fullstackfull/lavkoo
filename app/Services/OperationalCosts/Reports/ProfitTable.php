<?php

namespace App\Services\OperationalCosts\Reports;

/** A profit report laid out along one dimension: its lines, and the total that sits under them. */
final readonly class ProfitTable
{
    /** @param list<ProfitLine> $lines */
    public function __construct(public string $dimension, public array $lines, public ProfitTotals $totals)
    {
    }
}
