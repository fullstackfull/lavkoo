<?php

namespace App\Console\Commands;

use App\Services\Monitoring\Alerting\AlertEvaluator;
use Illuminate\Console\Command;

/**
 * Compare every enabled alert rule against what was actually measured this minute.
 *
 * Collection without evaluation is a wall of charts nobody is watching at three in the morning.
 * This is the part that decides something is wrong and says so — and, just as importantly, the
 * part that stays quiet: a rule only fires once its condition has held for its whole window, and a
 * metric that stopped arriving fires nothing at all, because absent is not zero.
 */
class MonitoringEvaluate extends Command
{
    protected $signature = 'monitoring:evaluate
                            {--seed : No longer needed; the shipped rules are installed on the first run}
                            {--force-seed : Re-install any shipped rule that has been deleted}
                            {--quiet-ok : Only print rules that are not ok}';

    protected $description = 'Evaluate the monitoring alert rules against the latest measurements';

    public function handle(AlertEvaluator $evaluator): int
    {
        if (!config('monitoring.enabled', true)) {
            $this->warn('Monitoring is disabled (MONITORING_ENABLED=false); no rule was evaluated.');

            return self::SUCCESS;
        }

        // Seeded on the first run, not only when somebody remembers the flag. The scheduled
        // evaluator runs every minute without it, so an install whose operator never typed
        // `--seed` evaluated zero rules forever and nothing could ever page anyone — the alerting
        // chain was complete and silent. seedDefaults() is marker-guarded, so this happens once.
        $created = $evaluator->seedDefaults(force: (bool) $this->option('force-seed'));

        if ($created > 0) {
            $this->info("Created {$created} alert rule(s).");
        } elseif ($this->option('seed') || $this->option('force-seed')) {
            $this->info('No rule needed to be created.');
        }

        $outcomes = $evaluator->evaluate();

        if ($outcomes === []) {
            $this->warn('No alert rule is enabled. Add or re-enable one in Monitoring → Alerts.');

            return self::SUCCESS;
        }

        $shown = $this->option('quiet-ok')
            ? array_filter($outcomes, static fn (array $outcome) => $outcome['state'] !== 'ok')
            : $outcomes;

        if ($shown !== []) {
            $this->table(
                ['Rule', 'Metric', 'State', 'Value', 'Note'],
                array_map(static fn (array $outcome) => [
                    $outcome['rule'],
                    $outcome['metric'],
                    $outcome['state'],
                    $outcome['value'] === null ? '—' : rtrim(rtrim(number_format((float) $outcome['value'], 2, '.', ''), '0'), '.'),
                    $outcome['note'],
                ], $shown),
            );
        }

        $firing = array_filter($outcomes, static fn (array $outcome) => in_array($outcome['state'], ['warning', 'critical'], true));

        $this->line(sprintf(
            '%d rule(s) evaluated, %d firing.',
            count($outcomes),
            count($firing),
        ));

        return self::SUCCESS;
    }
}
