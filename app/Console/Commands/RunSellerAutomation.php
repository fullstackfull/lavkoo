<?php

namespace App\Console\Commands;

use App\Models\SellerAutomationRun;
use App\Services\SellerAutomation\AutomationEngine;
use Illuminate\Console\Command;

/**
 * Run every seller rule that is due.
 *
 * Rules are evaluated on a schedule rather than fired from model events, deliberately. An
 * event-driven rule fires inside whatever request happened to change the row, which means a
 * customer's checkout can end up hiding a listing, and a rule that misbehaves takes a checkout down
 * with it. A sweep has none of that coupling: it runs on its own, it is bounded, and a rule that
 * fails fails alone.
 */
class RunSellerAutomation extends Command
{
    protected $signature = 'seller:run-automation {--seller= : Only this seller} {--limit=200 : Rules per sweep}';

    protected $description = 'Evaluate seller automation rules that are due to run';

    public function handle(AutomationEngine $engine): int
    {
        $runs = $engine->runDue(
            sellerId: $this->option('seller') ? (int) $this->option('seller') : null,
            limit: max(1, (int) $this->option('limit')),
        );

        $applied = array_sum(array_map(fn (SellerAutomationRun $run) => $run->applied_count, $runs));

        $this->info(sprintf('%d rule(s) evaluated, %d change(s) applied.', count($runs), $applied));

        return self::SUCCESS;
    }
}
