<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `docs/gcc/ARCHITECTURE_AS_BUILT.md` describes the schema that is really there (M3-05).
 *
 * The document exists because Discovery-01 proposed five tables, three were built under other names and two were
 * deliberately not built, and a reader of the proposal would reasonably conclude that five tables are still owed. A
 * reconciliation is only worth having while it is true, and a reconciliation that has drifted is worse than none: it is
 * the document a reader trusts.
 *
 * So four things are read out of the file and checked against the tree:
 *
 *   - every `table_name` it calls BUILT is in `docs/gcc/table-ownership.json`, which is itself enforced against the
 *     migrations by `TableOwnershipTest`;
 *   - every `table.column` it names is really added by a migration;
 *   - every `gcc.*` configuration key it names is in `config/gcc.php`;
 *   - every table it calls NOT BUILT is really absent from the register, so the day one of them is created this fails
 *     rather than the document quietly becoming wrong.
 *
 * The names are taken from the file's own backticks, so the document cannot pass by not mentioning anything: the test
 * also requires a floor on how many names it found.
 */
class ArchitectureAsBuiltTest extends TestCase
{
    private const DOCUMENT = 'docs/gcc/ARCHITECTURE_AS_BUILT.md';

    private const REGISTER = 'docs/gcc/table-ownership.json';

    /** The tables the document says were NOT built. Pinned here, so creating one of them fails this test by name. */
    private const NOT_BUILT = ['countries', 'storefronts'];

    /** Tables named in the file that are not this schema's (they are the proposal's own names). */
    private const PROPOSED_NAMES = ['customer_balance_accounts', 'staff_scopes'];

    public function test_every_table_the_document_calls_built_is_in_the_ownership_register(): void
    {
        $register = $this->register();
        $named = $this->namesIn('/`([a-z][a-z0-9_]{4,})`/');

        $tables = array_values(array_filter(
            $named,
            fn (string $name): bool => !in_array($name, [...self::NOT_BUILT, ...self::PROPOSED_NAMES], true)
                && (isset($register[$name]) || $this->looksLikeATableOfThisProgramme($name)),
        ));

        $this->assertNotEmpty($tables, 'no table name was read out of ' . self::DOCUMENT . ', so this guard proved nothing');

        $unknown = array_values(array_filter($tables, static fn (string $table): bool => !isset($register[$table])));

        $this->assertSame(
            [],
            $unknown,
            "These tables are named as built in " . self::DOCUMENT . " and are in neither the register nor the schema:\n  "
            . implode("\n  ", $unknown),
        );
    }

    public function test_every_column_the_document_names_is_added_by_a_migration(): void
    {
        $columns = $this->namesIn('/`([a-z][a-z0-9_]+)\.([a-z][a-z0-9_]+)`/', both: true);
        $this->assertGreaterThanOrEqual(10, count($columns), 'a reconciliation that names fewer than ten columns is naming almost nothing');

        $source = $this->migrationSource();
        $missing = [];
        foreach ($columns as [$table, $column]) {
            if (!str_contains($source, "'" . $column . "'")) {
                $missing[] = "{$table}.{$column}";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These columns are named in " . self::DOCUMENT . " and no migration adds them:\n  " . implode("\n  ", $missing),
        );
    }

    public function test_every_configuration_key_the_document_names_exists(): void
    {
        preg_match_all('/`gcc\.([a-z0-9_.]+)`/', $this->document(), $found);
        $keys = array_values(array_unique($found[1]));

        $this->assertNotEmpty($keys, 'the document names no gcc configuration key');

        $source = (string) file_get_contents($this->repositoryRoot() . '/config/gcc.php');
        $missing = [];
        foreach ($keys as $key) {
            $leaf = (string) array_slice(explode('.', $key), -1)[0];
            if (!str_contains($source, "'" . $leaf . "'")) {
                $missing[] = 'gcc.' . $key;
            }
        }

        $this->assertSame([], $missing, "These configuration keys are named in " . self::DOCUMENT . " and are not in config/gcc.php:\n  " . implode("\n  ", $missing));
    }

    /**
     * The half of the reconciliation that would otherwise rot silently: the document says `countries` and `storefronts`
     * were deliberately NOT built, and the day somebody creates one the document becomes a lie about the schema. This
     * fails then, and names the table.
     */
    public function test_the_tables_the_document_says_were_not_built_are_still_not_there(): void
    {
        $register = $this->register();

        $built = array_values(array_filter(self::NOT_BUILT, static fn (string $table): bool => isset($register[$table])));

        $this->assertSame(
            [],
            $built,
            'These tables exist now and ' . self::DOCUMENT . ' says they were deliberately not built: ' . implode(', ', $built)
            . '. Rewrite section 1, and say in the commit which alternative was reopened.',
        );

        foreach (self::NOT_BUILT as $table) {
            $this->assertMatchesRegularExpression(
                '/`' . $table . '`[^|]*\|\s*\*\*not built/u',
                $this->document(),
                "section 1 must still record `{$table}` as not built, in that column, or this test is guarding a sentence that moved",
            );
        }
    }

    /**
     * @return list<string>|list<array{0: string, 1: string}>
     */
    private function namesIn(string $pattern, bool $both = false): array
    {
        preg_match_all($pattern, $this->document(), $found, PREG_SET_ORDER);

        $names = [];
        foreach ($found as $match) {
            $names[] = $both ? [$match[1], $match[2]] : $match[1];
        }

        return array_values(array_unique($names, $both ? SORT_REGULAR : SORT_STRING));
    }

    /** A name with an underscore and a plural-looking end is a table of this programme rather than a word in a sentence. */
    private function looksLikeATableOfThisProgramme(string $name): bool
    {
        return str_contains($name, '_') && str_ends_with($name, 's');
    }

    /** @return array<string, array<string, string>> */
    private function register(): array
    {
        $path = $this->repositoryRoot() . '/' . self::REGISTER;
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['tables'];
    }

    private function migrationSource(): string
    {
        $source = '';
        foreach (glob($this->repositoryRoot() . '/database/migrations/*.php') ?: [] as $file) {
            $source .= file_get_contents($file);
        }

        return $source;
    }

    private function document(): string
    {
        $path = $this->repositoryRoot() . '/' . self::DOCUMENT;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
