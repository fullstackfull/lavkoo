<?php

namespace App\Services\Fulfillment\Readiness;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Is this environment fit to run the fulfillment integration? Read-only: it inspects schema metadata,
 * configuration and the integration's own bookkeeping, and it never writes, sends or migrates anything.
 *
 * Three groups:
 *  - safety: which database is this, is live dispatch switched on, is the app key the public example key;
 *  - schema: do the core tables the integration reads and writes (they come from the installation dump, not
 *    from migrations) have the columns and engine it relies on, and what is the state of its own migrations;
 *  - runtime: queue, scheduler heartbeat, cache, credentials and webhook secrets.
 *
 * The same checks feed `php artisan fulfillment:preflight` (run it against a restored COPY of production before
 * the first migrate) and the "System checks" panel of the Integration Center.
 */
class FulfillmentPreflight
{
    /** The longest a fulfillment job may run (DispatchProviderFulfillment / Reconcile: 120 s). */
    private const LONGEST_JOB_SECONDS = 120;

    /** Core tables and the columns the integration needs from them. */
    private const CORE_COLUMNS = [
        'orders' => ['id', 'customer_id', 'is_guest', 'order_status', 'payment_status', 'payment_method', 'order_amount', 'paid_amount', 'shipping_cost', 'shipping_address_data', 'order_type', 'order_note', 'is_pause', 'seller_id', 'seller_is', 'created_at', 'updated_at'],
        'order_details' => ['id', 'order_id', 'product_id', 'seller_id', 'product_details', 'qty', 'price', 'discount', 'tax', 'tax_model', 'variant', 'variation', 'delivery_status'],
        'products' => ['id', 'name', 'slug', 'code', 'barcode', 'added_by', 'user_id', 'shop_id', 'product_type', 'category_ids', 'category_id', 'sub_category_id', 'sub_sub_category_id', 'brand_id', 'unit', 'details', 'colors', 'choice_options', 'attributes', 'variation', 'unit_price', 'purchase_price', 'tax', 'tax_type', 'tax_model', 'discount', 'discount_type', 'current_stock', 'minimum_order_qty', 'video_provider', 'status', 'request_status', 'shipping_cost', 'multiply_qty', 'color_image', 'images', 'thumbnail', 'thumbnail_storage_type', 'meta_title'],
        'business_settings' => ['id', 'type', 'value'],
        'currencies' => ['id', 'code', 'exchange_rate'],
        'admins' => ['id', 'admin_role_id'],
        'admin_roles' => ['id', 'module_access', 'status'],
        'categories' => ['id', 'name', 'parent_id', 'position'],
    ];

    private const OWN_TABLES = [
        'provider_fulfillments', 'provider_fulfillment_items', 'provider_fulfillment_events', 'provider_api_logs',
        'provider_sync_states', 'provider_product_mappings', 'provider_catalog_items', 'provider_price_changes',
        'provider_cities', 'provider_city_aliases', 'product_provider_changes',
    ];

    /**
     * Columns added to the feature's own tables after its first revision. The feature ships as one unreleased migration that
     * is edited in place, so a database migrated by an earlier revision of this branch has the tables but not these columns.
     */
    private const LATER_COLUMNS = [
        'provider_fulfillments' => ['cancel_intent_at', 'cancel_requested_at', 'cancel_attempts', 'cancel_next_at', 'cancel_blocked_reason'],
        'provider_catalog_items' => ['imported_hash'],
    ];

