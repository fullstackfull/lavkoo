<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Services\PlatformAdmin\PlatformAdminAuditPruner;
use App\Services\PlatformAdmin\PlatformAdminAuditVerifier;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PlatformAdminAuditPruneCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:audit:prune
        {--days= : How many days of the audit trail to keep: the lines older than that, from the start of the trail, are removed. A whole number of at least 1}
        {--confirm : Remove them. Without it nothing is removed and the command says what it would remove}';

    protected $description = 'The only way a line leaves the audit trail of the platform admin API: removes the oldest lines after a verification that must pass, and keeps a checkpoint so that the chain still verifies. Never scheduled';

    public function handle(PlatformAdminAuditVerifier $verifier, PlatformAdminAuditPruner $pruner, PlatformAdminSchema $schema): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $days = $this->daysOption();
        if ($days === null) {
            return self::FAILURE;
        }

        $verdict = $verifier->verify();
        if (!$verdict->intact) {
            $this->error("The audit trail does not verify ({$verdict->describedBreak()}), so nothing was removed: the evidence of a break is not cut away. Run gcc:platform-admin:audit:verify.");

            return self::FAILURE;
        }

        $plan = $pruner->planFor(days: $days);
        if ($plan === null) {
            $this->info("Nothing to remove: no line older than {$days} " . Str::plural('day', $days) . ' comes before the newest.');

            return self::SUCCESS;
        }

        $removal = "{$plan->lines} " . Str::plural('line', $plan->lines) . ", ids {$plan->firstId} to {$plan->lastId}, written before {$plan->cutoff}";

        if (!$this->option('confirm')) {
            $this->line("Would remove {$removal}. Nothing was changed. Run it again with --confirm to remove them.");

            return self::SUCCESS;
        }

        $checkpoint = $pruner->prune(plan: $plan, days: $days);

        $this->info("Removed {$removal}. Checkpoint {$checkpoint->getKey()} holds line {$plan->lastId} and its hash {$plan->lastHash}; the trail verifies from it, and the prune is a line of its own in the chain.");

        return self::SUCCESS;
    }

    private function daysOption(): ?int
    {
        $given = (string) $this->option('days');

        if (preg_match('/^[1-9][0-9]*$/', $given) !== 1) {
            $this->error('--days is required: the number of days of the audit trail to keep, a whole number of at least 1.');

            return null;
        }

        return (int) $given;
    }
}
