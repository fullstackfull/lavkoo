<?php

namespace App\Services\SalesChannels\Exceptions;

use RuntimeException;

/**
 * The channel could not give the recipient of an order for shipping. `reason` is a stable code a person can act on; the message never contains anything the channel said about the buyer.
 */
class RecipientUnavailable extends RuntimeException
{
    public const NOT_PERMITTED = 'recipient_not_permitted';
    public const NOT_FOUND = 'order_not_found_at_channel';
    public const NOT_SHIPPABLE_BY_US = 'not_shipped_by_the_seller';
    public const INCOMPLETE = 'recipient_incomplete';
    public const CHANNEL_ERROR = 'channel_error';

    /** @param list<string> $problems */
    public function __construct(public readonly string $reason, public readonly array $problems = [])
    {
        parent::__construct($problems === [] ? $reason : $reason . ': ' . implode(', ', $problems));
    }
}
