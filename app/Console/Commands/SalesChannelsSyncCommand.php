<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Sync\ChannelSyncService;
use Illuminate\Console\Command;

class SalesChannelsSyncCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:sync
        {account? : the sales channel account id (default: every enabled account)}
        {--only=orders,listings,inventory,finances : comma separated resources}
        {--due : run only the resources whose interval has elapsed (what the scheduler uses)}';

    protected $description = 'Read orders, listings, channel-held stock and statements from the sales channels (read-only; each honours the account switches)';

    public function handle(ChannelSyncService $sync): int
    {
        if (!config('sales_channels.enabled')) {
            $this->line('Sales channel integrations are switched off (SALES_CHANNELS_ENABLED); nothing to do.');

            return self::SUCCESS;
        }
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $accounts = $this->argument('account') !== null
            ? SalesChannelAccount::whereKey((int) $this->argument('account'))->get()
            : SalesChannelAccount::enabled()->orderBy('id')->get();
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $failed = false;

        foreach ($accounts as $account) {
            $results = $this->option('due') ? $sync->runDue($account) : $this->runSelected($sync, $account, $only);
            foreach ($results as $resource => $result) {
                $failed = $failed || $result['result'] === 'failed';
                $this->line(sprintf('%s #%d %s: %s%s', $account->channel, $account->id, $resource, $result['result'], $result['detail'] === null ? '' : ' (' . $result['detail'] . ')'));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<string> $only
     * @return array<string, array{result: string, detail: string|null, stats: array<string, mixed>}>
     */
    private function runSelected(ChannelSyncService $sync, SalesChannelAccount $account, array $only): array
    {
        $methods = [
            ChannelSyncService::ORDERS => 'syncOrders',
            ChannelSyncService::LISTINGS => 'syncListings',
            ChannelSyncService::INVENTORY => 'syncChannelInventory',
            ChannelSyncService::FINANCES => 'syncFinances',
        ];
        $results = [];
        foreach (array_intersect_key($methods, array_flip($only)) as $resource => $method) {
            $results[$resource] = $sync->{$method}($account);
        }

        return $results;
    }
}
