<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;

/**
 * A2-14 of the master plan: existing accounts, ids and history are preserved. DECISIONS guardrail 2 states
 * the rule this checks — new behaviour is additive, and the Saudi experience is unchanged until a storefront
 * is switched on.
 *
 * The five tables the criterion names hold the live platform's customers and their history: `users`, `carts`,
 * `orders`, `order_details`, `shipping_addresses`. `guest_users` is guarded with them, because a guest's order
 * is history too. A migration of this chain that dropped, renamed or retyped a column of one of them, or
 * deleted rows from one, would take something away from a customer who has been shopping on the Saudi
 * platform for years, and no later switch could give it back.
 *
 * Until now the check was a list: the migration names of pull requests 83 and 75 read by hand for the data
 * pack. A list read once cannot see the fifteenth migration.
 *
 * ## What is compared, and what is allowed
 *
 * `tests/Fixtures/migrations-on-main.txt` is the migration file names on origin/main, sorted, one per line.
 * Everything in `database/migrations` that is not in it is this chain's, and each of those is read.
 *
 * Only the body of `up()` is read. `down()` is a rollback and is SUPPOSED to drop what `up()` added — the
 * storefront-country migration on this very tree drops its column and its index in `down()`, as it should.
 *
 * In `up()`, against one of the guarded tables, these are refused outright: dropColumn, dropIfExists, drop,
 * renameColumn, rename, truncate, a `->change()` that retypes an existing column, and a write that deletes or
 * rewrites rows. Adding a column IS allowed, on one condition the test enforces: it has to be nullable or
 * carry a default, so that every row already in the table stays exactly as it was. That is what "additive"
 * means here, and it is what lets GCC-05 add `storefront_country`, `currency_code` and `currency_rate` to
 * `orders` on another branch of this chain without touching a single existing order.
 *
 * ## What is true on this tree today
 *
 * Fourteen migrations are this chain's, and not one of them names any of the six guarded tables. The only one
 * that touches a table that existed on main at all is the storefront country on `products`, which is a
 * catalogue table, not a customer's history. That is recorded as its own test, because it is the criterion's
 * own sentence and it is worth knowing the day it stops being true.
 */
class CustomerTablesAreNotRewrittenTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../../database/migrations';

    private const BASELINE = __DIR__ . '/../../Fixtures/migrations-on-main.txt';

    private const MIGRATIONS_ON_MAIN = 336;

    /** The tables that hold a customer and their history. The criterion names the first five; a guest's order is history too. */
    private const GUARDED = ['users', 'carts', 'orders', 'order_details', 'shipping_addresses', 'guest_users'];

    /** What may never be done to one of those tables in an up(). Each is a regular expression over the up() body. */
    private const REFUSED = [
        'a column is dropped' => '/->\s*dropColumn\s*\(/',
        'a column or table is renamed' => '/->\s*renameColumn\s*\(|Schema::\s*rename\s*\(/',
        'the table is dropped' => '/Schema::\s*drop(IfExists)?\s*\(/',
        'the table is emptied' => '/->\s*truncate\s*\(|->\s*delete\s*\(\s*\)/',
        'an existing column is retyped' => '/->\s*change\s*\(\s*\)/',
    ];

    /** @return array<string, string> the up() body of each migration this chain adds, by file name */
    private function upBodiesOfTheChain(): array
    {
        $onMain = array_flip($this->migrationsOnMain());
        $bodies = [];

        foreach ((array) glob(self::MIGRATIONS . '/*.php') as $path) {
            $name = basename((string) $path);

            if (isset($onMain[$name])) {
                continue;
            }

            $bodies[$name] = $this->upBodyOf(source: (string) file_get_contents((string) $path));
        }

        return $bodies;
    }

    /** The text of up(), which is everything from its signature to the signature of down() or the end of the file. */
    private function upBodyOf(string $source): string
    {
        if (!preg_match('/function\s+up\s*\(/i', $source, $start, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $from = $start[0][1];
        $body = substr($source, $from);

        return preg_split('/function\s+down\s*\(/i', $body)[0] ?: '';
    }

    /** @return list<string> */
    private function migrationsOnMain(): array
    {
        $lines = explode("\n", (string) file_get_contents(self::BASELINE));
        $lines = array_filter(array_map(fn (string $line): string => trim($line), $lines), fn (string $line): bool => $line !== '');

        return array_values($lines);
    }

    /** @return list<string> the guarded tables this up() body names */
    private function guardedTablesIn(string $upBody): array
    {
        return array_values(array_filter(
            self::GUARDED,
            fn (string $table): bool => (bool) preg_match('/[\'"]' . preg_quote($table, '/') . '[\'"]/', $upBody),
        ));
    }

    public function test_the_baseline_fixture_is_the_migration_set_it_says_it_is(): void
    {
        $onMain = $this->migrationsOnMain();

        $this->assertFileExists(self::BASELINE);
        $this->assertCount(self::MIGRATIONS_ON_MAIN, $onMain, 'the fixture has been edited: rebuild it from main in a commit of its own instead');
        $this->assertSame($onMain, array_values(array_unique($onMain)), 'the fixture lists a migration twice');
    }

    public function test_this_tree_really_does_add_migrations_of_its_own(): void
    {
        $this->assertNotEmpty($this->upBodiesOfTheChain(), 'nothing to check means the fixture matches the whole directory, so this test proves nothing');
    }

    public function test_no_migration_of_this_chain_rewrites_a_customer_table(): void
    {
        $offences = [];

        foreach ($this->upBodiesOfTheChain() as $name => $upBody) {
            $tables = $this->guardedTablesIn(upBody: $upBody);

            if ($tables === []) {
                continue;
            }

            foreach (self::REFUSED as $what => $pattern) {
                if (preg_match($pattern, $upBody)) {
                    $offences[] = $name . ': ' . $what . ' (' . implode(', ', $tables) . ')';
                }
            }
        }

        $this->assertSame([], $offences, 'a migration of this chain takes something away from a customer table in its up(); down() is not read, because a rollback is supposed to undo');
    }

    public function test_a_column_this_chain_adds_to_a_customer_table_leaves_every_existing_row_as_it_was(): void
    {
        $offences = [];

        foreach ($this->upBodiesOfTheChain() as $name => $upBody) {
            if ($this->guardedTablesIn(upBody: $upBody) === []) {
                continue;
            }

            foreach ($this->columnsAddedIn(upBody: $upBody) as $statement) {
                if (!preg_match('/->\s*nullable\s*\(|->\s*default\s*\(/', $statement)) {
                    $offences[] = $name . ': ' . trim($statement);
                }
            }
        }

        $this->assertSame([], $offences, 'a column added to a customer table is neither nullable nor defaulted, so every row already in the table has to be written to');
    }

    public function test_no_migration_of_this_chain_names_a_customer_table_at_all(): void
    {
        $touching = [];

        foreach ($this->upBodiesOfTheChain() as $name => $upBody) {
            $tables = $this->guardedTablesIn(upBody: $upBody);

            if ($tables !== []) {
                $touching[] = $name . ' (' . implode(', ', $tables) . ')';
            }
        }

        $this->assertSame(
            [],
            $touching,
            'this is the criterion\'s own sentence and it has stopped being true. That is not automatically wrong — an additive, nullable column is allowed and the tests above check it — but it is a fact worth recording, so update this test and the tracker together rather than deleting it.',
        );
    }

    /** @return list<string> one entry per column-adding statement of an up() body */
    private function columnsAddedIn(string $upBody): array
    {
        $types = 'string|char|text|longText|integer|bigInteger|unsignedBigInteger|unsignedInteger|tinyInteger|smallInteger|boolean|decimal|double|float|date|dateTime|timestamp|json|uuid|foreignId|enum';

        preg_match_all('/\$table\s*->\s*(?:' . $types . ')\s*\([^;]*;/', $upBody, $found);

        return $found[0];
    }
}
