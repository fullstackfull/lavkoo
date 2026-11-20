<?php

namespace Tests\Feature\Wallet;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

/**
 * E-02's fourth criterion, the half with the teeth: no file of the application names the sharing key, so turning sharing on is a code
 * change a person writes and another reviews. It reads the source and nothing else — there is no schema here, which is why it is its own
 * class rather than fourteen more runs of a market-account setUp, the same way the platform's other source guards are their own classes.
 *
 * It reads the code through `Tests\Support\PlatformWriteScanner`, the platform's own scanner, which resolves a name the way it resolves a
 * table name: a string constant of the file stands for its value, a string written in pieces is read whole, and the case the database
 * ignores is ignored here too. The first version of this guard was beaten eight ways by a reviewer, every one of them from inside the tree
 * it scans, and each is now a case of `waysOfNamingTheKeyThatHidBehindTheFirstScan` or of the directories and kinds of file this one reads:
 * `'sharing' . '_group'`, a constant joined to a literal, `whereRaw('SHARING_GROUP = ?')` against a case-insensitive engine, a `.ts` and a
 * `.json` file under `resources/js`, and plain readers in `bootstrap/`, `public/` and `scripts/`.
 *
 * A name the scan CANNOT read is not allowed to pass quietly either: a file that reaches an account table and hands a variable, a call or a
 * dynamic array key to a column position is reported, and the list below has to name it. That is how the four market-account files that
 * read a column name out of `MarketBalanceKind` are on the list: a text scan cannot follow a name through a method, and a list that says so
 * is worth more than a scan that says nothing.
 */
class NothingReadsTheSharingKeyTest extends TestCase
{
    private const SHARING_KEY = 'sharing_group';

    private const SHARING_KEY_MIGRATION = 'database/migrations/2026_12_05_140000_add_sharing_key_to_market_balance_accounts.php';

    /** The two account tables and their models: what a column name has to reach for an unreadable name to matter. */
    private const ACCOUNTS = [
        'customer_market_wallet_accounts',
        'customer_market_loyalty_accounts',
        'CustomerMarketWalletAccount',
        'CustomerMarketLoyaltyAccount',
    ];

    /**
     * Every file allowed to name the key, and every file allowed to reach an account table with a column name this scan cannot read.
     *
     * The key itself is named by the migration that adds it, by the two guards that assert it, and by the two models that HIDE it — hiding
     * is the one declaration that keeps a column out of an API answer, and it is the opposite of a read. Nothing else may name it. The
     * unreadable names are all one pattern: a column name taken from `MarketBalanceKind` (`amountColumn()`, `legacyColumn()`) or from a
     * loop over the columns a migration owns. A file arriving here that is not on this list has to be read by a person.
     *
     * @var array<string, list<string>>
     */
    private const MAY_NAME_THE_KEY_OR_BUILD_A_NAME = [
        'app/Models/CustomerMarketLoyaltyAccount.php' => [self::SHARING_KEY],
        'app/Models/CustomerMarketWalletAccount.php' => [self::SHARING_KEY],
        'app/Services/Wallet/CustomerMarketBalances.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'database/migrations/2026_12_05_120000_create_customer_market_balance_accounts.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        self::SHARING_KEY_MIGRATION => [self::SHARING_KEY],
        'tests/Feature/Wallet/MarketAccountDormancyTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountExpandMigrationTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountIsolationTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountReconcilerTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountSharingKeyTest.php' => [self::SHARING_KEY],
        'tests/Feature/Wallet/NothingReadsTheSharingKeyTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN, self::SHARING_KEY],
    ];

    /**
     * Where the application's own code lives, which is every directory of this repository that holds any: `app`, `config`, `database`,
     * `routes`, `resources`, `Modules` and `tests` were the first version's whole list, and this repository has real PHP in `bootstrap`,
     * `public` and `scripts` as well, and two files at its root. `vendor` is somebody else's code and `storage` is not code at all.
     */
    private const SOURCE_DIRECTORIES = [
        'app', 'bootstrap', 'config', 'database', 'installation', 'Modules', 'public', 'resources', 'routes', 'scripts', 'tests',
    ];

    private const SOURCE_FILES_AT_THE_ROOT = ['index.php', 'server.php'];

