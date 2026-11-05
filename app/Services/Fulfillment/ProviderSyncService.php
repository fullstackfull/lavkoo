<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\Providers\M5azn\M5aznAdapter;
use App\Services\Fulfillment\Providers\M5azn\M5aznProductImporter;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;

/**
 * Runs a provider's pull-style synchronisations: its product list, its stock, its city list.
 *
 * One code path for the scheduler and for the admin "Sync now" buttons. Each task honours the provider's
 * own switches and never throws: the result says what happened, or why nothing did.
 */
class ProviderSyncService
{
    public const PRODUCTS = 'products';
    public const INVENTORY = 'inventory';
    public const CITIES = 'cities';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderConnectionManager $connections,
        private readonly M5aznProductImporter $importer,
    ) {
    }

    /**
     * @param list<string> $tasks
     * @return array<string, array<string, mixed>> task => result
     */
    public function run(ProviderCode $provider, array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $results[$task] = $this->guarded(fn (): array => $this->task($provider, $task));
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function task(ProviderCode $provider, string $task): array
    {
        $config = $this->connections->config($provider);
        $adapter = $this->registry->adapter($provider);

        return match (true) {
            $task === self::PRODUCTS && $provider === ProviderCode::M5azn && $adapter instanceof M5aznAdapter => $this->m5aznProducts($adapter, $config->bool('product_sync_enabled'), $config->bool('import_enabled')),
            $task === self::PRODUCTS && $adapter instanceof TorodPointAdapter => $config->bool('product_sync_enabled') ? $adapter->syncCatalog() : $this->off('product_sync_enabled'),
            $task === self::INVENTORY && ($adapter instanceof M5aznAdapter || $adapter instanceof TorodPointAdapter) => $config->bool('inventory_sync_enabled') ? $adapter->syncInventory() : $this->off('inventory_sync_enabled'),
            $task === self::CITIES && $adapter instanceof TorodPointAdapter => $adapter->syncCities(),
            default => ['ok' => false, 'error' => 'not_applicable'],
        };
    }

    /** @return array<string, mixed> */
    private function m5aznProducts(M5aznAdapter $adapter, bool $syncEnabled, bool $importEnabled): array
    {
        if (!$syncEnabled) {
            return $this->off('product_sync_enabled');
        }

        $result = $adapter->syncCatalog();
        if ($result['ok'] && $importEnabled) {
            $result['import'] = $this->importer->importPending();
        }

        return $result;
    }

    /** @param callable(): array<string, mixed> $work @return array<string, mixed> */
    private function guarded(callable $work): array
    {
        try {
            return $work();
        } catch (ProviderUnavailableException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /** @return array{ok: false, error: string} */
    private function off(string $setting): array
    {
        return ['ok' => false, 'error' => 'switched_off:' . $setting];
    }
}
