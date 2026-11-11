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
    /** The account is missing a setting the call cannot be made without — a different problem from a capability nobody switched on, and reported as a different one. */
    public const SETTING_MISSING = 'setting_missing';
    /**
     * Another process is already making this exact write and owns it (`channel_shipment_sends`). Nothing left this host, and nothing is wrong: it is a skip, not a refusal,
     * so a caller that counts refusals does not count this one and park work the other process is finishing.
     */
    public const ANOTHER_ATTEMPT_RUNNING = 'another_attempt_running';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
