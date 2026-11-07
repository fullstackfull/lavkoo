<?php

namespace App\Services\OperationalCosts\Contributors;

use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocument;

/** The cost of the goods of a sale. */
class ProductCostContributor implements CostContributor
{
    public function __construct(private readonly SaleCostService $sales)
    {
    }

    public function contribute(SaleDocument $document): array
    {
        return $this->sales->refresh($document);
    }
}
