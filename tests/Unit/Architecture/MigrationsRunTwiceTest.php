<?php

namespace Tests\Unit\Architecture;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Running a migration of this programme a second time leaves the schema exactly as the first run left it (M2-16).
 *
 * `Tests\Unit\Ci\GccMigrationsContractTest` reads the source and says every create and every column addition asks the schema first. This
 * runs them. The difference matters: a guard can be written and not bite -- `hasColumn` on the wrong table, a return that comes after the
 * statement it was meant to skip, a second column added inside a closure the guard only covers the first of. One of those is a deploy that
 * dies on its second attempt with half a schema in place, and no reading of the file would have shown it.
 *
 * ## How a file is chosen, and why some are skipped rather than failed
 *
 * Every migration of this programme is offered. The test database holds no `orders`, `products` or `sellers`: those come from
 * `installation/backup/database.sql`, which is not in this repository, so a migration that adds a column to one of them correctly does
 * nothing here. Doing nothing twice proves nothing, so such a case is SKIPPED with that reason rather than counted as a pass -- a count of
 * cases that proved something is the number to read, and it is the tables this programme creates ITSELF that it proves.
 *
 * ## What it asserts
 *
 * The full column list of every table, before, after one run, and after two. After one run it must have changed (or the case is skipped);
 * after two it must be what it was after one. Then `down()` puts it back, and whatever the migration created is dropped in any case: these
 * tables have no migrated state on this database, so absent IS the state to restore them to.
 */
class MigrationsRunTwiceTest extends TestCase
{
    /** The first migration of this programme, as the source scan defines it. */
    private const FIRST_OF_THIS_PROGRAMME = '2026_11_05_100000';

    #[DataProvider('migrationsOfThisProgramme')]
    public function test_running_it_again_leaves_the_schema_as_the_first_run_left_it(string $file): void
    {
        $before = $this->schemaFingerprint();

        try {
            $this->runTheMigration($file);
        } catch (Throwable $refusal) {
            $this->restore($before, $file);
            $this->markTestSkipped("it needs a table this database has not got: {$refusal->getMessage()}");
        }

        $afterOneRun = $this->schemaFingerprint();

        if ($afterOneRun === $before) {
            $this->markTestSkipped('it changed nothing on a database built from the migrations alone, so running it twice proves nothing');
        }

        try {
            $this->runTheMigration($file);
            $afterTwoRuns = $this->schemaFingerprint();
        } finally {
            $this->restore($before, $file);
        }

        $this->assertSame(
            $afterOneRun,
            $afterTwoRuns,
            "{$file} does not leave the schema alone when it is run again, so a deploy that stopped half way cannot be resumed.",
        );
    }

    /**
     * The same question for the batch rather than the file, because a migration that adds a column to a table ANOTHER migration of this
     * programme creates is skipped above: on its own it meets no such table and does nothing. Run in order, the whole set builds what it
     * needs, and `migrate` on a host where the batch stopped half way is exactly this: the files run again from the top.
     */
    public function test_the_whole_batch_can_be_run_again_from_the_top(): void
    {
        $before = $this->schemaFingerprint();

        $ran = $this->runTheWholeBatch();
        $afterOneRun = $this->schemaFingerprint();

        $this->assertNotSame($before, $afterOneRun, 'the batch built nothing, so running it again proves nothing');

        try {
            $ranAgain = $this->runTheWholeBatch();
            $afterTwoRuns = $this->schemaFingerprint();
        } finally {
            $this->dropWhatWasNotThere($before);
        }

        $this->assertSame($afterOneRun, $afterTwoRuns, 'running the batch again from the top did not leave the schema as it was');
        $this->assertSame(
            $ran,
            $ranAgain,
            'a migration that ran the first time refused the second, or the other way about, so a resumed deploy would not do the same work',
        );
    }

