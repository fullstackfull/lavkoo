<?php

namespace App\Console\Commands\Concerns;

use App\Services\Fulfillment\FulfillmentSchema;

/**
 * The fulfillment code can be deployed before its two migrations have run, and until they have it must leave the platform exactly as it
 * was. A scheduled command that queries one of the new tables would fail every time the scheduler ran it: it says so and stops instead.
 */
trait SkipsUntilMigrated
{
    protected function notMigratedYet(): bool
    {
        if (FulfillmentSchema::ready()) {
            return false;
        }

        $this->line('The fulfillment migrations have not been run yet; nothing to do.');

        return true;
    }
}
