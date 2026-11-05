<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\FulfillmentRecovery;
use Illuminate\Console\Command;

class FulfillmentRecoverCommand extends Command
{
    protected $signature = 'fulfillment:recover';

    protected $description = 'Route eligible orders, dispatch due fulfillments, reconcile unknown outcomes and cancel for closed orders';

    public function handle(FulfillmentRecovery $recovery): int
    {
        if (!config('fulfillment.enabled')) {
            $this->line('Fulfillment integrations are disabled (FULFILLMENT_INTEGRATIONS_ENABLED); nothing to do.');

            return self::SUCCESS;
        }

        $counts = $recovery->runAll();
        $this->table(['sweep', 'handled'], array_map(static fn (string $sweep, int $count): array => [$sweep, $count], array_keys($counts), $counts));

        return self::SUCCESS;
    }
}