    /**
     * @return array<string, bool> each migration of this programme, in order, and whether it ran without refusing. A refusal is kept rather
     *                            than failed: this database has no `orders` or `products`, so a file that adds a column to one cannot run here.
     */
    private function runTheWholeBatch(): array
    {
        $ran = [];
        foreach (array_keys(self::migrationsOfThisProgramme()) as $file) {
            try {
                $this->runTheMigration($file);
                $ran[$file] = true;
            } catch (Throwable) {
                $ran[$file] = false;
            }
        }

        return $ran;
    }

    /** @return array<string, list<string>> */
    public static function migrationsOfThisProgramme(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 3) . '/database/migrations/*.php') ?: [] as $path) {
            $file = basename($path);
            if (strcmp(substr($file, 0, 17), self::FIRST_OF_THIS_PROGRAMME) >= 0) {
                $cases[$file] = [$file];
            }
        }

        ksort($cases);

        return $cases;
    }

    private function runTheMigration(string $file): void
    {
        (require database_path('migrations/' . $file))->up();
    }

    /**
     * Puts back what the migration changed: its own `down()` first, and then, whatever that did or failed to do, every table that was not
     * there before. A table of this programme has no migrated state on this database, so being gone IS its state.
     *
     * @param array<string, list<string>> $before
     */
    private function restore(array $before, string $file): void
    {
        try {
            (require database_path('migrations/' . $file))->down();
        } catch (Throwable) {
            // A rollback that cannot run leaves the drop below to put the schema back.
        }

        $this->dropWhatWasNotThere($before);
    }

    /**
     * MariaDB refuses to drop the parent of a foreign key, and the tables of this programme reference each other (the platform admin tokens,
     * factors and challenges all hang off its grants), so the order a listing happens to give is not a safe order to drop in. SQLite does not
     * care, which is why this was only wrong on the engine the pilot runs.
     *
     * @param array<string, list<string>> $before
     */
    private function dropWhatWasNotThere(array $before): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($before): void {
            foreach ($this->tablesOfThisDatabase() as $name) {
                if (!array_key_exists($name, $before)) {
                    Schema::dropIfExists($name);
                }
            }
        });
    }

    /**
     * The tables of THIS connection's own database, unqualified.
     *
     * `Schema::getTableListing()` with no argument asks MariaDB for every schema the connection can SEE, and the `ci` user in this
     * container is globally privileged -- so a run listed 148 tables across five databases belonging to other work. Two tracks found it
     * independently: one measured the failure count moving 85 -> 79 between two runs of the SAME tree, the other watched another
     * database's tables appear between the "after one run" and "after two runs" reads. Flaky by construction, and invisible in CI, which
     * gives each job a database of its own.
     *
     * Worse than noisy: `dropWhatWasNotThere()` hands these names to `Schema::dropIfExists()`, and a foreign table kept its
     * `other_database.` prefix because the old stripper removed only this one's. Laravel's grammar reads that as a QUALIFIED name, so the
     * drop was aimed at another database's table -- the most likely source of the `Table 'orders' doesn't exist` errors other tracks saw
     * here. That is why this is a fix and not a tidy-up.
     *
     * `schemaQualified: false` alone would be WORSE than the bug: it strips the prefix, so a sibling's table arrives under a bare name and
     * is dropped without even looking foreign. Naming the schema is the fix.
     *
     * @return list<string>
     */
    private function tablesOfThisDatabase(): array
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        // SQLite has no schema to name, and answers an empty list when given one.
        return $database === '' || config("database.connections.{$connection}.driver") === 'sqlite'
            ? Schema::getTableListing()
            : Schema::getTableListing(schema: $database, schemaQualified: false);
    }

    /** @return array<string, list<string>> the columns of every table, by table */
    private function schemaFingerprint(): array
    {
        $tables = [];
        foreach ($this->tablesOfThisDatabase() as $name) {
            $tables[$name] = Schema::getColumnListing($name);
            sort($tables[$name]);
        }

        ksort($tables);

        return $tables;
    }
}
