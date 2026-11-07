<?php

namespace App\Services\SalesChannels\Exceptions;

use RuntimeException;

/**
 * A call was refused BEFORE anything left this host, because a switch is off or an approval is missing. `reason` is a stable code a caller
 * and the admin panel can act on; the message says in words which switch.
 */
class ChannelUnavailableException extends RuntimeException
{
    public const GLOBAL_OFF = 'global_off';
    public const ACCOUNT_OFF = 'account_off';
    public const CAPABILITY_OFF = 'capability_off';
    public const WRITES_OFF_ENVIRONMENT = 'writes_off_environment';
    public const WRITES_OFF_ACCOUNT = 'writes_off_account';
    public const NOT_APPROVED = 'not_approved';
    public const UNREGISTERED_OPERATION = 'unregistered_operation';
    public const AUTH_NEEDED = 'auth_needed';
    public const BUYER_DATA_OFF = 'buyer_data_off';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
