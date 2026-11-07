<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Console\Command;

class SalesChannelsDispatchSyncCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:dispatch-sync {--limit=200 : orders per run}';

    protected $description = 'Bring dispatched channel orders in step with their provider shipment (link on acceptance, flag failures, close on cancellation). Reads local tables only; calls nobody.';

    public function handle(ManagedShipmentMirror $mirror): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $counts = $mirror->sweep((int) $this->option('limit'));
        $this->info('Dispatch sync: ' . collect($counts)->map(static fn (int $count, string $outcome): string => "{$outcome} {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
