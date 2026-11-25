<?php

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * No architectural change is made by direct SQL (M2-V2; M1-18's fourth rule): the schema is changed by a migration file and by nothing
 * else, and application code does not execute a statement the schema builder did not write.
 *
 * The rule was true of every change this programme read and NOTHING kept it true. `tests/Unit/Ci/GccMigrationsContractTest` holds the half
 * that is inside the migrations -- a raw statement on the way up must be listed with what makes it re-runnable, and nothing may drop,
 * rename or `change()` -- and this is the other half: the code that runs on every request, every console run and every install.
 *
 * **What it looks for, and why that and not more.** It finds the EXECUTORS -- `DB::statement()`, `DB::unprepared()` and the same two on a
 * connection -- because a statement that is executed is the only one that changes anything. It deliberately does NOT look for `DB::raw()`,
 * `selectRaw()` or `whereRaw()`: a raw expression inside a SELECT is a way of writing a read, not an architectural change, and dozens of
 * report screens are built on them (the day-grouped money reports among them). A guard that refused those would have an allow-list longer
 * than the rule and would say nothing.
 *
 * Comments are stripped before anything is judged, so what a file SAYS about the SQL it does not run does not excuse it -- the lesson the
 * secret scanner learned on its own comment.
 *
 * Every file that executes one is listed below with the reason, and an entry that has gone stale fails too, so a file that stops running
 * raw SQL cannot leave a permission behind for the next person who edits it.
 */
class NoDirectSqlOutsideMigrationsTest extends TestCase
{
    /** The trees that run on a server: the application, the modules, the route files and the configuration. Migrations are the other half. */
    private const SCANNED = ['app', 'Modules', 'routes', 'config'];

    /**
     * A call that hands a statement to the database. `$connection->statement(...)` is matched by the receiver's NAME, because a value
     * object with a `statement()` method of its own (the staging sanitiser builds the text of one) executes nothing.
     */
    private const EXECUTORS = '/(?:DB|Schema)::(?:statement|unprepared)\s*\(|\$[A-Za-z_>\-]*(?:connection|Connection|db|pdo|Pdo)[A-Za-z_]*(?:->|::)(?:statement|unprepared)\s*\(/';

    /**
     * The files that execute a statement of their own, and why each is allowed to. Each reason says what the statement is FOR and why a
     * migration could not be it -- a new entry without one of those two halves is a new defect with a note attached.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // The installer and the updater of the platform itself, which run the dumps shipped beside them. These predate this programme and
        // are how a fresh installation gets the tables whose CREATE lives in a .sql file rather than in a migration (`addon_settings`,
        // `payment_requests`); a migration cannot be the installer, because there is no database to migrate yet.
        'app/Http/Controllers/InstallController.php' => 'the installer runs the platform\'s own .sql dumps on a database that has no migrations table yet',
        'app/Traits/UpdateClass.php' => 'the updater creates addon_settings and payment_requests from the .sql files shipped beside it, each behind a hasTable guard',
        'app/Console/Commands/DatabaseRefresh.php' => 'a development command that imports the installation dump into a throwaway database',

        // Statements that are not DDL at all: they set something on the SESSION, which a migration cannot do for a request that has not
        // happened yet.
        'app/Services/Fulfillment/Readiness/Compatibility/ReadOnlyProbe.php' => 'sets the session read-only around a compatibility probe, so a probe cannot write even by mistake, and sets it back',
        'app/Services/Fulfillment/Support/LegacyColumnWidener.php' => 'sets the session lock_wait_timeout around the widening of a legacy column, and widens it with ALGORITHM=INPLACE, LOCK=NONE -- which the schema builder has no way to write (GccMigrationsContractTest holds the same three ALTERs under RAW_DDL)',

        // A switch an operator turns on, which adds or drops an index rather than a column.
        'app/Services/Catalog/ProductCodeIndex.php' => 'adds and drops the country-scoped code index when the operator turns GCC-14b on or off, including the generated column MySQL needs for it: it is an administrative action on a live table, not a release',

        // **Found by this guard, and a defect rather than a decision.** Both report controllers REMOVE `ONLY_FULL_GROUP_BY` from the
        // session so that a query grouping by `DATE_FORMAT(created_at, '%M')` while selecting `YEAR(created_at)` will run. That is the
        // same shape `config/database.php`'s `'strict' => false` hides for the other thirty-odd day-grouped report queries, except that
        // here a request does it to the server explicitly. It is listed so the guard is honest about what is there, NOT because it is
        // right: the repair is the query, and it changes figures an operator reads, so it belongs to the money-report lane and not here.
        'Modules/TaxModule/app/Http/Controllers/Admin/Reports/VendorTaxReportController.php' => 'DEFECT, recorded not accepted: removes ONLY_FULL_GROUP_BY from the session so a day-grouped report query runs (see coverage-gaps; the repair is the query)',
        'Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php' => 'DEFECT, recorded not accepted: the same statement on the seller API\'s copy of that report',
    ];

    /** @return list<string> every scanned file, by its path relative to the repository */
    private function scannedFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $paths = [];
        foreach (self::SCANNED as $tree) {
            if (!is_dir($root . '/' . $tree)) {
                continue;
            }
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $tree, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }
        sort($paths);

        return $paths;
    }

    public function test_every_file_that_runs_a_statement_of_its_own_is_listed_with_the_reason(): void
    {
        $unlisted = [];
        foreach ($this->scannedFiles() as $path) {
            $statements = $this->executorsIn(path: $path);
            if ($statements !== [] && !array_key_exists($path, self::ALLOWED)) {
                $unlisted[] = $path . "\n    " . implode("\n    ", $statements);
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            "A file hands a statement to the database itself:\n  " . implode("\n  ", $unlisted)
            . "\n\nChange the schema in a migration, read with the query builder, or list the file in ALLOWED with what the statement is for and why a migration cannot be it.",
        );
    }

    public function test_no_entry_of_the_allow_list_has_gone_stale(): void
    {
        $withoutOne = array_values(array_filter(array_keys(self::ALLOWED), fn (string $path): bool => $this->executorsIn(path: $path) === []));

        $this->assertSame([], $withoutOne, 'a file is listed as running a statement of its own and runs none: take the entry out, or the next edit of it inherits a permission nobody meant to give: ' . implode(', ', $withoutOne));
    }

    public function test_no_sql_file_sits_in_a_tree_that_runs_on_the_server(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (self::SCANNED as $tree) {
            foreach (glob($root . '/' . $tree . '/{,*/,*/*/,*/*/*/,*/*/*/*/}*.sql', GLOB_BRACE) ?: [] as $file) {
                $found[] = str_replace($root . '/', '', $file);
            }
        }

        $this->assertSame([], $found, 'a .sql file under ' . implode(', ', self::SCANNED) . " is a schema change nothing reviews as a migration:\n  " . implode("\n  ", $found));
    }

    public function test_the_guard_reads_the_trees_it_claims_to(): void
    {
        $files = $this->scannedFiles();

        // A floor, not a figure: it fails if a tree stops being read at all, and does not have to be edited every time a file is added.
        $this->assertGreaterThan(2000, count($files), 'the scan found almost nothing, so it is reading the wrong place');
        foreach (self::SCANNED as $tree) {
            $this->assertNotSame([], array_filter($files, static fn (string $path): bool => str_starts_with($path, $tree . '/')), $tree . ' was not read');
        }
        foreach (array_keys(self::ALLOWED) as $allowed) {
            $this->assertContains($allowed, $files, $allowed . ' is allowed and is not a file the scan reads');
        }
    }

    /**
     * @return list<string> the lines that hand a statement to the database, with their numbers, comments stripped first
     */
    private function executorsIn(string $path): array
    {
        $code = $this->withoutComments(contents: (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
        $found = [];
        foreach (explode("\n", $code) as $number => $line) {
            if (preg_match(self::EXECUTORS, $line) === 1) {
                $found[] = ($number + 1) . ': ' . trim($line);
            }
        }

        return $found;
    }

    /** What a file SAYS about the SQL it does not run does not excuse it, so the comments go before anything is judged. */
    private function withoutComments(string $contents): string
    {
        $code = '';
        foreach (token_get_all($contents) as $token) {
            if (!is_array($token)) {
                $code .= $token;

                continue;
            }
            // A comment becomes the newlines it held, so the line numbers of what is left are the file's own.
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1];
        }

        return $code;
    }
}