    private const MIGRATIONS = [
        '2026_11_05_100000_create_provider_fulfillment_tables',
        '2026_11_05_100100_add_fulfillment_provider_to_products_table',
    ];

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly ProviderRegistry $registry,
    ) {
    }

    /** @return list<PreflightCheck> */
    public function run(bool $allowProduction = false, bool $allowLive = false): array
    {
        return [...$this->safety($allowProduction, $allowLive), ...$this->schema(), ...$this->runtime()];
    }

    /** @return list<PreflightCheck> */
    public function safety(bool $allowProduction = false, bool $allowLive = false): array
    {
        $checks = [];
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        $location = $connection->getDriverName() . '://' . (string) config('database.connections.' . $connection->getName() . '.host', 'local') . '/' . $database;

        $checks[] = match (true) {
            app()->environment('production') && !$allowProduction => $this->fail('safety', 'database', "APP_ENV is production ({$location}). This check is meant for a restored COPY; it refuses to run here unless --allow-production-read-only is given (it only reads schema metadata)."),
            preg_match('/prod/i', $database) === 1 && !app()->environment('production') => $this->warn('safety', 'database', "{$location}: the database name looks like production although APP_ENV is " . app()->environment() . '. Make sure this is the restored copy.'),
            default => $this->pass('safety', 'database', $location),
        };

        foreach (ProviderCode::cases() as $provider) {
            $config = $this->connections->config($provider);
            $live = config('fulfillment.enabled') && $config->enabled && $config->bool('dispatch_enabled') && !$config->bool('dry_run', true);
            $checks[] = $live && !$allowLive
                ? $this->fail('safety', "{$provider->value} dispatch", 'LIVE dispatch is on: a staging validation must never send real orders. Turn off "Send orders" or turn on "Dry run" (or pass --allow-live if this is a deliberate go-live check).')
                : $this->pass('safety', "{$provider->value} dispatch", $live ? 'live (allowed by --allow-live)' : 'off or dry-run');
        }

        $example = $this->exampleAppKey();
        $isExample = $example !== null && hash_equals($example, (string) config('app.key'));
        $checks[] = match (true) {
            $isExample && app()->environment('production') => $this->fail('safety', 'app key', 'APP_KEY is the example key committed in .env.example: anyone with the repository can decrypt the stored credentials. Generate a new key and re-enter the credentials.'),
            $isExample => $this->warn('safety', 'app key', 'APP_KEY equals the example key in .env.example (fine for a throwaway test database; never for production).'),
            default => $this->pass('safety', 'app key', 'differs from the example key'),
        };

        return $checks;
    }

    /** @return list<PreflightCheck> */
    public function schema(): array
    {
        $checks = [];

        foreach (self::CORE_COLUMNS as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $checks[] = $this->fail('schema', $table, 'table is missing - restore the installation dump before migrating');
                continue;
            }

            $present = array_map(static fn (array $column): string => strtolower((string) $column['name']), Schema::getColumns($table));
            $missing = array_values(array_diff($columns, $present));
            $checks[] = $missing === []
                ? $this->pass('schema', $table, count($columns) . ' required columns present')
                : $this->fail('schema', $table, 'missing columns: ' . implode(', ', $missing));
        }

        $checks[] = $this->idTypes();
        array_push($checks, ...$this->engineAndVersion());

        $existing = array_values(array_filter(self::OWN_TABLES, static fn (string $table): bool => Schema::hasTable($table)));
        $checks[] = match (true) {
            $existing === [] => $this->pass('schema', 'fulfillment tables', 'not migrated yet (expected before the first migrate)'),
            count($existing) === count(self::OWN_TABLES) => $this->pass('schema', 'fulfillment tables', 'all ' . count($existing) . ' present'),
            default => $this->fail('schema', 'fulfillment tables', 'only ' . count($existing) . ' of ' . count(self::OWN_TABLES) . ' exist (' . implode(', ', array_diff(self::OWN_TABLES, $existing)) . ' missing): a partial migration. Roll back and re-run.'),
        };

        $checks[] = $this->laterColumns();

        $recorded = Schema::hasTable('migrations') ? DB::table('migrations')->whereIn('migration', self::MIGRATIONS)->pluck('migration')->all() : [];
        $checks[] = count($recorded) === count(self::MIGRATIONS)
            ? $this->pass('schema', 'migrations', 'both applied')
            : $this->pass('schema', 'migrations', count(self::MIGRATIONS) - count($recorded) . ' pending: ' . implode(', ', array_diff(self::MIGRATIONS, $recorded)));

        $checks[] = Schema::hasColumn('products', 'fulfillment_provider')
            ? $this->pass('schema', 'products.fulfillment_provider', 'present')
            : $this->pass('schema', 'products.fulfillment_provider', 'not added yet (expected before the first migrate)');

        return $checks;
    }

    /** @return list<PreflightCheck> */
    public function runtime(): array
    {
        $checks = [];
        $enabled = (bool) config('fulfillment.enabled');
        $checks[] = $enabled
            ? $this->warn('runtime', 'kill switch', 'FULFILLMENT_INTEGRATIONS_ENABLED is ON: providers can be called when they are enabled.')
            : $this->pass('runtime', 'kill switch', 'FULFILLMENT_INTEGRATIONS_ENABLED is off: no provider is called, queued work waits.');

        $connectionName = (string) (config('fulfillment.queue.connection') ?: config('queue.default'));
        $driver = (string) config("queue.connections.{$connectionName}.driver", $connectionName);
        $checks[] = $driver === 'sync'
            ? $this->warn('runtime', 'queue', "connection '{$connectionName}' is the sync driver: nothing is dispatched inline (by design) so the per-minute sweep does the work. Works, but a real queue is faster.")
            : $this->pass('runtime', 'queue', "connection '{$connectionName}' ({$driver})");

        if ($driver !== 'sync') {
            $retryAfter = (int) config("queue.connections.{$connectionName}.retry_after", 90);
            $checks[] = $retryAfter > self::LONGEST_JOB_SECONDS + 10
                ? $this->pass('runtime', 'queue retry_after', "{$retryAfter}s (jobs run up to " . self::LONGEST_JOB_SECONDS . 's)')
                : $this->warn('runtime', 'queue retry_after', "{$retryAfter}s is not above the longest fulfillment job (" . self::LONGEST_JOB_SECONDS . 's): a slow job could be handed to a second worker. Set retry_after to 180 on this connection. (The per-shipment lock and claim token still refuse a double send.)');
        }
        if ($driver === 'database') {
            foreach (['jobs', 'failed_jobs'] as $table) {
                $checks[] = Schema::hasTable($table) ? $this->pass('runtime', "table {$table}", 'present') : $this->fail('runtime', "table {$table}", 'missing: run the queue table migrations');
            }
        }

        $cache = (string) config('cache.default');
        $checks[] = in_array($cache, ['array', 'null'], true)
            ? $this->warn('runtime', 'cache', "driver '{$cache}' does not share locks between processes: the scheduler's overlap protection cannot work. Use file, database or redis.")
            : $this->pass('runtime', 'cache', "driver '{$cache}'");

        $checks[] = $this->heartbeat($enabled);
        $checks[] = $this->trackingFreshness($enabled);

        foreach (ProviderCode::cases() as $provider) {
            $config = $this->connections->config($provider);
            if (!$config->enabled) {
                continue;
            }
            $missing = $this->registry->adapter($provider)->readiness()->missing;
            $checks[] = $missing === []
                ? $this->pass('runtime', "{$provider->value} credentials", 'present')
                : $this->warn('runtime', "{$provider->value} credentials", 'missing: ' . implode(', ', $missing));
        }

        $torod = $this->connections->config(ProviderCode::TorodPoint);
        if ($torod->enabled && $torod->bool('webhooks_enabled')) {
            $checks[] = $torod->hasSecret('webhook_secret')
                ? $this->pass('runtime', 'torod webhook secret', 'present')
                : $this->fail('runtime', 'torod webhook secret', 'webhooks are accepted but no secret exists, so every delivery is rejected. Generate one in the Torod Point settings.');
        }

        return $checks;
    }

    private function heartbeat(bool $enabled): PreflightCheck
    {
        if (!$enabled || !Schema::hasTable('provider_sync_states')) {
            return $this->pass('runtime', 'scheduler heartbeat', $enabled ? 'not available until migrated' : 'not expected while the kill switch is off');
        }

        $state = ProviderSyncState::where('provider', ProviderSyncRecorder::SYSTEM)->where('capability', ProviderSyncRecorder::SWEEP)->first();
        if ($state === null || $state->last_attempt_at === null) {
            return $this->warn('runtime', 'scheduler heartbeat', 'the per-minute sweep (fulfillment:recover) has never run. Is the schedule:run cron installed?');
        }

        $age = Carbon::parse($state->last_attempt_at)->diffInMinutes(now(), true);
        $failing = $state->last_failure_at !== null && ($state->last_success_at === null || $state->last_failure_at->gt($state->last_success_at));

        return match (true) {
            $age > 15 => $this->fail('runtime', 'scheduler heartbeat', 'the sweep last ran ' . (int) $age . ' minutes ago: the scheduler or cron has stopped. Orders are not being dispatched or reconciled.'),
            $age > 5 => $this->warn('runtime', 'scheduler heartbeat', 'the sweep last ran ' . (int) $age . ' minutes ago (expected every minute).'),
            $failing => $this->warn('runtime', 'scheduler heartbeat', 'the sweep is running but its last run reported failures: ' . (string) $state->last_error),
            default => $this->pass('runtime', 'scheduler heartbeat', 'last sweep ' . (int) $age . ' minute(s) ago'),
        };
    }

    /**
     * A webhook that never arrives and a status poll that returns nothing look exactly alike from the outside: an open
     * shipment that simply never moves. This is the one place that notices.
     */
    private function trackingFreshness(bool $enabled): PreflightCheck
    {
        if (!$enabled || !Schema::hasTable('provider_fulfillments')) {
            return $this->pass('runtime', 'shipment tracking', 'not applicable until migrated and switched on');
        }

        $hours = (int) config('fulfillment.tracking.stale_hours', 48);
        $cutoff = now()->subHours($hours);
        $stale = ProviderFulfillment::query()
            ->whereNotIn('status', [...FulfillmentStatus::preSubmissionValues(), ...FulfillmentStatus::terminalValues()])
            ->where(static fn ($query) => $query
                ->where('last_received_at', '<', $cutoff)
                ->orWhere(static fn ($never) => $never->whereNull('last_received_at')->where('submitted_at', '<', $cutoff)))
            ->count();

        return $stale === 0
            ? $this->pass('runtime', 'shipment tracking', "every open shipment has heard from its provider within {$hours} hours")
            : $this->warn('runtime', 'shipment tracking', "{$stale} open shipment(s) have had no update from their provider for over {$hours} hours. Check the webhook configuration and the status poll (M5AZN has no webhook: its tracking depends on the poll and a verified order field map).");
    }

    private function laterColumns(): PreflightCheck
    {
        $missing = [];
        foreach (self::LATER_COLUMNS as $table => $columns) {
            if (Schema::hasTable($table)) {
                array_push($missing, ...array_map(static fn (string $column): string => "{$table}.{$column}", array_filter($columns, static fn (string $column): bool => !Schema::hasColumn($table, $column))));
            }
        }

        return $missing === []
            ? $this->pass('schema', 'fulfillment columns', 'up to date')
            : $this->fail('schema', 'fulfillment columns', 'missing ' . implode(', ', $missing) . ': this database was migrated by an earlier revision of the feature. On a throwaway or staging database roll that one migration back and migrate again; never edit a production schema by hand.');
    }

    private function idTypes(): PreflightCheck
    {
        $odd = [];
        foreach (['orders' => 'id', 'order_details' => 'order_id', 'products' => 'id'] as $table => $column) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getColumns($table) as $definition) {
                if (strtolower((string) $definition['name']) === $column && !str_contains(strtolower((string) $definition['type_name']), 'int')) {
                    $odd[] = "{$table}.{$column} is {$definition['type_name']}";
                }
            }
        }

        return $odd === []
            ? $this->pass('schema', 'id types', 'orders.id, order_details.order_id and products.id are integers (the integration stores them as bigint without foreign keys)')
            : $this->fail('schema', 'id types', implode('; ', $odd));
    }

    /** @return list<PreflightCheck> */
    private function engineAndVersion(): array
    {
        $connection = DB::connection();
        $version = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $driver = $connection->getDriverName();
        $checks = [];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $isMaria = stripos($version, 'mariadb') !== false;
            $number = (float) preg_replace('/^(\d+\.\d+).*/', '$1', $version);
            $checks[] = ($isMaria && $number >= 10.5) || (!$isMaria && $number >= 8.0)
                ? $this->pass('schema', 'server version', $version)
                : $this->warn('schema', 'server version', "{$version}: the integration is tested on MySQL 8.0; older servers lack some JSON behaviour it relies on.");

            $engines = DB::table('information_schema.tables')->where('table_schema', $connection->getDatabaseName())->whereIn('table_name', ['orders', 'order_details', 'products'])->pluck('engine', 'table_name');
            $notInnoDb = $engines->filter(static fn ($engine): bool => strtolower((string) $engine) !== 'innodb')->keys()->all();
            $checks[] = $notInnoDb === []
                ? $this->pass('schema', 'storage engine', 'orders, order_details, products are InnoDB (transactions and row locks work)')
                : $this->fail('schema', 'storage engine', implode(', ', $notInnoDb) . ' is not InnoDB: row locks and transactions - which duplicate prevention relies on - do nothing on it. Convert to InnoDB first.');

            $collation = DB::table('information_schema.tables')->where('table_schema', $connection->getDatabaseName())->where('table_name', 'orders')->value('table_collation');
            $checks[] = $this->pass('schema', 'collation', 'orders: ' . (string) $collation . ' (the integration joins on integer ids only)');
        } else {
            $checks[] = $this->warn('schema', 'server version', "{$driver} {$version}: not MySQL, so engine and collation were not checked. Production runs MySQL.");
        }

        return $checks;
    }

    private function exampleAppKey(): ?string
    {
        $path = base_path('.env.example');
        if (!is_file($path)) {
            return null;
        }

        return preg_match('/^APP_KEY=(.+)$/m', (string) file_get_contents($path), $matches) === 1 ? trim($matches[1], " \t\"'") : null;
    }

    private function pass(string $group, string $name, string $detail): PreflightCheck
    {
        return new PreflightCheck($group, $name, PreflightCheck::PASS, $detail);
    }

    private function warn(string $group, string $name, string $detail): PreflightCheck
    {
        return new PreflightCheck($group, $name, PreflightCheck::WARN, $detail);
    }

    private function fail(string $group, string $name, string $detail): PreflightCheck
    {
        return new PreflightCheck($group, $name, PreflightCheck::FAIL, $detail);
    }
}
