<?php

namespace Tests\Feature\Fulfillment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PlatformWriteScanner;

/**
 * The ledger of every form of money write that the guard on users.wallet_balance and users.loyalty_point (WalletBalanceWritersTest) has EVER been shown to miss, and the one
 * test that says it still reports each of them.
 *
 * It exists because three rounds in a row closed the misses they were handed and opened a wider hole in the same change, each time by narrowing the reading that had caught the
 * other set:
 *
 *   9f6bc526 missed 26 forms of array and attribute write. a9f5ec43 closed them by following an array into the call that writes it.
 *   7465de7d missed 10 forms of raw SQL -- eight with a `where` in front of the SET clause, two behind a bare `--`. 8a0d86c8 closed them by isolating the clause that assigns,
 *            and in the same change gated the SQL side on a whitelist of receiver methods.
 *   19a8545d missed 21 forms because of that gate: `DB::select` and a PDO `exec` run arbitrary SQL and are on no such list, and an array of amounts handed on to a name the
 *            reader cannot follow was reported only when the handing could be resolved. Every one of the 21 was reported by BOTH earlier heads.
 *
 * Each round's own test file was green. What was missing was a place where the forms of the round before are still asserted, so this is that place: a form enters this ledger
 * when it is shown to have been missed and never leaves it. A round that trades one reading for another now goes red here, which is the only thing that makes the trade
 * visible -- a count of tests does not move, and a list in a docblock is a list someone has to remember.
 *
 * This asks a different question from WalletBalanceWritersTest::writesAndNonWrites(), which pins HOW MANY writes of WHAT KIND each form reports and, as importantly, every
 * form that must stay QUIET. This file pins only that the form is reported at all, because that is the property that cannot be satisfied by weakening the scanner: the
 * expected value here is "not nothing", so there is no number to adjust and no kind to rename. Deleting a case is the only way past it, and the count below makes that red too.
 *
 * Every form in it moves money. The 21 of the third round were each proved on MariaDB 10.11.14 by the verifier that found them and again here (see the run recorded on the
 * pull request): seed a `decimal(40,20)` column at 350.12345678901234567890, run the statement, read back 0.00000000000000000000. None of them is a read.
 */
final class NoRoundMayLoseABalanceWriteFormTest extends TestCase
{
    private const FIRST_ROUND = '9f6bc526';

    private const SECOND_ROUND = '7465de7d';

    private const THIRD_ROUND = '19a8545d';

    /**
     * How many cases each round put in this ledger, and how many writes they hold. Asserted, so that a case cannot be taken out of the provider to make a round green: the
     * forms of a round are closed by reading them, not by shortening the list of them.
     *
     * @var array<string, array{cases: int, writes: int}>
     */
    private const WHAT_EACH_ROUND_LOST = [
        self::FIRST_ROUND => ['cases' => 23, 'writes' => 26],
        self::SECOND_ROUND => ['cases' => 10, 'writes' => 10],
        self::THIRD_ROUND => ['cases' => 21, 'writes' => 21],
    ];

    /**
     * Every form, with the head that lost it. The sources are the forms as they were planted, not as they were paraphrased: the third round's twenty-one are the ten the
     * verifier named at the lines it planted them on, and eleven more of the same two shapes -- a row-changing statement handed to something other than the six whitelisted
     * methods, and an array of amounts whose handing the reader cannot follow.
     *
     * @return array<string, array{string, string, string}> name => [the head that lost it, the source, the column]
     */
    public static function everyFormARoundHasLost(): array
    {
        return array_merge(self::theFirstRoundsArrayAndAttributeForms(), self::theSecondRoundsRawSqlForms(), self::theThirdRoundsUngatedForms());
    }

