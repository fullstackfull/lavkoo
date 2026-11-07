<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use Illuminate\Console\Command;

class SalesChannelsStockUsePlatformForOwnWarehouseCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:stock-use-platform-for-own-warehouse {--apply : write the change (without it the command only says what it would do)}';

    protected $description = 'Make the own warehouse read its stock from the storefront (products.current_stock) instead of from balances a person typed: a dry run unless --apply, and audited';

    public function handle(AuditLogger $audit, LocationStockReader $reader): int
    {
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }
        if (config('sales_channels.inventory.own_warehouse_source') !== 'platform') {
            $this->error('SALES_CHANNELS_OWN_WAREHOUSE_SOURCE is not "platform": the stock reader would go on reading typed balances, so the change would only make the location refuse to offer anything. Set it to "platform" (then php artisan config:clear) and run this again.');

            return self::FAILURE;
        }
        $location = InventoryLocation::where('code', InventoryLocationService::OWN_CODE)->first();
        if ($location === null) {
            $this->error('There is no own-warehouse location yet: open Stock by location once, or run any channels command, and try again.');

            return self::FAILURE;
        }
        if ($location->isStorefrontOwned()) {
            $this->info('The own warehouse already reads the storefront\'s stock; nothing to do.');

            return self::SUCCESS;
        }

        $readable = $reader->storeOwnedProducts()->count();
        $this->line(sprintf(
            'The own warehouse (%s) would change from "%s" to "%s": %d product(s) of the store would be read from products.current_stock (nothing is copied, nothing is written to a product), and the %d balance(s) typed for it would stop being read. It is still not offered to any channel: stock the store owns waits for the owner\'s decision.',
            $location->code, $location->source_of_truth, InventoryLocation::TRUTH_PLATFORM, $readable, InventoryLocationBalance::where('location_id', $location->id)->count(),
        ));
        if (!$this->option('apply')) {
            $this->warn('Dry run: nothing was written. Run again with --apply to make the change.');

            return self::SUCCESS;
        }

        $before = ['source_of_truth' => $location->source_of_truth];
        $location->forceFill(['source_of_truth' => InventoryLocation::TRUTH_PLATFORM])->save();
        $audit->record(action: 'sales_channel.own_warehouse_source_changed', subject: $location, before: $before, after: ['source_of_truth' => InventoryLocation::TRUTH_PLATFORM], context: ['by' => 'artisan']);
        $this->info('Done: the own warehouse reads the storefront\'s stock.');

        return self::SUCCESS;
    }
}
