<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Models\SalesChannelAccount;

/**
 * What would happen to a call that CHANGES something at a channel, for one account, right now. The cases follow the order in which the transport checks its switches
 * (ChannelHttp::assertAllowed), so what is shown here is what the transport will do; the approval of the kind of write is checked per capability and is not part of this.
 */
enum WriteState: string
{
    case GlobalOff = 'global_off';
    case AccountDisabled = 'account_disabled';
    case Rehearsal = 'rehearsal';
    case EnvironmentReadOnly = 'environment_read_only';
    case WritesNotAllowed = 'writes_not_allowed';
    case Live = 'live';

    public static function of(SalesChannelAccount $account): self
    {
        return match (true) {
            !config('sales_channels.enabled') => self::GlobalOff,
            !$account->enabled => self::AccountDisabled,
            $account->dry_run => self::Rehearsal,
            !config('sales_channels.external_writes_enabled') => self::EnvironmentReadOnly,
            !$account->write_enabled => self::WritesNotAllowed,
            default => self::Live,
        };
    }

    /** Dry run: the exact request is logged and nothing is sent. */
    public function isRehearsal(): bool
    {
        return $this === self::Rehearsal;
    }

    /** Only a live account sends a write for real; every other state sends nothing. */
    public function sendsForReal(): bool
    {
        return $this === self::Live;
    }

    public function labelKey(): string
    {
        return 'write_state_' . $this->value;
    }

    public function helpKey(): string
    {
        return 'write_state_help_' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Live => 'warning',
            self::Rehearsal => 'info',
            self::GlobalOff, self::AccountDisabled, self::EnvironmentReadOnly, self::WritesNotAllowed => 'neutral',
        };
    }
}
