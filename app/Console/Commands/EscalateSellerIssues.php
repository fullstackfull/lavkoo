<?php

namespace App\Console\Commands;

use App\Services\SellerIntelligence\IssueEscalationService;
use Illuminate\Console\Command;

/**
 * Promote issues nobody has answered.
 *
 * Separate from the detection sweep on purpose. Detection asks "is this true"; escalation asks "how
 * long has this been true and has anyone done anything" — and the second question has a useful
 * answer far less often than the first, so it runs less often and touches only rows nobody has
 * touched.
 */
class EscalateSellerIssues extends Command
{
    protected $signature = 'seller:escalate-issues {--seller= : Only this seller}';

    protected $description = 'Raise the severity of seller issues that have gone unanswered';

    public function handle(IssueEscalationService $escalation): int
    {
        $result = $escalation->sweep($this->option('seller') ? (int) $this->option('seller') : null);

        $this->info(sprintf('%d issue(s) escalated.', $result['escalated']));

        return self::SUCCESS;
    }
}
