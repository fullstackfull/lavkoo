<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Services\SalesChannels\Shipments\ShipmentDiscovery;
use Illuminate\Console\Command;

class SalesChannelsFindShipmentsCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:find-shipments {--limit=100 : orders per run}';

    protected $description = 'Look for the provider shipment of channel orders whose shipping the provider arranges directly, link the ones that can be proven and hand the rest to a person. Reads only; nothing is sent to the provider or the channel.';

    public function handle(ShipmentDiscovery $discovery): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        if (!config('sales_channels.shipment_discovery.enabled', false)) {
            $this->line('Shipment discovery is switched off; nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Shipment discovery: ' . collect($discovery->run((int) $this->option('limit')))->map(static fn (int $count, string $outcome): string => "{$outcome} {$count}")->implode(', '));

        return self::SUCCESS;
    }
}