    /**
     * Every kind of file that could carry a column name to the database or to a screen. `php` covers Blade, whose extension is `.php`; `ts`
     * and `json` were how a reader under `resources/js` escaped the first version; `sql` is here for `installation/`, whose dump is imported
     * BEFORE the migrations run on a real installation.
     */
    private const EXTENSIONS = ['php', 'js', 'vue', 'ts', 'json', 'sql'];

    /** The dump a real installation imports before it migrates (see CLAUDE.md), which a checkout of the repository does not carry. */
    private const INSTALLATION_DUMP = 'installation/backup/database.sql';

    /** @var array<string, list<string>>|null */
    private static ?array $findings = null;

    /** @var list<string>|null */
    private static ?array $filesRead = null;

    public function test_no_file_of_the_application_names_the_key(): void
    {
        $found = $this->findings();
        $listed = self::MAY_NAME_THE_KEY_OR_BUILD_A_NAME;
        ksort($listed);

        $this->assertSame(
            $listed,
            $found,
            'A file names the sharing key, or reaches an account table with a column name this scan cannot read, and is not on the list — '
            . 'or a listed file has stopped doing it, which leaves the guard asserting a column nobody adds. Turning sharing on is meant to '
            . 'be a code change a person writes and another reviews, and this list is where that review is recorded. Found: '
            . json_encode($found, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The scan is worth exactly what it reads. The first version read `php`, `js` and `vue` under seven directories, and a reader in a
     * `.ts` file, a `.json` file, `bootstrap/`, `public/`, `scripts/` or `index.php` was invisible to it. These are the files this one has
     * to be reading for the list above to mean anything.
     */
    public function test_the_scan_reads_every_place_this_repository_keeps_code(): void
    {
        $read = $this->filesRead();

        foreach ([...self::SOURCE_FILES_AT_THE_ROOT, 'bootstrap/app.php', 'public/index.php', 'scripts/ci/leak-guard.php', 'scripts/deploy/deploy.php', 'app/Services/Wallet/CustomerMarketBalances.php', 'config/gcc.php', self::SHARING_KEY_MIGRATION, 'routes/web/routes.php'] as $file) {
            $this->assertContains($file, $read, "{$file} is not read by the scan, so a reader in it would be invisible.");
        }

        foreach (['ts', 'json', 'sql', 'vue', 'js', 'php'] as $extension) {
            $this->assertContains($extension, self::EXTENSIONS);
        }

        $this->assertGreaterThan(6000, count($read), 'The scan is reading far less of the repository than it did.');
        $this->assertTrue(
            count(array_filter($read, static fn (string $file): bool => str_ends_with($file, '.blade.php'))) > 1000,
            'The views are read too: a column name in Blade markup reaches a screen.',
        );
    }

    /**
     * The dump a real installation imports before it migrates is not in the repository, so this scan cannot speak for it. It says so rather
     * than passing: a default or a trigger introduced there would be invisible both to the scan and to the "nullable with no default" test,
     * which reads `COLUMN_DEFAULT` and never a trigger. Where a checkout does carry the dump — a deployment tarball, or a CI job that
     * fetches it — it is scanned like any other file, `sql` being one of the extensions above.
     */
    public function test_the_installation_dump_is_scanned_where_a_checkout_carries_it(): void
    {
        if (!is_file(base_path(self::INSTALLATION_DUMP))) {
            $this->markTestSkipped(
                self::INSTALLATION_DUMP . ' is not in this checkout, so this run cannot say whether the dump a real installation imports '
                . 'names the sharing key. Run this where the dump is present before trusting the guard for an installed platform.',
            );
        }

        $this->assertContains(self::INSTALLATION_DUMP, $this->filesRead());
        $this->assertArrayNotHasKey(self::INSTALLATION_DUMP, $this->findings());
    }

    /**
     * Every way of naming the key that the first version of this guard did not see, and the ones it did, as code samples — so the day the
     * resolution stops working, this fails here and not only in a review.
     *
     * @return array<string, array{0: string, 1: bool, 2: list<string>}>
     */
    public static function waysOfNamingTheKeyThatHidBehindTheFirstScan(): array
    {
        $key = self::SHARING_KEY;
        $unknown = PlatformWriteScanner::UNKNOWN_COLUMN;

        return [
            'two literals joined' => ["<?php DB::table('x')->value('sharing' . '_group');", true, [$key]],
            'a constant of the file joined to a literal' => ["<?php class A { private const PART = 'sharing'; public function f(): string { return self::PART . '_group'; } }", true, [$key]],
            'a joined name kept in a variable' => ["<?php \$column = 'sharing' . '_group'; \$x = \$column;", true, [$key]],
            'the case a column name is written in, which the engine ignores' => ["<?php DB::table('x')->whereRaw('SHARING_GROUP IS NOT NULL');", true, [$key]],
            'a quoted string' => ["<?php DB::table('x')->value('sharing_group');", true, [$key]],
            'an identifier' => ['<?php $account->sharing_group;', true, [$key]],
            'raw SQL in a heredoc' => ["<?php DB::statement(<<<SQL\nupdate customer_market_wallet_accounts set sharing_group = 'gulf'\nSQL);", true, [$key]],
            'Blade markup, whose extension is php' => ['<div>@if($account->sharing_group)grouped@endif</div>', true, [$key]],
            'a name in a comment is prose, not a reader' => ["<?php // the sharing_group column\n\$x = 1;", true, []],
            'a name in a docblock is prose too' => ["<?php /** The sharing_group this leaves room for. */\nclass A {}", true, []],
            'a TypeScript file is read as text' => ['export const column = "sharing_group";', false, [$key]],
            'a JSON file is read as text' => ['{"columns": ["market", "sharing_group"]}', false, [$key]],
            'a name this scan cannot read, against an account table' => ["<?php DB::table('customer_market_wallet_accounts')->value(\$column);", true, [$unknown]],
            'a name this scan cannot read as an array key, against an account table' => ["<?php DB::table('customer_market_loyalty_accounts')->update([\$column => 'gulf']);", true, [$unknown]],
            'a name read off a model of an account by name' => ['<?php $account = CustomerMarketWalletAccount::first(); $x = $account->{$column};', true, [$unknown]],
            'a name this scan cannot read, against a table that is not an account' => ["<?php DB::table('orders')->value(\$column);", true, []],
            'an account table read by a name it spells out' => ["<?php DB::table('customer_market_wallet_accounts')->value('market');", true, []],
            'nothing of the sort' => ["<?php DB::table('orders')->value('id');", true, []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('waysOfNamingTheKeyThatHidBehindTheFirstScan')]
    public function test_the_scan_sees_the_key_however_it_is_written(string $code, bool $asPhp, array $expected): void
    {
        $this->assertSame($expected, $this->namesTheKeyIn(code: $code, asPhp: $asPhp), 'Not read as it should be: ' . str_replace("\n", ' / ', $code));
    }

    /** @return array<string, list<string>> every file of the application that names the key or builds a name, by path, sorted */
    private function findings(): array
    {
        $this->readTheSource();

        return self::$findings;
    }

    /** @return list<string> every file the scan read */
    private function filesRead(): array
    {
        $this->readTheSource();

        return self::$filesRead;
    }

    private function readTheSource(): void
    {
        if (self::$findings !== null) {
            return;
        }

        $root = base_path();
        $findings = [];
        $read = [];

        foreach ($this->sourceFiles() as $file) {
            $path = str_replace($root . '/', '', $file);
            $read[] = $path;
            $named = $this->namesTheKeyIn(code: (string) file_get_contents($file), asPhp: pathinfo($file, PATHINFO_EXTENSION) === 'php');

            if ($named !== []) {
                $findings[$path] = $named;
            }
        }

        ksort($findings);
        sort($read);

        self::$findings = $findings;
        self::$filesRead = $read;
    }

    /** @return list<string> every file of the application's own code, wherever it lives */
    private function sourceFiles(): array
    {
        $root = base_path();
        $files = [];

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }

            $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

            foreach ($tree as $file) {
                if ($file->isFile() && in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (self::SOURCE_FILES_AT_THE_ROOT as $file) {
            if (is_file($root . '/' . $file)) {
                $files[] = $root . '/' . $file;
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function namesTheKeyIn(string $code, bool $asPhp): array
    {
        return (new PlatformWriteScanner([]))->namesColumnIn(code: $code, column: self::SHARING_KEY, asPhp: $asPhp, tables: self::ACCOUNTS);
    }
}
