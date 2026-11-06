<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Services\Fulfillment\Readiness\FulfillmentPreflight;
use Illuminate\Database\Migrations\Migrator;

/**
 * What `php artisan migrate` would do to THIS database. It runs EVERY pending migration, not only the integration's two:
 * a restored dump whose `migrations` table is behind the code (or empty) would have a long list of the platform's own
 * migrations applied in the same run. That list belongs in the rehearsal, so it is shown here, read-only, from the
 * migration files and the `migrations` table.
 */
final class MigrationsSection implements CompatibilitySection
{
    private const SECTION = 'migrations';

    /** Pending migrations are listed by name up to this many; the count is always complete. */
    private const MAX_LISTED = 40;

    /** Tables whose structure checkout, orders and the integration depend on. */
    private const CORE_TABLES = ['orders', 'order_details', 'products', 'carts', 'business_settings', 'currencies', 'stock_movements'];

    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        $files = $this->migrator->getMigrationFiles([...$this->migrator->paths(), database_path('migrations')]);
        $ran = $probe->migrationsRan();

        if ($ran === null) {
            return [new CompatibilityCheck(
                section: self::SECTION,
                name: 'migrations table',
                status: CompatibilityCheck::WARN,
                detail: 'the database has no migrations table: every one of the ' . count($files) . ' migration files would be pending. This is not a restored platform database; never run `php artisan migrate` on it (restore the installation dump first).',
                facts: ['migration_files' => count($files), 'recorded' => 0],
            )];
        }

        $pending = array_values(array_diff(array_keys($files), $ran));
        $ours = array_values(array_intersect($pending, [...FulfillmentPreflight::MIGRATIONS, ...FulfillmentPreflight::COMPATIBILITY_MIGRATIONS]));
        $platform = array_values(array_diff($pending, $ours));
        $touchingCore = array_values(array_filter($platform, fn (string $name): bool => $this->touchesCoreTables($files[$name])));

        return [
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'pending migrations',
                status: $platform === [] ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
                detail: $platform === []
                    ? 'the only pending migrations are the integration\'s own (' . count($ours) . ')'
                    : 'php artisan migrate would also apply ' . count($platform) . ' migration(s) of the platform itself, next to the integration\'s ' . count($ours) . '. Rehearse the whole set on the restored copy, not the integration\'s two alone',
                facts: [
                    'migration_files' => count($files),
                    'recorded' => count($ran),
                    'pending' => count($pending),
                    'pending_integration' => $ours,
                    'pending_platform' => count($platform),
                    'pending_platform_listed' => array_slice($platform, 0, self::MAX_LISTED),
                    'pending_platform_not_listed' => max(0, count($platform) - self::MAX_LISTED),
                ],
            ),
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'pending platform migrations that change checkout tables',
                status: $touchingCore === [] ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
                detail: $touchingCore === []
                    ? 'no pending platform migration creates, alters or drops ' . implode(', ', self::CORE_TABLES)
                    : count($touchingCore) . ' pending platform migration(s) create, alter or drop a table that checkout or the integration reads: they change the schema this validation just checked, so run it again after the rehearsal migrate',
                facts: ['migrations' => array_slice($touchingCore, 0, self::MAX_LISTED)],
            ),
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'recorded migrations with no file',
                status: CompatibilityCheck::INFO,
                detail: ($missing = count(array_diff($ran, array_keys($files)))) . ' migration(s) are recorded as run but have no file in this codebase (normal when the database comes from a newer or customised build)',
                facts: ['recorded_without_file' => $missing],
            ),
        ];
    }

    private function touchesCoreTables(string $path): bool
    {
        $source = (string) @file_get_contents($path);
        $tables = implode('|', array_map('preg_quote', self::CORE_TABLES));

        return preg_match("/Schema::(?:table|create|drop|dropIfExists|rename)\\(\\s*['\"](?:{$tables})['\"]/", $source) === 1
            || preg_match("/(?:ALTER|DROP|TRUNCATE|RENAME)\\s+TABLE\\s+[`'\"]?(?:{$tables})[`'\"]?\\b/i", $source) === 1;
    }
}
