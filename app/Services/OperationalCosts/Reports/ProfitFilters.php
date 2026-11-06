<?php

namespace App\Services\OperationalCosts\Reports;

use Carbon\CarbonImmutable;

/** Which sales a profit report reads: a date range (always), and optionally one channel, one channel account, one provider and one seller's books. */
final readonly class ProfitFilters
{
    /** The `provider` value that selects sales no provider handled. */
    public const NO_PROVIDER = 'none';

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $channel = null,
        public ?int $accountId = null,
        public ?string $provider = null,
        public ?int $sellerId = null,
    ) {
    }
}
