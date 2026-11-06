<?php

namespace App\Enums\Fulfillment;

/**
 * Lifecycle of ONE provider fulfillment (a child of the customer's parent order).
 *
 * This is deliberately not the platform's `orders.order_status`: that value is financially loaded
 * (delivered matures earnings, canceled restocks) and is only ever moved by the order flow. This
 * vocabulary is the provider-side truth, and the parent is derived from it by FulfillmentAggregator.
 *
 * "Submitted" means the provider VERIFIABLY accepted the request. Everything before it is some form of
 * "not yet accepted" — including `reconcile_pending`, where we do not know and must find out before
 * anything is sent again.
 */
enum FulfillmentStatus: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Dispatching = 'dispatching';
    case ReconcilePending = 'reconcile_pending';
    case RetryWait = 'retry_wait';
    case DryRun = 'dry_run';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case DeliveryPostponed = 'delivery_postponed';
    case DeliveryFailed = 'delivery_failed';
    case PartiallyDelivered = 'partially_delivered';
    case NeedsAttention = 'needs_attention';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], true);
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    /** True while the provider has not (verifiably) accepted the request. */
    public function isPreSubmission(): bool
    {
        return in_array($this, [
            self::Pending, self::Held, self::Dispatching, self::ReconcilePending,
            self::RetryWait, self::DryRun, self::Failed,
        ], true);
    }

    /** True once the provider holds the order, i.e. it can still ship, fail or be cancelled there. */
    public function isAcceptedByProvider(): bool
    {
        return !$this->isPreSubmission();
    }

    /** Nothing reached the provider for certain, so a cancellation needs no call: the shipment is simply dropped. */
    public function isCancellableLocally(): bool
    {
        return in_array($this, [self::Pending, self::Held, self::RetryWait, self::DryRun, self::Failed], true);
    }

    /** A send is (or may be) in flight: the provider cannot be asked to cancel what we cannot yet find there. */
    public function isSendOutcomeUnknown(): bool
    {
        return in_array($this, [self::Dispatching, self::ReconcilePending], true);
    }

    /**
     * Forward-progress rank used to refuse a LATE event that would move a shipment backwards when the
     * event carries no timestamp of its own. Sideways states (postponed/failed attempts) share a rank
     * with out-for-delivery, since a courier retries from there.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending, self::Held, self::DryRun, self::Failed => 0,
            self::RetryWait, self::Dispatching, self::ReconcilePending => 1,
            self::Submitted => 2,
            self::Processing => 3,
            self::InTransit => 4,
            self::OutForDelivery, self::DeliveryPostponed, self::DeliveryFailed => 5,
            self::PartiallyDelivered, self::NeedsAttention => 6,
            self::Delivered, self::Cancelled, self::Returned => 9,
        };
    }

    /** Translation key, defined in each language's new-messages.php file. */
    public function labelKey(): string
    {
        return 'fulfillment_status_' . $this->value;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isActive());
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isTerminal());
    }

    /** @return list<string> */
    public static function cancellableLocallyValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isCancellableLocally());
    }

    /** @return list<string> */
    public static function sendOutcomeUnknownValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isSendOutcomeUnknown());
    }

    /** @return list<string> */
    public static function preSubmissionValues(): array
    {
        return self::valuesWhere(static fn (self $status): bool => $status->isPreSubmission());
    }

    /**
     * @param callable(self): bool $filter
     * @return list<string>
     */
    private static function valuesWhere(callable $filter): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), $filter),
        ));
    }
}
