<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\SalesChannelAccount;
use Illuminate\Console\Command;

class SalesChannelsStatusCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:status';

    protected $description = 'Show each sales channel account: whether it is on, what it may do, whether it may write, and when it last synced';

    public function handle(): int
    {
        $this->line('Global switch (SALES_CHANNELS_ENABLED): ' . (config('sales_channels.enabled') ? 'ON' : 'off'));
        $this->line('External writes (SALES_CHANNELS_EXTERNAL_WRITES_ENABLED): ' . (config('sales_channels.external_writes_enabled') ? 'ON' : 'off'));
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'channel', 'name', 'env', 'enabled', 'writes', 'dry run', 'connection', 'capabilities', 'last sync'],
            SalesChannelAccount::orderBy('id')->get()->map(fn (SalesChannelAccount $account): array => [
                $account->id, $account->channel, $account->name, $account->environment, $account->enabled ? 'yes' : 'no', $account->write_enabled ? 'yes' : 'no', $account->dry_run ? 'yes' : 'no',
                $account->connectionStatus()->value, implode(',', (array) $account->capabilities) ?: '-', $account->last_synced_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
