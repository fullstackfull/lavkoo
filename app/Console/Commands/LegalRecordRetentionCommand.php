<?php

namespace App\Console\Commands;

use App\Services\Retention\LegalRecordRetention;
use Illuminate\Console\Command;

/**
 * Prints the retention rules of the legal texts and their acceptances, what applying them would remove, and -- only when asked -- applies
 * them (I2-01, I2-05).
 *
 * The default is to print and change nothing, because the single removal these rules permit cannot be undone and an operator should read
 * what it would take before it takes it. The rules are not written here: {@see LegalRecordRetention::rules()} is the one place they live,
 * so this command, its test and the service's docblock cannot drift apart.
 */
class LegalRecordRetentionCommand extends Command
{
    protected $signature = 'gcc:legal:retention {--apply : Remove what the rules permit, rather than only reporting it}';

    protected $description = 'The retention rules of the legal texts and their acceptances, what they would remove, and optionally applying them';

    public function handle(LegalRecordRetention $retention): int
    {
        foreach ($retention->rules() as $record => $rule) {
            $this->line("  {$record}: {$rule}");
        }

        $plan = $retention->plan();
        $this->newLine();
        $this->line("  budget for a never-approved draft: " . ($plan['draft_days'] === 0 ? 'never removed' : $plan['draft_days'] . ' days'));
        $this->line("  legal texts kept for good: {$plan['kept_for_good']}");
        $this->line("  acceptance rows, none of them removable: {$plan['acceptances']}");
        $this->line("  drafts the rules would remove now: {$plan['removable_drafts']}");

        if (!$this->option('apply')) {
            $this->newLine();
            $this->info('Nothing was changed. Run again with --apply to remove the drafts listed above.');

            return self::SUCCESS;
        }

        $removed = $retention->apply();
        $this->newLine();
        $this->info($removed === 0 ? 'Nothing was removable.' : "Removed {$removed} never-approved draft(s).");

        return self::SUCCESS;
    }
}