    /**
     * 9f6bc526 read an array key as a write only where its value was not a string, and read an attribute write only in the spellings it had thought of. Closed at a9f5ec43 by
     * following the array into the call that writes it, whatever the value, and by reading the rest of the spellings.
     *
     * @return array<string, array{string, string, string}>
     */
    private static function theFirstRoundsArrayAndAttributeForms(): array
    {
        $round = self::FIRST_ROUND;

        return [
            'an update array whose value is a string, which a database writes as a number' => [$round, "DB::table('users')->update(['wallet_balance' => '0']);", 'wallet_balance'],
            'an update array whose value is an interpolated string' => [$round, "DB::table('users')->update(['loyalty_point' => \"{\$amount}\"]);", 'loyalty_point'],
            'an update array whose value is the empty string' => [$round, "DB::table('users')->update(['wallet_balance' => '']);", 'wallet_balance'],
            'an update array built in a variable whose value is a string' => [$round, "\$values = ['wallet_balance' => '0'];\nDB::table('users')->update(\$values);", 'wallet_balance'],
            'an update array built in a property whose value is a string' => [$round, "class X { private \$values = ['loyalty_point' => '0'];\npublic function z() { DB::table('users')->update(\$this->values); } }", 'loyalty_point'],
            'an upsert whose value is a string' => [$round, "DB::table('users')->upsert([['id' => 1, 'wallet_balance' => '9999']], ['id'], ['wallet_balance']);", 'wallet_balance'],
            'an updateOrInsert whose value is a string' => [$round, "DB::table('users')->updateOrInsert(['id' => 1], ['wallet_balance' => '9999']);", 'wallet_balance'],
            'a fill whose value is a string' => [$round, "\$user->fill(['wallet_balance' => '9999'])->save();", 'wallet_balance'],
            'setRawAttributes' => [$round, "\$user->setRawAttributes(['loyalty_point' => '5'], true);", 'loyalty_point'],
            'the key qualified by its table' => [$round, "DB::table('users')->join('tops', 'tops.user_id', '=', 'users.id')->update(['users.wallet_balance' => 0]);", 'wallet_balance'],
            'the key qualified by an alias' => [$round, "DB::table('users as u')->update(['u.wallet_balance' => 0]);", 'wallet_balance'],
            'increment and decrement called with named arguments, which this project asks for' => [$round, "\$user->increment(column: 'wallet_balance', amount: 5);\n\$user->decrement(column: 'wallet_balance', amount: 1);", 'wallet_balance'],
            'setAttribute called with named arguments' => [$round, "\$user->setAttribute(key: 'loyalty_point', value: 3);", 'loyalty_point'],
            'offsetSet, which is what the array access of a model calls' => [$round, "\$user->offsetSet('loyalty_point', 3);", 'loyalty_point'],
            'the array access of a model' => [$round, "\$user['wallet_balance'] = 5;", 'wallet_balance'],
            'the rarer compound assignments' => [$round, '$user->wallet_balance %= 100; $user->wallet_balance **= 2; $user->wallet_balance <<= 1;', 'wallet_balance'],
            'an assignment with a space either side of the arrow' => [$round, '$user -> wallet_balance = 5;', 'wallet_balance'],
            'an assignment with a newline after the arrow' => [$round, "\$user->\n    loyalty_point = 1;", 'loyalty_point'],
            'the column held by a property of the class that writes it' => [$round, "class X { private string \$column = 'wallet_balance';\npublic function z() { DB::table('users')->update([\$this->column => 0]); } }", 'wallet_balance'],
            'the column read out of a list a constant holds' => [$round, "class X { private const MONEY = ['wallet' => 'wallet_balance'];\npublic function z() { DB::table('users')->update([self::MONEY['wallet'] => 0]); } }", 'wallet_balance'],
            'raw SQL behind a block comment' => [$round, 'DB::statement("/* nightly reset */ update users set wallet_balance = 0");', 'wallet_balance'],
            'raw SQL behind a comment line' => [$round, 'DB::unprepared("-- reset\nupdate users set wallet_balance = 0");', 'wallet_balance'],
            'raw SQL with a semicolon inside one of its literals' => [$round, 'DB::statement("update users set remark = \'cleared; see ticket\', wallet_balance = 0");', 'wallet_balance'],
        ];
    }

