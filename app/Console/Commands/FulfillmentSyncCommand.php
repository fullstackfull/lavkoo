<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SkipsUntilMigrated;
use App\Enums\Fulfillment\CitySyncVerdict;
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
                $error = (string) ($result['error'] ?? '');
                if (!$ok && $error === 'not_applicable') {
                    $this->line(sprintf('%s %s: not applicable to this provider, skipped', $provider->value, $task));

                    continue;
                }

                $partial = $ok && ($result['verdict'] ?? null) === CitySyncVerdict::Partial->value;
                $failed = $failed || $partial || (!$ok && !str_starts_with($error, 'switched_off'));
                $this->line(sprintf('%s %s: %s', $provider->value, $task, match (true) {
                    $partial => 'PARTIAL ' . json_encode($result['count'] ?? 0) . ' (' . ($result['reason'] ?? '') . ')',
                    $ok => 'ok ' . json_encode($result['stats'] ?? $result['count'] ?? '') . (isset($result['approved']) ? sprintf(' (%d approved automatically, %d proposed)', $result['approved'], $result['proposed']) : ''),
                    default => $error !== '' ? $error : 'failed',
                }));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
