<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\FulfillmentRecovery;
use Illuminate\Console\Command;

class FulfillmentPollCommand extends Command
{
    protected $signature = 'fulfillment:poll';

    protected $description = 'Ask each provider where its open fulfillments stand (the net under a missed webhook)';

    public function handle(FulfillmentRecovery $recovery): int
    {
        if (!config('fulfillment.enabled')) {
            $this->line('Fulfillment integrations are disabled; nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Polled ' . $recovery->pollOpenStatuses() . ' fulfillment(s).');

        return self::SUCCESS;
    }
}
