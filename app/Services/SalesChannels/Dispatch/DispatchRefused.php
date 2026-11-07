<?php

namespace App\Services\SalesChannels\Dispatch;

use RuntimeException;

/** Turning the order into a shipment was refused, with a stable reason code; nothing was written. The message never contains anything about the buyer. */
class DispatchRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false)
    {
        parent::__construct($reason);
    }
}
