<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Services\PlatformAdmin\AuditVerdict;
use App\Services\PlatformAdmin\PlatformAdminAuditVerifier;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PlatformAdminAuditVerifyCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:audit:verify';

    protected $description = 'Read the whole audit trail of the platform admin API and its chain of hashes, and say whether it holds and, if not, the first line that is wrong. Reads only';

    public function handle(PlatformAdminAuditVerifier $verifier, PlatformAdminSchema $schema): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $verdict = $verifier->verify();

        if (!$verdict->intact) {
            $this->error($verdict->brokenAt === null ? "The audit trail is broken: {$verdict->reason}" : "The audit trail is broken at {$verdict->describedBreak()}");

            return self::FAILURE;
        }

        $this->info($this->summaryOf(verdict: $verdict));

        if ($verdict->newestId !== null) {
            $this->line("The newest is line {$verdict->newestId}, hash {$verdict->newestHash}. Write that down where the administrator of the database cannot reach it: a later verification that still finds that line with that hash shows the trail up to it was not rewritten.");
        }

        return self::SUCCESS;
    }

    private function summaryOf(AuditVerdict $verdict): string
    {
        $lines = fn (int $count): string => $count . ' ' . Str::plural('line', $count);

        $summary = match (true) {
            $verdict->hashedLines === 0 && $verdict->unhashedLines === 0 => 'The audit trail is intact: it has no line yet.',
            $verdict->hashedLines === 0 => 'The audit trail is intact: no line is hashed yet, and ' . $lines($verdict->unhashedLines) . ' from before the chain.',
            default => 'The audit trail is intact: ' . $lines($verdict->hashedLines) . ' read' . ($verdict->unhashedLines > 0 ? ', and ' . $lines($verdict->unhashedLines) . ' from before the chain' : '') . '.',
        };

        return $verdict->checkpoint === null
            ? $summary
            : $summary . " It starts from the checkpoint of {$verdict->checkpoint->pruned_at}, which holds line {$verdict->checkpoint->last_line_id} and its hash.";
    }
}
