<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Services\OperationalCosts\CostRefresher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CostsRefreshCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'costs:refresh {--days=3 : sales placed or changed in this many days are brought up to date}';

    protected $description = 'Bring the cost ledger of recently placed or changed sales up to date (estimates, missing costs, goods sold); idempotent, never rewrites an approved entry';

    public function handle(CostRefresher $refresher): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        $result = $refresher->refreshChangedSince(CarbonImmutable::now()->subDays(max(1, (int) $this->option('days')))->startOfDay());
        $this->line(sprintf('%d sales checked, %d cost entries written, %d sales could not be brought up to date.', $result['documents'], $result['written'], $result['failed']));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
