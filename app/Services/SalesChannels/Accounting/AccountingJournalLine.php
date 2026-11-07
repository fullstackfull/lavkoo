<?php

namespace App\Services\SalesChannels\Accounting;

use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * One fact an accounting system would be given: a sale, a refund, a channel fee, a cost of goods, a payout. The amount is exact and always carries its currency; `reference`
 * is the identity of the fact in OUR books (a cost entry id, a channel order package), so posting the same line twice can be recognised and refused by the destination.
 */
final readonly class AccountingJournalLine
{
    public const SALE = 'sale';
    public const REFUND = 'refund';
    public const CHANNEL_FEE = 'channel_fee';
    public const COST_OF_GOODS = 'cost_of_goods';
    public const SHIPPING_COST = 'shipping_cost';
    public const PAYOUT = 'payout';

    /** @param array<string, mixed> $meta */
    public function __construct(
        public string $type,
        public string $reference,
        public Money $amount,
        public string $currency,
        public CarbonInterface $occurredAt,
        public ?string $orderReference = null,
        public array $meta = [],
    ) {
    }
}
