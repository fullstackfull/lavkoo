<?php

namespace App\Console\Commands\Concerns;

use App\Services\SalesChannels\Support\SalesChannelSchema;
use App\Services\SalesChannels\Support\SalesChannelSetup;

/** A scheduled command that queries a table the sales-channel migrations have not created yet would fail on every run: it says so and stops instead. Once they have run, it makes sure the default stock locations exist. */
trait SkipsUntilSalesChannelsMigrated
{
    protected function notMigratedYet(): bool
    {
        if ($this->salesChannelsNotMigrated()) {
            return true;
        }
        SalesChannelSetup::prepare();

        return false;
    }

    /** The same check without making the default locations: for a command that must not write anything. */
    protected function salesChannelsNotMigrated(): bool
    {
        if (SalesChannelSchema::ready()) {
            return false;
        }

        $this->line('The sales-channel migrations have not been run yet; nothing to do.');

        return true;
    }
}
