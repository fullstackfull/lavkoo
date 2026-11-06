<?php

namespace App\Console\Commands\Concerns;

use App\Services\SalesChannels\Support\SalesChannelSchema;

/** A scheduled command that queries a table the sales-channel migrations have not created yet would fail on every run: it says so and stops instead. */
trait SkipsUntilSalesChannelsMigrated
{
    protected function notMigratedYet(): bool
    {
        if (SalesChannelSchema::ready()) {
            return false;
        }

        $this->line('The sales-channel migrations have not been run yet; nothing to do.');

        return true;
    }
}
