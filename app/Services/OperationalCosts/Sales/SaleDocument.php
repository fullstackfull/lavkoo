<?php

namespace App\Services\OperationalCosts\Sales;

use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;

/**
 * A sale as the cost and profit modules see it: one shape for a storefront order, an Amazon order and a Trendyol order.
 *
 * `shippingCharged` is shipping the customer paid us; `orderDiscounts` are coupons and other discounts that apply to the whole order;
 * `revenueNotes` lists anything that makes the revenue figure less than certain (a total that does not add up, an amount that could
 * not be converted), so a profit built on it is shown as unverified rather than trusted.
 */
final readonly class SaleDocument
{
    public const SUBJECT_ORDER = 'order';
    public const SUBJECT_CHANNEL_ORDER = 'channel_order';

    public const STATE_OPEN = 'open';
    public const STATE_SHIPPED = 'shipped';
    public const STATE_DELIVERED = 'delivered';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_RETURNED = 'returned';

    /**
     * @param list<SaleLine> $lines
     * @param list<string> $revenueNotes
     */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $salesChannel,
        public ?int $accountId,
        public int $sellerId,
        public string $currency,
        public CarbonInterface $occurredAt,
        public string $state,
        public ?string $fulfillmentMode,
        public ?string $provider,
        public ?int $orderId,
        public ?int $mappingId,
        public Money $shippingCharged,
        public Money $orderDiscounts,
        public array $lines,
        public array $revenueNotes = [],
        public ?string $reference = null,
        public ?string $paymentMethod = null,
        public ?Money $orderTotal = null,
    ) {
    }

    public function linesRevenue(): Money
    {
        return Money::sum(array_map(static fn (SaleLine $line): Money => $line->revenue, $this->lines));
    }

    public function refundsTotal(): Money
    {
        return Money::sum(array_map(static fn (SaleLine $line): Money => $line->refunded, $this->lines));
    }

    /** Product sales + shipping collected - order-level discounts - refunds. Never includes a platform payout. */
    public function netRevenue(): Money
    {
        return $this->linesRevenue()->plus($this->shippingCharged)->minus($this->orderDiscounts)->minus($this->refundsTotal());
    }

    public function isCancelled(): bool
    {
        return $this->state === self::STATE_CANCELLED;
    }

    /** Has the sale reached the point at which its goods are treated as sold ($recognition is 'shipped' or 'delivered')? */
    public function isRecognised(string $recognition): bool
    {
        return match ($this->state) {
            self::STATE_DELIVERED, self::STATE_RETURNED => true,
            self::STATE_SHIPPED => $recognition === 'shipped',
            default => false,
        };
    }

    public function cacheKey(): string
    {
        return $this->subjectType . ':' . $this->subjectId;
    }
}
