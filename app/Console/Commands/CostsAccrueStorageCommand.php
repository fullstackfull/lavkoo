<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Console\Commands\Concerns\SkipsWhileOperationalCostsAreOff;
use App\Models\InventoryLocation;
use App\Services\OperationalCosts\StorageCostService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CostsAccrueStorageCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;
    use SkipsWhileOperationalCostsAreOff;

    protected $signature = 'costs:accrue-storage {--date= : the day to accrue (default: yesterday)}';

    protected $description = 'Record what it cost to keep stock at each provider warehouse on a day, from the contract (a day with no stock figure we can trust is a missing cost, never a free day)';

    public function handle(StorageCostService $storage): int
    {
        if ($this->operationalCostsAreOff() || $this->notMigratedYet()) {
            return self::SUCCESS;
        }
        $day = $this->option('date') !== null ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::yesterday();
        $written = 0;
        $failed = 0;
        foreach (InventoryLocation::where('is_active', true)->whereNotNull('provider')->get() as $location) {
            try {
                $written += count(array_filter($storage->accrue($location, $day), static fn ($outcome): bool => $outcome->wroteSomething()));
            } catch (Throwable $exception) {
                // One warehouse that cannot be accrued must not keep the others from being accrued.
                report($exception);
                $failed++;
            }
        }
        $this->line("{$written} storage cost entries written for {$day->toDateString()}" . ($failed > 0 ? "; {$failed} locations could not be accrued." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
