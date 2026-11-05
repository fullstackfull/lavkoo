<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderSyncService;
use Illuminate\Console\Command;

class FulfillmentSyncCommand extends Command
{
    use SkipsUntilMigrated;

    protected $signature = 'fulfillment:sync
        {provider? : m5azn or torod_point (default: every enabled provider)}
        {--only=products,inventory : comma separated: products, inventory, cities}
        {--due : run only the tasks whose provider interval has elapsed (what the scheduler uses)}';

    protected $description = 'Pull provider products, stock and city lists (each honours the provider\'s own switches)';

    public function handle(ProviderSyncService $sync, ProviderConnectionManager $connections): int
    {
        if (!config('fulfillment.enabled')) {
            $this->line('Fulfillment integrations are disabled; nothing to do.');

            return self::SUCCESS;
        }
        if ($this->notMigratedYet()) {
            return self::SUCCESS;
        }

        $providers = $this->argument('provider') !== null
            ? array_filter([ProviderCode::tryFrom((string) $this->argument('provider'))])
            : array_filter(ProviderCode::cases(), static fn (ProviderCode $code): bool => $connections->config($code)->enabled);
        $tasks = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $failed = false;

        foreach ($providers as $provider) {
            $due = $this->option('due') ? array_values(array_filter($tasks, static fn (string $task): bool => $sync->isDue($provider, $task))) : $tasks;

            foreach ($sync->run($provider, $due) as $task => $result) {
                $ok = (bool) ($result['ok'] ?? false);
                $failed = $failed || (!$ok && !str_starts_with((string) ($result['error'] ?? ''), 'switched_off'));
                $this->line(sprintf('%s %s: %s', $provider->value, $task, $ok ? 'ok ' . json_encode($result['stats'] ?? $result['count'] ?? '') : (string) ($result['error'] ?? 'failed')));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
