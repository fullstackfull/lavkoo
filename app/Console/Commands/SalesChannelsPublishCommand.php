<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilSalesChannelsMigrated;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use Illuminate\Console\Command;

class SalesChannelsPublishCommand extends Command
{
    use SkipsUntilSalesChannelsMigrated;

    protected $signature = 'channels:publish
        {account? : the sales channel account id (default: every enabled account)}
        {--only=stock,price,settle,shipments : comma separated: allocate (after an order import: divide the pools whose orders changed and lower what they lowered), stock, price, settle (follow up what the channel queued), shipments (tell the channel about linked shipments)}';

    protected $description = 'Publish confirmed stock and set prices to the sales channels (a WRITE: needs the environment switch, the account switch and a recorded approval; a dry-run account only rehearses)';

    public function handle(StockPricePublisher $publisher, ChannelShipmentNotifier $notifier, DirtyPoolRun $dirtyPools): int
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

        if (in_array('allocate', $only, true) && $accounts->isNotEmpty()) {
            $this->line('allocate: ' . json_encode($dirtyPools->run($this->argument('account') !== null ? $accounts->first() : null)));
        }
        foreach ($accounts as $account) {
            foreach (['stock' => 'publishStock', 'price' => 'publishPrices'] as $name => $method) {
                if (in_array($name, $only, true)) {
                    $result = $publisher->{$method}($account);
                    $failed = $failed || $result['result'] === 'failed';
                    $this->line(sprintf('%s #%d publish %s: %s %s', $account->channel, $account->id, $name, $result['result'], json_encode($result['stats'])));
                }
            }
            if (in_array('shipments', $only, true) && $account->enabled) {
                $this->line(sprintf('%s #%d shipments: %s', $account->channel, $account->id, json_encode($notifier->notifyDue($account))));
            }
            if (in_array('settle', $only, true) && $account->enabled) {
                $this->line(sprintf('%s #%d settle: %s', $account->channel, $account->id, json_encode($publisher->settle($account))));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
