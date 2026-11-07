<?php

namespace App\Console\Commands\Concerns;

use App\Services\Address\AddressSchema;

/**
 * The address code is deployed before its migrations run, and until they have it must leave the store exactly as it was. A command
 * that reads a table that is not there yet would fail every time it ran: it says so and stops instead.
 */
trait SkipsUntilAddressMigrated
{
    protected function addressNotMigratedYet(): bool
    {
        AddressSchema::flush();
        if (AddressSchema::ready()) {
            return false;
        }

        $this->line('The address migrations have not been run yet; nothing to do.');

        return true;
    }
}
