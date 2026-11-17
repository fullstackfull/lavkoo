<?php

namespace App\Console\Commands\Concerns;

use App\Services\PlatformAdmin\PlatformAdminSchema;

/** What the commands that manage the platform admin API's grants check before they change anything: that its tables exist and which admin they were asked about. */
trait ChecksPlatformAdminCommandInput
{
    /** Tells the operator, and answers true, when the migrations of the platform admin API have not run. */
    private function migrationsMissing(PlatformAdminSchema $schema): bool
    {
        $missing = $schema->missingTables();

        if ($missing === []) {
            return false;
        }

        $this->error('The platform admin tables do not exist yet (' . implode(', ', $missing) . '). Run the migrations first.');

        return true;
    }

    /** The admin id argument, or null, after telling the operator, when it is not a positive whole number. */
    private function adminIdArgument(): ?int
    {
        $given = (string) $this->argument('admin_id');

        if (preg_match('/^[1-9][0-9]*$/', $given) !== 1) {
            $this->error('The admin id must be a positive whole number.');

            return null;
        }

        return (int) $given;
    }
}
