<?php

namespace App\Services\SalesChannels\Data;

use App\Enums\OperationalCosts\CostComponent;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * One financial fact from a channel, already sorted into what it means for our books.
 *
 *  category `fee`         a charge by the channel: `amount` is a COST to us (positive); a refund of a fee is negative.
 *  category `sale`        the sale itself (already counted from the order; never added to revenue again)
 *  category `refund`      money given back to a buyer (the order's refund figures already carry it)
 *  category `payout`      the channel paying us what it owes: NOT revenue
 *  category `adjustment`  a correction the channel made; `amount` is a cost (positive) or a credit (negative)
 *
 * `identity` is stable under a redelivery of the same fact: importing it twice records it once.
 */
final readonly class RemoteFinancialEntry
{
    public const FEE = 'fee';
    public const SALE = 'sale';
    public const REFUND = 'refund';
    public const PAYOUT = 'payout';
    public const ADJUSTMENT = 'adjustment';
    /** A fact the channel reported that matches nothing we know: kept for a person, never counted as a cost or as revenue. */
    public const UNCLASSIFIED = 'unclassified';
    /** Reported again elsewhere (an invoice that bills commission already seen on each sale): kept for reconciliation, not counted twice. */
    public const DUPLICATE_OF_ANOTHER_ENTRY = 'duplicate';

    /** @param array<string, mixed> $meta */
    public function __construct(
        public string $identity,
        public string $category,
        public CostComponent $component,
        public Money $amount,
        public string $currency,
        public ?string $orderId,
        public ?string $packageId,
        public ?string $lineId,
        public ?string $sku,
        public CarbonInterface $postedAt,
        public string $rawType,
        public array $meta = [],
    ) {
    }
}
