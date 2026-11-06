<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Services\Fulfillment\FulfillmentRecovery;
use Illuminate\Console\Command;

class FulfillmentRecoverCommand extends Command
{
    use SkipsUntilMigrated;

    protected $signature = 'fulfillment:recover';

    protected $description = 'Route eligible orders, dispatch due fulfillments, reconcile unknown outcomes and cancel for closed orders';

    public function handle(FulfillmentRecovery $recovery): int
    {
        if (!config('fulfillment.enabled')) {
            $this->line('Fulfillment integrations are disabled (FULFILLMENT_INTEGRATIONS_ENABLED); nothing to do.');

            return self::SUCCESS;
        }
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $counts = $recovery->runAll();
        $this->table(['sweep', 'handled'], array_map(static fn (string $sweep, int $count): array => [$sweep, $count < 0 ? 'FAILED (see log)' : $count], array_keys($counts), $counts));

        return min($counts) < 0 ? self::FAILURE : self::SUCCESS;
    }
}
