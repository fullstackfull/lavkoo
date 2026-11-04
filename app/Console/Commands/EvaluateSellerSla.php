<?php

namespace App\Console\Commands;

use App\Services\Marketplace\SlaService;
use Illuminate\Console\Command;

/**
 * Evaluate every seller against the configured SLA thresholds, reconciling the breach ledger.
 *
 * Previously SLA evaluation only ran when an admin clicked the button on the SLA page, so the breach
 * ledger went stale between visits. Scheduled daily (bootstrap/app.php) it keeps enforcement current;
 * the admin button remains for on-demand re-runs.
 *
 *   php artisan marketplace:evaluate-sla
 */
class EvaluateSellerSla extends Command
{
    protected $signature = 'marketplace:evaluate-sla';

    protected $description = 'Evaluate seller SLA thresholds and reconcile the breach ledger';

    public function handle(SlaService $slaService): int
    {
        $affected = $slaService->evaluateAll();
        $this->info(sprintf('SLA evaluated: %d seller breach ledger(s) reconciled.', $affected));

        return self::SUCCESS;
    }
}
