<?php

namespace Tests\Feature\Fulfillment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * E-03 of the master plan: a customer's balances are changed by their writers and by nothing else. The wallet balance (users.wallet_balance) has four writers and the
 * loyalty points (users.loyalty_point) two, every one of them taking the row lock and writing the ledger row with the balance. Anything else that assigns, updates,
 * increments or rewrites either column skips the lock or the ledger and cannot be reconciled (wallet:reconcile), so this test fails when a new one appears. It reads
 * the source and needs no database.
 *
 * A new writer is added to the list below with the reason, in the same change that adds it. Until the balances are per market (GCC-15) a writer also has to take the
 * market it writes in, so the list is also the list of the places that will.
 */
class WalletBalanceWritersTest extends TestCase
{
    /** @var array<string, int> file => number of writes of users.wallet_balance */
    private const WALLET_WRITERS = [
        'app/Repositories/WalletTransactionRepository.php' => 1,
        'app/Traits/CustomerTrait.php' => 1,
        'app/Utils/CustomerManager.php' => 1,
        'app/Utils/OrderManager.php' => 1,
    ];

    /** @var array<string, int> file => number of writes of users.loyalty_point */
    private const LOYALTY_WRITERS = [
        'app/Repositories/LoyaltyPointTransactionRepository.php' => 1,
        'app/Utils/CustomerManager.php' => 1,
    ];

    /** @var list<string> where the application's code lives */
    private const ROOTS = ['app', 'Modules', 'routes', 'config'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every write of one balance column in a piece of PHP source, with the comments left out: an assignment to the attribute, an array key that is given a value that
     * is not a string (a cast or a name map is one; an update or a fill is not), an increment or a decrement, setAttribute, and raw SQL that sets the column.
     *
     * @return list<string>
     */
    public static function writesOf(string $column, string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $name = preg_quote($column, '/');
        $patterns = [
            'assignment' => '/->' . $name . '\s*(?:[-+*\/.]|\?\?)?=(?![=>])/',
            'array key given a value' => '/[\'"]' . $name . '[\'"]\s*=>\s*(?![\s\'"])/',
            'increment or decrement' => '/(?:increment|decrement)(?:Quietly)?\(\s*[\'"]' . $name . '[\'"]/',
            'setAttribute' => '/setAttribute\(\s*[\'"]' . $name . '[\'"]/',
            'raw SQL set' => '/\bset\b[^\'";]*\b' . $name . '\s*=/i',
        ];

        $writes = [];
        foreach ($patterns as $kind => $pattern) {
            $count = preg_match_all($pattern, $code);
            if ($count) {
                $writes = array_merge($writes, array_fill(0, $count, $kind));
            }
        }

        return $writes;
    }

    /** @return array<string, list<string>> relative path => the writes of the column found in it */
    private function writersOf(string $column): array
    {
        $found = [];

        foreach (self::ROOTS as $root) {
            $directory = self::root() . '/' . $root;
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $writes = self::writesOf($column, (string) file_get_contents($file->getPathname()));
                if ($writes !== []) {
                    $found[substr($file->getPathname(), strlen(self::root()) + 1)] = $writes;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** @param array<string, int> $allowed */
    private function assertOnlyTheseWrite(string $column, array $allowed): void
    {
        $actual = array_map('count', $this->writersOf($column));

        $this->assertSame(
            $allowed,
            $actual,
            "users.{$column} is written by code that is not on the list (or a listed writer changed the number of its writes). Go through one of the writers, which take the row "
            . 'lock and write the ledger row, or add this one to the list with the reason.',
        );
    }

    public function test_the_wallet_balance_is_written_by_its_four_writers_and_nothing_else(): void
    {
        $this->assertOnlyTheseWrite(column: 'wallet_balance', allowed: self::WALLET_WRITERS);
    }

    public function test_the_loyalty_points_are_written_by_their_two_writers_and_nothing_else(): void
    {
        $this->assertOnlyTheseWrite(column: 'loyalty_point', allowed: self::LOYALTY_WRITERS);
    }

    public function test_the_scan_reads_the_source_it_is_meant_to_so_a_wrong_root_cannot_make_it_pass(): void
    {
        $scanned = 0;
        foreach (self::ROOTS as $root) {
            if (is_dir(self::root() . '/' . $root)) {
                $scanned += iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/' . $root, RecursiveDirectoryIterator::SKIP_DOTS)));
            }
        }

        $this->assertGreaterThan(500, $scanned);
        foreach ([...array_keys(self::WALLET_WRITERS), ...array_keys(self::LOYALTY_WRITERS)] as $writer) {
            $this->assertFileExists(self::root() . '/' . $writer);
        }
    }

    /** @return array<string, array{string, string, int}> a source line, the column and how many writes of it the scan must find */
    public static function writesAndNonWrites(): array
    {
        return [
            'assignment' => ['$user->wallet_balance = $current + $credit;', 'wallet_balance', 1],
            'compound assignment' => ['$user->wallet_balance += 5; $user->wallet_balance -= 2; $user->wallet_balance ??= 0;', 'wallet_balance', 3],
            'update array' => ["User::where('id', 1)->update(['wallet_balance' => \$new]);", 'wallet_balance', 1],
            'fill array' => ["\$user->forceFill(['loyalty_point' => \$points])->save();", 'loyalty_point', 1],
            'raw value in an array' => ["->update(['wallet_balance' => DB::raw('wallet_balance + 5')]);", 'wallet_balance', 1],
            'increment' => ["\$user->increment('wallet_balance', 5); \$user->decrementQuietly('wallet_balance', 1);", 'wallet_balance', 2],
            'setAttribute' => ["\$user->setAttribute('loyalty_point', 3);", 'loyalty_point', 1],
            'raw SQL' => ['DB::statement("update users set wallet_balance = wallet_balance - ? where id = ?");', 'wallet_balance', 1],
            'a read' => ['$balance = $user->wallet_balance; if ($user->wallet_balance == 0) { echo $user->wallet_balance; }', 'wallet_balance', 0],
            'a comparison' => ['if ($user->loyalty_point === 5 || $user->loyalty_point >= 3) {}', 'loyalty_point', 0],
            'a cast' => ["protected \$casts = ['wallet_balance' => 'float', 'loyalty_point' => 'float'];", 'wallet_balance', 0],
            'a name map' => ["['loyalty_point' => 'loyalty_point']", 'loyalty_point', 0],
            'a comment' => ["// \$user->wallet_balance = 5;\n/* ->update(['wallet_balance' => 1]) */\n# \$user->wallet_balance -= 1;", 'wallet_balance', 0],
            'the other column' => ['$user->loyalty_point = 5;', 'wallet_balance', 0],
            'a longer name' => ['$user->total_wallet_balance = 5; $x->wallet_balance_before = 1;', 'wallet_balance', 0],
        ];
    }

    #[DataProvider('writesAndNonWrites')]
    public function test_the_scan_finds_every_kind_of_write_and_ignores_reads_casts_and_comments(string $line, string $column, int $expected): void
    {
        $this->assertCount($expected, self::writesOf($column, "<?php\n" . $line . "\n"));
    }
}
