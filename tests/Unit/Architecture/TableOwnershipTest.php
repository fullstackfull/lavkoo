<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A migration that creates a table without an ownership classification fails CI (A1-10).
 *
 * Six markets share one schema, so "who owns this row" has to be answered once per table and written down -- and DECISIONS guardrail 3
 * forbids the lazy answer of a `country_id` on everything. The answer lives in `docs/gcc/table-ownership.json`; this reads the migration
 * SOURCE, not a database, so a new table is caught by the file that creates it rather than by whatever reads it wrongly six months later.
 *
 * Three ways to fail, and each is a different mistake:
 *   - a migration creates a table the register does not name: the classification was never made;
 *   - the register names a table no migration creates: a rename or a drop left the register describing a schema that is gone;
 *   - a table is classified `inherited`, the frozen bucket of the single-market platform, although it is new.
 *
 * `inherited` is the honest bucket for the 71 tables this platform had before an owner scope was a question. It is pinned by name, so it
 * can only shrink: classifying one of them properly is progress the guard notices, and adding to it is refused.
 *
 * A migration that passes a variable to `Schema::create` cannot be read statically. Such a file names its tables in the register's
 * `declares` map, and a declaration for a file that no longer needs one fails too -- an escape hatch that cannot rot into an exemption.
 */
class TableOwnershipTest extends TestCase
{
    private const REGISTER = 'docs/gcc/table-ownership.json';

    /** Every scope a table may be given. `by` is required for all but these two. */
    private const SCOPES_WITHOUT_AN_OWNER_COLUMN = ['global', 'inherited'];

    public function test_every_table_a_migration_creates_is_classified(): void
    {
        $created = array_keys($this->tablesCreatedByMigrations());
        $classified = array_keys($this->register()['tables']);

        sort($created);
        sort($classified);

        $this->assertSame(
            [],
            array_values(array_diff($created, $classified)),
            'A migration creates these tables and ' . self::REGISTER . ' does not classify them. Add an entry naming the owner scope.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($classified, $created)),
            self::REGISTER . ' classifies these tables and no migration creates them. Remove the entry, or name the migration that does.',
        );
    }

    public function test_every_classification_is_one_of_the_declared_scopes_and_names_what_carries_it(): void
    {
        $register = $this->register();
        $scopes = array_keys($register['scopes']);

        $wrong = [];
        foreach ($register['tables'] as $table => $entry) {
            $scope = $entry['scope'] ?? null;
            if (!in_array($scope, $scopes, true)) {
                $wrong[$table] = "scope '{$scope}' is not one of " . implode(', ', $scopes);
                continue;
            }
            if (!in_array($scope, self::SCOPES_WITHOUT_AN_OWNER_COLUMN, true) && trim((string) ($entry['by'] ?? '')) === '') {
                $wrong[$table] = "scope '{$scope}' needs `by`: which column, or which path through a parent, carries the owner";
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * The single-market platform's tables are frozen by name. A table classified `inherited` that is not on that list is a NEW table
     * being filed as old, which is how an unclassified table would otherwise slip through: `inherited` says nothing about ownership.
     */
    public function test_nothing_new_is_filed_as_inherited(): void
    {
        $register = $this->register();
        $frozen = $register['inherited_frozen_at'];

        $inherited = [];
        foreach ($register['tables'] as $table => $entry) {
            if (($entry['scope'] ?? null) === 'inherited') {
                $inherited[] = $table;
            }
        }
        sort($inherited);
        sort($frozen);

        $this->assertSame(
            [],
            array_values(array_diff($inherited, $frozen)),
            "These tables are classified `inherited` but are not in the frozen list: a new table must name a real owner scope.",
        );
        $this->assertSame(
            [],
            array_values(array_diff($frozen, $inherited)),
            'These tables left `inherited`. That is progress -- take them out of inherited_frozen_at so the bucket keeps shrinking.',
        );
    }

    /** A declaration exists for a migration that cannot be read statically, and for no other. */
    public function test_a_declaration_is_kept_only_while_the_migration_still_needs_one(): void
    {
        $declared = array_keys($this->register()['declares']);
        $unreadable = $this->migrationsWithAnUnreadableCreate();

        sort($declared);
        sort($unreadable);

        $this->assertSame($unreadable, $declared, 'Every migration whose Schema::create argument is not a literal or a self::CONST must be in `declares`, and no other.');
    }

    /** @return array<string, string> table => the migration that creates it */
    private function tablesCreatedByMigrations(): array
    {
        $declares = $this->register()['declares'];
        $tables = [];

        foreach ($this->migrationFiles() as $file) {
            $base = basename($file);
            $source = (string) file_get_contents($file);

            foreach ($this->createdIn($source) as $table) {
                $tables[$table] = $base;
            }

            if ($this->createsWithoutALiteral($source)) {
                foreach ($declares[$base] ?? [] as $table) {
                    $tables[$table] = $base;
                }
            }
        }

        return $tables;
    }

    /** @return list<string> */
    private function migrationsWithAnUnreadableCreate(): array
    {
        $files = [];
        foreach ($this->migrationFiles() as $file) {
            if ($this->createsWithoutALiteral((string) file_get_contents($file))) {
                $files[] = basename($file);
            }
        }

        return $files;
    }

    /**
     * The tables a migration creates, as its source states them: a quoted name, or a `self::CONST` this file assigns one to.
     *
     * @return list<string>
     */
    private function createdIn(string $source): array
    {
        $tables = [];
        foreach ($this->createArguments($source) as $argument) {
            if (preg_match("/^'([a-z0-9_]+)'$/", $argument, $match) === 1) {
                $tables[] = $match[1];
                continue;
            }
            if (preg_match('/^(?:self|static)::([A-Z_]+)$/', $argument, $match) === 1
                && preg_match("/const\s+{$match[1]}\s*=\s*'([a-z0-9_]+)'/", $source, $value) === 1) {
                $tables[] = $value[1];
            }
        }

        return $tables;
    }

    /** True when at least one `Schema::create` names its table in a way this test cannot read. */
    private function createsWithoutALiteral(string $source): bool
    {
        foreach ($this->createArguments($source) as $argument) {
            $isLiteral = preg_match("/^'[a-z0-9_]+'$/", $argument) === 1;
            $isResolvedConstant = preg_match('/^(?:self|static)::([A-Z_]+)$/', $argument, $match) === 1
                && preg_match("/const\s+{$match[1]}\s*=\s*'[a-z0-9_]+'/", $source) === 1;

            if (!$isLiteral && !$isResolvedConstant) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the first argument of every Schema::create call, as written */
    private function createArguments(string $source): array
    {
        preg_match_all('/Schema::create\(\s*([^,]+),/', $source, $matches);

        return array_map('trim', $matches[1]);
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        return glob($this->basePath() . '/database/migrations/*.php') ?: [];
    }

    /** @return array{tables: array<string, array<string, string>>, scopes: array<string, string>, declares: array<string, list<string>>, inherited_frozen_at: list<string>} */
    private function register(): array
    {
        $path = $this->basePath() . '/' . self::REGISTER;
        $this->assertFileExists($path);

        $register = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach (['tables', 'scopes', 'declares', 'inherited_frozen_at'] as $key) {
            $this->assertArrayHasKey($key, $register, self::REGISTER . " must hold a '{$key}' key");
        }

        return $register;
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }
}
