<?php

namespace App\Services\Catalog;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database's own refusal of two products of one country sharing a SKU or a barcode (GCC-14b slice 3, B-04).
 *
 * The validation rules of `ProductCodeScope` are the first refusal; this is the last one, and it holds for a path that never
 * reaches a form (a console command, a queue job, an import written tomorrow) and for two requests that pass validation at the
 * same instant.
 *
 * **The rule for an absent code.** Uniqueness holds among the values a person could scan, which means the non-empty ones only.
 * Most of this catalogue has no barcode and a plain unique index would read every one of those rows as the same product and
 * refuse all but the first. NULL and the empty string are both "this product has no barcode", so both are left out of the index:
 *
 * - MySQL and MariaDB have no partial index, so the index is laid over a VIRTUAL generated column (`NULLIF(code, '')`). Adding a
 *   virtual column rewrites no rows, and a unique index skips its NULLs, so a row with no code or an empty one is never compared
 *   with another. Measured on MariaDB 10.11.14: both forms of "absent" repeat freely, a repeat of a real value inside one country
 *   is refused, and the same value in two countries is accepted.
 * - SQLite and PostgreSQL take the same rule written directly, as a partial unique index.
 *
 * **The index compares as the column's collation says, not as PHP does.** On a table whose collation is case-insensitive
 * (`latin1_swedish_ci` in the shipped dump) the index reads 'A1' and 'a1' as one value while the gates, which compare in PHP,
 * read them as two. The index is therefore the stricter of the pair, never the looser; `ProductCodeReadiness` groups with the same
 * collation, so a pair the index would refuse is counted by the report before it is applied.
 *
 * Nothing here is applied by deploying code: `blockers()` is read-only and the migration refuses to create an index the live data
 * would reject rather than touch a row to make one fit.
 */
final class ProductCodeIndex
{
    public const TABLE = 'products';

    /** kind => [the generated column MySQL and MariaDB index, the index name] */
    private const INDEXES = [
        'code' => ['products_code_key', 'products_country_code_unique'],
        'barcode' => ['products_barcode_key', 'products_country_barcode_unique'],
    ];

    /** @return list<string> the kinds of code this table holds: the barcode column is guarded because two services already guard it */
    public static function kinds(): array
    {
        return array_values(array_filter(array_keys(self::INDEXES), static fn (string $kind): bool => Schema::hasColumn(self::TABLE, $kind)));
    }

    public static function indexName(string $kind): string
    {
        return self::INDEXES[$kind][1];
    }

    public static function generatedColumn(string $kind): string
    {
        return self::INDEXES[$kind][0];
    }

    public static function applied(string $kind): bool
    {
        return Schema::hasColumn(self::TABLE, StorefrontCatalog::COLUMN) && Schema::hasIndex(self::TABLE, self::indexName($kind));
    }

    /**
     * How many groups of products the index would refuse: one group is one country holding one non-empty value more than once.
     * Read only, and it answers with the database's own comparison, so a pair that differs only in letter case is counted on an
     * engine whose collation ignores case. Zero means the index can be created.
     */
    public static function blockers(string $kind): int
    {
        if (!Schema::hasColumn(self::TABLE, StorefrontCatalog::COLUMN) || !Schema::hasColumn(self::TABLE, $kind)) {
            return 0;
        }

        return DB::table(self::TABLE)
            ->whereNotNull($kind)->where($kind, '!=', '')
            ->groupBy(StorefrontCatalog::COLUMN, $kind)
            ->havingRaw('count(*) > 1')
            ->get([StorefrontCatalog::COLUMN])
            ->count();
    }

    /**
     * Creates the index for one kind of code. It does nothing when the index is already there, and it refuses — without writing
     * anything — when the live data holds a value twice inside one country. No row is ever deleted, merged or edited to make the
     * index fit: that is the owner's decision, taken with the readiness report in hand.
     *
     * @throws ProductCodeIndexBlocked
     */
    public static function create(string $kind): void
    {
        if (self::applied($kind)) {
            return;
        }

        $blockers = self::blockers($kind);
        if ($blockers > 0) {
            throw new ProductCodeIndexBlocked(kind: $kind, groups: $blockers);
        }

        $connection = Schema::getConnection();
        foreach (self::createStatements(connection: $connection, kind: $kind) as $statement) {
            $connection->statement($statement);
        }
    }

    /** Drops the index, and the generated column it was laid over where one was needed. Nothing else depends on either. */
    public static function drop(string $kind): void
    {
        $connection = Schema::getConnection();
        if (Schema::hasIndex(self::TABLE, self::indexName($kind))) {
            $connection->statement(self::usesGeneratedColumn($connection)
                ? 'ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::indexName($kind)
                : 'DROP INDEX IF EXISTS ' . self::indexName($kind));
        }

        if (self::usesGeneratedColumn($connection) && Schema::hasColumn(self::TABLE, self::generatedColumn($kind))) {
            $connection->statement('ALTER TABLE ' . self::TABLE . ' DROP COLUMN ' . self::generatedColumn($kind));
        }
    }

    /** @return list<string> */
    private static function createStatements(Connection $connection, string $kind): array
    {
        $country = StorefrontCatalog::COLUMN;
        $index = self::indexName($kind);

        if (!self::usesGeneratedColumn($connection)) {
            return ['CREATE UNIQUE INDEX ' . $index . ' ON ' . self::TABLE . ' (' . $country . ', ' . $kind . ') WHERE ' . $kind . ' IS NOT NULL AND ' . $kind . " <> ''"];
        }

        $generated = self::generatedColumn($kind);
        $statements = [];
        if (!Schema::hasColumn(self::TABLE, $generated)) {
            $statements[] = 'ALTER TABLE ' . self::TABLE . ' ADD COLUMN ' . $generated . ' ' . self::typeOf($kind) . ' AS (NULLIF(' . $kind . ", '')) VIRTUAL";
        }
        $statements[] = 'ALTER TABLE ' . self::TABLE . ' ADD UNIQUE INDEX ' . $index . ' (' . $country . ', ' . $generated . ')';

        return $statements;
    }

    /**
     * The generated column takes the **stored column's own type**, read from the schema. A length guessed here and smaller than the real one
     * would truncate the value the index is built on, and two codes that agree up to that length would collide although they are different
     * codes — an index stricter than the rule it is meant to express. A type the schema cannot name falls back to the length the
     * `add_code_to_products_table` migration gives the code.
     */
    private static function typeOf(string $kind): string
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if (($column['name'] ?? null) === $kind && is_string($column['type'] ?? null) && $column['type'] !== '') {
                return $column['type'];
            }
        }

        return 'VARCHAR(191)';
    }

    private static function usesGeneratedColumn(Connection $connection): bool
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
