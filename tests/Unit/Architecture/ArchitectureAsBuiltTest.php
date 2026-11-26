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
     * The storage leg (report 02's open criterion): the document covers all three kinds of storage this platform has, and every file and
     * class it cites for them is in the tree.
     *
     * The three kinds are pinned HERE and not read out of the document, for the reason the threat model's guard pins its nine classes in
     * its own test: a document that renamed a kind to one that happens to be covered would otherwise pass. The citations are pinned the same
     * way, because the failure this is for is the one a reader cannot see -- a path that was true when it was written and has since moved.
     */
    public function test_the_storage_leg_covers_the_three_kinds_and_every_path_it_cites_is_in_the_tree(): void
    {
        $document = $this->document();

        foreach ([
            'the database as the source' => 'The database is the source',
            'the uploaded files' => 'Uploaded files live on four disks',
            'the storage of the clients' => 'Client-local storage is not in this repository',
            'what the leg does not cover' => 'What this leg does NOT cover',
        ] as $kind => $sentence) {
            $this->assertStringContainsString($sentence, $document, 'the storage leg must cover ' . $kind);
        }

        foreach ([
            'config/filesystems.php' => 'config/filesystems.php',
            'App\Traits\StorageTrait' => 'app/Traits/StorageTrait.php',
            'DigitalProductFileService::PRIVATE_DISK' => 'app/Services/DigitalProductFileService.php',
            'VaryByStorefront' => 'app/Http/Middleware/VaryByStorefront.php',
            'ResolveStorefront::varyByHeader()' => 'app/Http/Middleware/ResolveStorefront.php',
            'ResolveStorefrontMiddlewareTest' => 'tests/Feature/Storefront/ResolveStorefrontMiddlewareTest.php',
            'docs/gcc/audits/2026-11-16-upload-surface-audit.md' => 'docs/gcc/audits/2026-11-16-upload-surface-audit.md',
        ] as $citation => $path) {
            $this->assertStringContainsString($citation, $document, 'the storage leg must cite ' . $citation);
            $this->assertFileExists($this->repositoryRoot() . '/' . $path, $citation . ' is cited by ' . self::DOCUMENT . ' and is not in the tree');
        }

        // The four disks are the ones the application really declares. A disk named here that the configuration does not have would make the
        // map describe a platform that does not exist.
        $filesystems = (string) file_get_contents($this->repositoryRoot() . '/config/filesystems.php');
        foreach (['local', 'public', 'digital_private', 's3'] as $disk) {
            $this->assertStringContainsString("'" . $disk . "' => [", $filesystems, 'the storage leg names the disk ' . $disk);
            $this->assertStringContainsString('`' . $disk . '`', $document, 'the disk ' . $disk . ' is declared and the storage leg does not name it');
        }
    }

    /**
     * Section 4 is the "what is still not built" list, and it had drifted: it filed the search index with the analytics
     * rollup and the monitoring module as having no market dimension, and concluded that "a figure read from them is a
     * platform total". That was true of two of the three. A **search** is narrowed to the storefront at read time —
     * `ProductSearchService::baseQuery` passes the query through `StorefrontCatalog::restrictProductIds` BEFORE the
     * 500-id cap — so a search result is not a platform total, and a reader acting on that sentence would have gone
     * looking for a market column the feature does not need.
     *
     * Section 5's citations have been pinned since it was written; section 4's never were, which is how one line of it
     * went stale for a day. So the same treatment: the claim that needs the mechanism names the mechanism, and the
     * mechanism has to be in the tree AND still do what the sentence says. An "absent" list is the part of a
     * reconciliation most worth guarding, because every entry on it is an invitation to build something.
     */
    public function test_the_not_built_list_does_not_file_the_search_index_among_the_things_with_no_market(): void
    {
        $document = $this->document();

        foreach ([
            'tests/Feature/Queue/EveryScheduledTaskDeclaresItsMarketTest.php' => 'tests/Feature/Queue/EveryScheduledTaskDeclaresItsMarketTest.php',
            'tests/Feature/Queue/EveryQueuedClassDeclaresItsMarketTest.php' => 'tests/Feature/Queue/EveryQueuedClassDeclaresItsMarketTest.php',
            'StorefrontSearchIndexTest' => 'tests/Feature/Storefront/StorefrontSearchIndexTest.php',
            'ProductSearchService::baseQuery' => 'app/Services/Search/ProductSearchService.php',
            'VisitorContext::country' => 'app/Services/Analytics/VisitorContext.php',
        ] as $citation => $path) {
            $this->assertStringContainsString($citation, $document, 'the not-built list must cite ' . $citation);
            $this->assertFileExists($this->repositoryRoot() . '/' . $path, $citation . ' is cited by ' . self::DOCUMENT . ' and is not in the tree');
        }

        // The sentence the correction turns on, read out of the service rather than out of the document: the narrowing
        // has to be in the method the document names, and it has to come before the cap it says it comes before.
        $search = (string) file_get_contents($this->repositoryRoot() . '/app/Services/Search/ProductSearchService.php');
        $this->assertStringContainsString(
            'StorefrontCatalog::restrictProductIds(query: DB::table(\'product_search_index\')',
            $search,
            'section 4 says a search is narrowed to the storefront before its cap; put the line back or rewrite that paragraph',
        );

        // And the other direction, so this is a guard on the claim rather than on a phrase: the index itself must still
        // carry no market column. If one is ever added, the paragraph's reasoning changes and it has to be re-read.
        $this->assertStringNotContainsString(
            'product_search_index',
            $this->columnAddingMigrationsFor(column: 'storefront_country'),
            'the search index has gained a market column: section 4 explains why it needs none, so rewrite it',
        );
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

    /** The source of every migration whose file name says it adds this column, which is where a market column arrives. */
    private function columnAddingMigrationsFor(string $column): string
    {
        $source = '';

        foreach (glob($this->repositoryRoot() . '/database/migrations/*' . $column . '*.php') ?: [] as $file) {
            $source .= file_get_contents($file);
        }

        return $source;
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
