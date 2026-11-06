<?php

namespace App\Services\OperationalCosts\Reports;

use App\Services\OperationalCosts\Profit\ProfitResult;
use App\Services\OperationalCosts\Sales\SaleDocument;

/**
 * The sales a profit report read and the profit the service computed for each, in the same order.
 *
 * A report never reads more than `limit` sales: `truncated` says there were more, so a total is never mistaken for the whole period's.
 */
final readonly class ProfitReport
{
    /**
     * @param list<SaleDocument> $documents
     * @param list<ProfitResult> $results
     */
    public function __construct(
        public ProfitFilters $filters,
        public array $documents,
        public array $results,
        public bool $truncated,
        public int $limit,
    ) {
    }
}