    /**
     * 7465de7d cut a statement at its first `where` instead of isolating the clause that assigns, so every statement with a `where` in front of its SET clause read as no
     * write; and it asked for a space after a `--`, so a bare marker left the statement behind it unread. Closed at 8a0d86c8 and 25846921.
     *
     * @return array<string, array{string, string, string}>
     */
    private static function theSecondRoundsRawSqlForms(): array
    {
        $round = self::SECOND_ROUND;

        return [
            'raw SQL whose set clause follows the where of a join subquery' => [$round, 'DB::statement("update users u join (select id from tops where paid = 1) t on t.id = u.id set u.wallet_balance = 0");', 'wallet_balance'],
            'raw SQL whose set clause follows a literal that spells where' => [$round, 'DB::statement("update users set remark = \'where it went\', wallet_balance = 0, loyalty_point = 1");', 'wallet_balance'],
            'raw SQL whose set clause holds a subselect with a where' => [$round, 'DB::statement("update users set is_active = (select 1 from users where id > 0 limit 1), wallet_balance = 0");', 'wallet_balance'],
            'raw SQL whose set clause counts rows in a subselect with a where' => [$round, 'DB::statement("update users set loyalty_point = (select count(*) from logs where ok = 1), wallet_balance = 0");', 'wallet_balance'],
            'raw SQL whose join condition holds a subquery with a where' => [$round, 'DB::statement("UPDATE users u JOIN tops t ON t.id = u.id AND t.id IN (SELECT id FROM z WHERE ok = 1) SET u.wallet_balance = 0");', 'wallet_balance'],
            'raw SQL that upserts from a select with a where' => [$round, 'DB::statement("insert into users (id) select id from z where ok = 1 on duplicate key update wallet_balance = 0");', 'wallet_balance'],
            'raw SQL in a nowdoc whose set clause follows the where of a join subquery' => [$round, "DB::unprepared(<<<'SQL'\nupdate users u join (select id from tops where paid = 1) t on t.id = u.id set u.wallet_balance = 0\nSQL);", 'wallet_balance'],
            'raw SQL whose set clause reads a note out of a subselect with a where' => [$round, 'DB::statement("update users set remark = (select note from audit_notes where audit_notes.user_id = users.id), wallet_balance = 0, loyalty_point = 0");', 'wallet_balance'],
            'raw SQL behind a comment line that is only its marker' => [$round, 'DB::statement("--\nupdate users set wallet_balance = 0, loyalty_point = 0");', 'wallet_balance'],
            'raw SQL behind a comment line that is only its marker and a carriage return' => [$round, 'DB::statement("--\r\nupdate users set wallet_balance = 0, loyalty_point = 0");', 'wallet_balance'],
        ];
    }

