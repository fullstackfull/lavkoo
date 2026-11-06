<?php

namespace App\Services\SalesChannels\Exceptions;

use RuntimeException;

/** The channel answered 403 to a call whose credentials it had just accepted for others: this authorisation lacks a role or a permission for it. */
class ChannelPermissionDenied extends RuntimeException
{
    public function __construct(public readonly string $operation, string $message)
    {
        parent::__construct($message);
    }
}
