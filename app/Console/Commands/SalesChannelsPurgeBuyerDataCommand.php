<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Services\SalesChannels\Dispatch\BuyerDataPurger;
use Illuminate\Console\Command;

class SalesChannelsPurgeBuyerDataCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:purge-buyer-data {--limit=200 : orders per run}';

    protected $description = 'Remove the buyer details of dispatched channel orders once the provider holds the shipment, or once a rehearsal has run its time. It runs whatever the dispatch switches say: switching dispatch off never keeps data longer.';

    public function handle(BuyerDataPurger $purger): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $counts = $purger->run((int) $this->option('limit'));
        $this->info('Buyer data: ' . collect($counts)->map(static fn (int $count, string $outcome): string => "{$outcome} {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
