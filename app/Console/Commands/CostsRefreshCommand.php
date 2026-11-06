<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Services\OperationalCosts\CostRefresher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CostsRefreshCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'costs:refresh {--days=3 : how many days back to bring up to date}';

    protected $description = 'Bring the cost ledger of recent sales up to date (estimates, missing costs); idempotent, never rewrites an approved entry';

    public function handle(CostRefresher $refresher): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        $result = $refresher->refreshPeriod(CarbonImmutable::now()->subDays(max(1, (int) $this->option('days')))->startOfDay(), CarbonImmutable::now());
        $this->line(sprintf('%d sales checked, %d cost entries written.', $result['documents'], $result['written']));

        return self::SUCCESS;
    }
}
