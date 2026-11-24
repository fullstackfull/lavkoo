<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;

/**
 * No migration may ask for an index name longer than a real database allows.
 *
 * MySQL and MariaDB refuse an identifier over 64 characters; SQLite does not care. So a migration whose index name Laravel builds from the
 * table and the columns runs green in the whole SQLite suite and fails on every real server with "Identifier name is too long" -- which is
 * exactly what happened to `product_destination_permissions` (`..._product_id_destination_country_unique`, 68 characters) and was caught
 * by one MariaDB job after the migration had been written, reviewed and pushed.
 *
 * This reads the migration source rather than running anything, so it catches the name before a server does: a table with a long name and
 * two columns is over the limit without anybody noticing, and the fix is always the same -- pass the index a short name of its own.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    /** What MySQL and MariaDB allow. */
    private const LIMIT = 64;

    /**
     * Index names in migrations too old to be worth rewriting: they are on the deployed servers already, so renaming one now would be a
     * change of shape on a live table to fix nothing. Anything NEW has to be inside the limit.
     *
     * @var list<string>
     */
    private const ALREADY_DEPLOYED = [];

    /** @return list<array{file: string, name: string, length: int}> */
    private function overlongIdentifiers(): array
    {
        $found = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $table = $this->tableOf($source);

            if ($table === null) {
                continue;
            }

            foreach ($this->implicitIndexNames($source, $table) as $name) {
                if (strlen($name) > self::LIMIT && !in_array($name, self::ALREADY_DEPLOYED, true)) {
                    $found[] = ['file' => basename($file), 'name' => $name, 'length' => strlen($name)];
                }
            }

            foreach ($this->explicitIndexNames($source) as $name) {
                if (strlen($name) > self::LIMIT && !in_array($name, self::ALREADY_DEPLOYED, true)) {
                    $found[] = ['file' => basename($file), 'name' => $name, 'length' => strlen($name)];
                }
            }
        }

        return $found;
    }

    /** The table a migration is about, from its first `Schema::create` or `Schema::table`. */
    private function tableOf(string $source): ?string
    {
        return preg_match("/Schema::(?:create|table)\(\s*'([a-z0-9_]+)'/", $source, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The names Laravel would build for an index declared without one: the table, the columns and the kind, joined by underscores.
     *
     * @return list<string>
     */
    private function implicitIndexNames(string $source, string $table): array
    {
        preg_match_all("/->(unique|index|primary|fullText|spatialIndex)\(\s*\[([^\]]*)\]\s*\)/", $source, $matches, PREG_SET_ORDER);

        $names = [];

        foreach ($matches as $match) {
            $columns = [];
            preg_match_all("/'([a-z0-9_]+)'/", $match[2], $columnMatches);
            foreach ($columnMatches[1] as $column) {
                $columns[] = $column;
            }

            if ($columns !== []) {
                $names[] = strtolower($table . '_' . implode('_', $columns) . '_' . $this->suffixOf($match[1]));
            }
        }

        return $names;
    }

    /**
     * The names a migration spells out itself, which are refused over the limit just as readily.
     *
     * @return list<string>
     */
    private function explicitIndexNames(string $source): array
    {
        preg_match_all("/->(?:unique|index|primary|fullText|spatialIndex)\(\s*(?:\[[^\]]*\]|'[a-z0-9_]+')\s*,\s*'([a-z0-9_]+)'/", $source, $matches);

        return $matches[1];
    }

    private function suffixOf(string $kind): string
    {
        return match ($kind) {
            'unique' => 'unique',
            'primary' => 'primary',
            'fullText' => 'fulltext',
            'spatialIndex' => 'spatialindex',
            default => 'index',
        };
    }

    public function test_no_migration_asks_for_an_index_name_a_real_database_would_refuse(): void
    {
        $overlong = $this->overlongIdentifiers();

        $this->assertSame(
            [],
            $overlong,
            "these index names are over MySQL's " . self::LIMIT . "-character limit and would fail on a real server:\n"
            . implode("\n", array_map(
                static fn (array $row): string => "  {$row['file']}: {$row['name']} ({$row['length']})",
                $overlong,
            ))
            . "\nGive the index a short name of its own: \$table->unique([...], 'a_short_name').",
        );
    }

    /** The scan itself has to be looking at something: a pattern that matches nothing would pass this file for ever. */
    public function test_the_scan_reads_the_migrations_it_is_meant_to(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        $this->assertGreaterThan(100, count($files), 'the migrations are there to read');

        $names = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $table = $this->tableOf($source);
            if ($table !== null) {
                $names = [...$names, ...$this->implicitIndexNames($source, $table), ...$this->explicitIndexNames($source)];
            }
        }

        $this->assertGreaterThan(20, count($names), 'and index names are being found in them');
    }

    /** And the measure is the one that matters: the name this platform had to shorten is caught by it. */
    public function test_the_name_this_guard_was_written_for_is_over_the_limit(): void
    {
        $this->assertGreaterThan(
            self::LIMIT,
            strlen('product_destination_permissions_product_id_destination_country_unique'),
        );
    }
}