    /**
     * 19a8545d gated the SQL side on a whitelist of six receiver methods and the array side on a handing it could resolve. The two shapes below walk past both gates, and both
     * earlier heads reported every one of them, because both read a SQL string wherever it stood and an array of amounts wherever it stood.
     *
     * The ten the verifier named are marked. `DB::select` executes an UPDATE on MySQL and MariaDB and returns no rows; so does a PDO `exec`, `selectOne`, `scalar`, `cursor`, a
     * prepared statement executed, and the method of any other file the string is handed to. That is why the reading is no longer gated on a name.
     *
     * @return array<string, array{string, string, string}>
     */
    private static function theThirdRoundsUngatedForms(): array
    {
        $round = self::THIRD_ROUND;

        return [
            // PL1 and PL2, planted in the branch's own listed migration 2025_07_13_021823_modify_wallet_balance_column_in_users_table.php.
            'DB::select running an update, in a migration' => [$round, 'DB::select("update users set wallet_balance = 0");', 'wallet_balance'],
            'a PDO exec running an update, in a migration' => [$round, 'DB::connection()->getPdo()->exec("update users set wallet_balance = 0");', 'wallet_balance'],
            // PL8, planted in database/seeds/DatabaseSeeder.php, and PL11, planted in app/Http/Controllers/Web/UserWalletController.php.
            'DB::select running an update, in a seeder' => [$round, 'DB::select("update users set wallet_balance = 0 where id > 0");', 'wallet_balance'],
            'DB::select running an update, in a controller' => [$round, 'DB::select("update users set wallet_balance = wallet_balance - 5 where id = 1");', 'wallet_balance'],
            // PL10, planted inside the listed writer app/Utils/CustomerManager.php, where being on the writers list granted it no permission.
            'a PDO exec inside a listed writer' => [$round, "\$pdo = DB::getPdo();\n\$pdo->exec(\"update users set wallet_balance = 0\");", 'wallet_balance'],
            // PL6: the string reaches the call through a second variable, so the one hop the gate followed was not enough.
            'a two-hop SQL string' => [$round, "\$part = \"update users set wallet_balance = 0\";\n\$sql = \$part;\nDB::statement(\$sql);", 'wallet_balance'],
            'DB::select running an update of the points' => [$round, 'DB::select("update users set loyalty_point = 0");', 'loyalty_point'],
            'DB::selectOne running an update' => [$round, 'DB::selectOne("update users set wallet_balance = 0");', 'wallet_balance'],
            'DB::scalar running an update' => [$round, 'DB::scalar("update users set loyalty_point = 0");', 'loyalty_point'],
            'DB::cursor running an update' => [$round, 'DB::cursor("update users set wallet_balance = 0");', 'wallet_balance'],
            'a prepared statement executed' => [$round, "\$st = DB::getPdo()->prepare(\"update users set wallet_balance = 0\");\n\$st->execute();", 'wallet_balance'],
            'SQL handed to a method of another file, which runs it there' => [$round, '$this->runner->run("update users set wallet_balance = 0");', 'wallet_balance'],
            'SQL handed to a plain function' => [$round, 'run_sql("update users set wallet_balance = 0");', 'wallet_balance'],
            'an upsert run through DB::select' => [$round, 'DB::select("insert into users (id) values (1) on duplicate key update wallet_balance = 0");', 'wallet_balance'],
            'an insert run through DB::select' => [$round, 'DB::select("insert into users (id, wallet_balance) values (1, 0)");', 'wallet_balance'],
            // PL4, PL5, PL7 and PL12: an array of amounts whose handing the reader cannot follow. Each was reported by both earlier heads because the value is no cast.
            'a ternary-valued array' => [$round, "\$values = ['wallet_balance' => \$refund ? 0 : \$credit];\n\$this->writer->persist(\$values);", 'wallet_balance'],
            'an array merged into a variable with array_merge' => [$round, "\$values = array_merge(\$base, ['wallet_balance' => 0]);\n\$this->writer->persist(\$values);", 'wallet_balance'],
            'rows appended with the empty subscript and then upserted' => [$round, "\$rows[] = ['id' => 1, 'wallet_balance' => 0];\nDB::table('users')->upsert(\$rows, ['id']);", 'wallet_balance'],
            'an arm of a match' => [$round, "\$values = match (\$type) { 'credit' => ['wallet_balance' => \$amount], default => [] };\n\$this->writer->persist(\$values);", 'wallet_balance'],
            'an array that reaches its write through a second variable' => [$round, "\$first = ['wallet_balance' => 0];\n\$second = \$first;\nDB::table('users')->update(\$second);", 'wallet_balance'],
            'an array handed to a method of another file' => [$round, "\$this->repository->store(['loyalty_point' => \$points]);", 'loyalty_point'],
        ];
    }

    #[DataProvider('everyFormARoundHasLost')]
    public function test_a_form_a_round_once_lost_is_reported_again(string $round, string $form, string $column): void
    {
        $this->assertNotSame(
            [],
            PlatformWriteScanner::columnWritesIn("<?php\n" . $form . "\n", $column),
            "This form of writing users.{$column} was missed at {$round} and closed afterwards. It moves money and is reported again only if the scanner still reads it: "
            . 'whatever this round changed, it has narrowed the reading that caught this one. Widen it back rather than taking this case out.',
        );
    }

    public function test_the_ledger_still_holds_every_form_of_every_round(): void
    {
        $byRound = [];
        foreach (self::everyFormARoundHasLost() as [$round, $form, $column]) {
            $byRound[$round]['cases'] = ($byRound[$round]['cases'] ?? 0) + 1;
            $byRound[$round]['writes'] = ($byRound[$round]['writes'] ?? 0) + count(PlatformWriteScanner::columnWritesIn("<?php\n" . $form . "\n", $column));
        }

        $this->assertSame(
            self::WHAT_EACH_ROUND_LOST,
            $byRound,
            'A round lost this many forms of money write, holding this many writes between them. A case removed from the provider, or a form that reports a different number of '
            . 'writes than the round that lost it was handed, changes these numbers: say in the constant what changed and why, so that the next round reads it.',
        );
    }
}
