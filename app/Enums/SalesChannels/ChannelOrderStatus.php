<?php

namespace App\Enums\SalesChannels;

/** The application's own vocabulary for an external order; each connector maps the channel's statuses to it. */
enum ChannelOrderStatus: string
{
    /** Seen, nothing decided yet. */
    case New = 'new';
    /** The channel has the order but money/stock is not settled (Amazon "Pending"): never fulfilled in this state. */
    case Pending = 'pending';
    case AwaitingReview = 'awaiting_review';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned, self::Failed], true);
    }

    /** Fulfilment may only be started for an order the channel has released. */
    public function allowsFulfilment(): bool
    {
        return in_array($this, [self::New, self::Processing, self::AwaitingReview], true);
    }

    /** Forward progress, used to refuse a late notice that would move an order backwards. */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::New, self::AwaitingReview => 1,
            self::Processing => 2,
            self::Shipped => 3,
            self::Delivered, self::Cancelled, self::Returned, self::Failed => 9,
        };
    }

    public function labelKey(): string
    {
        return 'channel_order_status_' . $this->value;
    }
}
