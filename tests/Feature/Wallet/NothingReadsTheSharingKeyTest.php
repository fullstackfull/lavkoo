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
 * table name: a string constant of the file stands for its value, a string written in pieces is read whole whether the pieces are joined
 * with `.` or with `implode`, the escape sequences of a double-quoted string and of a heredoc are read as PHP reads them, and the case the
 * database ignores is ignored here too.
 *
 * TWO REVIEWERS HAVE BEATEN THIS GUARD, and each round is now a case of `waysOfNamingTheKeyThatHidBehindAnEarlierScan` or of the
 * directories and kinds of file this one reads. The first beat it eight ways on the NAME: `'sharing' . '_group'`, a constant joined to a
 * literal, `whereRaw('SHARING_GROUP = ?')` against a case-insensitive engine, a `.ts` and a `.json` file under `resources/js`, plain
 * readers in `bootstrap/`, `public/` and `scripts/`, and `artisan`, which is PHP at the root with no extension at all. The second beat it
 * sixteen ways on the POSITION: the name was read, but a name the scan could NOT read was looked for at a short list of builder calls that
 * left out `DB::select`, `DB::update`, `DB::insert`, `DB::delete`, a column list handed to `get()` or `first()`, `orWhere`, `whereColumn`,
 * `latest`, `getAttribute`, `setAttribute` and an index into what a model answers — and a hex or octal escape hid a name it would otherwise
 * have spelled. The scan now asks every position the same two questions, out of one list, which is why the list is one list.
 *
 * NAMING THE KEY AND READING IT ARE DIFFERENT FINDINGS, which is what makes the list below worth reading: a file listed because it names
 * the key is not thereby licensed to query by it. The two models name it to HIDE it and may do nothing else with it.
 *
 * A name the scan CANNOT read is not allowed to pass quietly either: a file that reaches an account table and hands a variable, a call or a
 * dynamic array key to a column position is reported, and the list below has to name it. That is how the market-account files that read a
 * column name out of `MarketBalanceKind` are on the list: a text scan cannot follow a name through a method, and a list that says so is
 * worth more than a scan that says nothing.
 *
 * WHAT THIS GUARD CANNOT SEE, said here rather than discovered by a third reviewer. A column name built by means no text scan can evaluate,
 * in a file that names neither account table nor either account model, is outside it: the unreadable-name finding is gated on the file
 * reaching an account, because without that gate every file in the repository that indexes an array by a variable would be on the list and
 * the list would mean nothing. So a view handed `$account` and an unreadable `$column` by a controller that names neither at a column
 * position is not reported here. `MarketAccountSharingKeyTest`, the `$hidden` on both models and a person reading the diff are what cover
 * that; this scan is one of the three, not all of them.
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
     * Every file allowed to name the key, what it is allowed to do with the name, and every file allowed to reach an account table with a
     * column name this scan cannot read.
     *
     * NAMING THE KEY AND READING IT ARE SEPARATE ENTRIES, which is the whole value of this list. The two models name the key and may only
     * name it: `$hidden` is the one declaration that keeps a column out of an API answer, and it is the opposite of a read. So their entry
     * is the key alone, and a method or a scope added beside that `$hidden` which asked the database for the key would carry
     * `COLUMN_READ_OR_WRITTEN` as well and fail here — a listed file is not licensed to read what it is listed for naming. Three files are
     * licensed to read it: the migration that adds it, which asks whether any row carries one before it drops the column, and the two
     * guards that assert all of this.
     *
     * The unreadable names are all one pattern: a column name taken from `MarketBalanceKind` (`amountColumn()`, `legacyColumn()`) or from a
     * loop over the columns a migration owns. A file arriving here that is not on this list has to be read by a person.
     *
     * @var array<string, list<string>>
     */
    private const MAY_NAME_THE_KEY_OR_BUILD_A_NAME = [
        'app/Models/CustomerMarketLoyaltyAccount.php' => [self::SHARING_KEY],
        'app/Models/CustomerMarketWalletAccount.php' => [self::SHARING_KEY],
        'app/Services/Wallet/CustomerMarketBalances.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'database/migrations/2026_12_05_120000_create_customer_market_balance_accounts.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        self::SHARING_KEY_MIGRATION => [PlatformWriteScanner::COLUMN_READ_OR_WRITTEN, self::SHARING_KEY],
        'tests/Feature/Wallet/MarketAccountDormancyTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountExpandMigrationTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountIsolationTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountReconcilerTest.php' => [PlatformWriteScanner::UNKNOWN_COLUMN],
        'tests/Feature/Wallet/MarketAccountSharingKeyTest.php' => [PlatformWriteScanner::COLUMN_READ_OR_WRITTEN, self::SHARING_KEY],
        'tests/Feature/Wallet/NothingReadsTheSharingKeyTest.php' => [
            PlatformWriteScanner::UNKNOWN_COLUMN,
            PlatformWriteScanner::COLUMN_READ_OR_WRITTEN,
            self::SHARING_KEY,
        ],
    ];

    /**
     * Where the application's own code lives, which is every directory of this repository that holds any: `app`, `config`, `database`,
     * `routes`, `resources`, `Modules` and `tests` were the first version's whole list, and this repository has real PHP in `bootstrap`,
     * `public` and `scripts` as well, and two files at its root. `vendor` is somebody else's code and `storage` is not code at all.
     */
    private const SOURCE_DIRECTORIES = [
        'app', 'bootstrap', 'config', 'database', 'installation', 'Modules', 'public', 'resources', 'routes', 'scripts', 'tests',
    ];

    /**
     * PHP at the repository root, which is read as well: the two front controllers come in by their extension, and `artisan` carries none
     * at all though it runs every console command. `composer.phar` is somebody else's code, like `vendor`.
     */
    private const SOURCE_SCRIPTS_AT_THE_ROOT = ['artisan'];

    /**
     * Every kind of file that could carry a column name to the database or to a screen. `php` covers Blade, whose extension is `.php`; `ts`
     * and `json` were how a reader under `resources/js` escaped the first version; `sql` is here for `installation/`, whose dump is imported
     * BEFORE the migrations run on a real installation.
     */
    private const EXTENSIONS = ['php', 'js', 'vue', 'ts', 'json', 'sql'];

    /** The dump a real installation imports before it migrates (see CLAUDE.md), which a checkout of the repository does not carry. */
    private const INSTALLATION_DUMP = 'installation/backup/database.sql';

    /**
     * The one scanned directory a checkout may contribute no file from: `installation/` is on the list for the dump above, and a checkout
     * carries only the licence text beside it. `test_the_installation_dump_is_scanned_where_a_checkout_carries_it` is what speaks for it.
     */
    private const DIRECTORY_OF_THE_DUMP = 'installation';

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
        /* Written out here rather than read from the constants the scan uses, the way the extensions below are: a guard that asks the scan
           what it reads and then agrees with the answer would pass a directory quietly dropped from the list. */
        $read = $this->filesRead();

        foreach (['index.php', 'server.php', 'artisan', 'webpack.mix.js', 'bootstrap/app.php', 'public/index.php', 'scripts/ci/leak-guard.php', 'scripts/deploy/deploy.php', 'app/Services/Wallet/CustomerMarketBalances.php', 'config/gcc.php', self::SHARING_KEY_MIGRATION, 'routes/web/routes.php'] as $file) {
            $this->assertContains($file, $read, "{$file} is not read by the scan, so a reader in it would be invisible.");
        }

        foreach (['ts', 'json', 'sql', 'vue', 'js', 'php'] as $extension) {
            $this->assertContains($extension, self::EXTENSIONS);
        }

        foreach (['app', 'bootstrap', 'config', 'database', 'installation', 'Modules', 'public', 'resources', 'routes', 'scripts', 'tests'] as $directory) {
            $this->assertContains($directory, self::SOURCE_DIRECTORIES, "{$directory} has been dropped from the directories the scan reads.");

            if ($directory === self::DIRECTORY_OF_THE_DUMP) {
                continue;
            }

            $this->assertNotEmpty(
                array_filter($read, static fn (string $file): bool => str_starts_with($file, $directory . '/')),
                "Nothing under {$directory}/ is read, so a reader there would be invisible.",
            );
        }

        $this->assertGreaterThan(
            5000,
            count(array_filter($read, static fn (string $file): bool => !str_starts_with($file, 'public/'))),
            'The scan is reading far less of the repository than it did. This counts everything BUT public/, whose size depends on whether '
            . 'the assets have been built, so the floor says nothing about a build.',
        );
        $this->assertGreaterThan(
            1000,
            count(array_filter($read, static fn (string $file): bool => str_ends_with($file, '.blade.php'))),
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
     * Every way of naming the key that a version of this guard did not see, and the ones it did, as code samples — so the day the
     * resolution stops working, this fails here and not only in a review.
     *
     * The first version was beaten eight ways on the NAME. The second was beaten sixteen ways on the POSITION: the name was read, but a
     * name it could not read was only looked for at a short list of builder calls, and an escape sequence hid a name it would otherwise
     * have spelled. Both families are below, each marked with what the scan has to answer: the key where the file spells it,
     * `COLUMN_READ_OR_WRITTEN` where the spelled name sits where a query takes the column to read or write, and `UNKNOWN_COLUMN` where the
     * name is built out of reach of a text scan.
     *
     * @return array<string, array{0: string, 1: bool, 2: list<string>}>
     */
    public static function waysOfNamingTheKeyThatHidBehindAnEarlierScan(): array
    {
        $key = self::SHARING_KEY;
        $unknown = PlatformWriteScanner::UNKNOWN_COLUMN;
        $read = PlatformWriteScanner::COLUMN_READ_OR_WRITTEN;
        $accounts = 'customer_market_wallet_accounts';
        $built = '<?php $column = $this->columnName(); ';

        return [
            // The name itself, however it is written.
            'two literals joined' => ["<?php DB::table('x')->value('sharing' . '_group');", true, [$read, $key]],
            'literal pieces joined by implode, which is the same string written another way' => ["<?php DB::table('x')->value(implode('_', ['sharing', 'group']));", true, [$read, $key]],
            'literal pieces joined by implode with no separator' => ["<?php DB::table('x')->value(implode(['sharing', '_group']));", true, [$read, $key]],
            'pieces joined by implode and kept in a variable, which is how a name is hidden in practice' => ["<?php \$column = implode('_', ['sharing','group']); \$x = \$column;", true, [$key]],
            'pieces implode cannot read are a name this scan cannot read' => ["<?php DB::table('customer_market_wallet_accounts')->value(implode('_', \$parts));", true, [$unknown]],
            'a constant of the file joined to a literal' => ["<?php class A { private const PART = 'sharing'; public function f(): string { return self::PART . '_group'; } }", true, [$key]],
            'a joined name kept in a variable' => ["<?php \$column = 'sharing' . '_group'; \$x = \$column;", true, [$key]],
            'the case a column name is written in, which the engine ignores' => ["<?php DB::table('x')->whereRaw('SHARING_GROUP IS NOT NULL');", true, [$read, $key]],
            'a quoted string' => ["<?php DB::table('x')->value('sharing_group');", true, [$read, $key]],
            'an identifier' => ['<?php $account->sharing_group;', true, [$read, $key]],
            'raw SQL in a heredoc' => ["<?php DB::statement(<<<SQL\nupdate customer_market_wallet_accounts set sharing_group = 'gulf'\nSQL);", true, [$read, $key]],
            'Blade markup, whose extension is php' => ['<div>@if($account->sharing_group)grouped@endif</div>', true, [$read, $key]],
            'a name in a comment is prose, not a reader' => ["<?php // the sharing_group column\n\$x = 1;", true, []],
            'a name in a docblock is prose too' => ["<?php /** The sharing_group this leaves room for. */\nclass A {}", true, []],
            'a TypeScript file is read as text' => ['export const column = "sharing_group";', false, [$key]],
            'a JSON file is read as text' => ['{"columns": ["market", "sharing_group"]}', false, [$key]],

            // An escape sequence spells the name without the text of the file carrying it.
            'a hex escape spells the name' => ["<?php DB::table('{$accounts}')->value(\"sharing\\x5fgroup\");", true, [$read, $key]],
            'an octal escape spells the name' => ["<?php DB::table('{$accounts}')->value(\"sharing\\137group\");", true, [$read, $key]],
            'pieces joined across an escape' => ["<?php DB::table('{$accounts}')->value('sharing' . \"\\x5fgroup\");", true, [$read, $key]],
            'an escape inside a heredoc of raw SQL' => ["<?php DB::statement(<<<SQL\nupdate {$accounts} set sharing\\x5fgroup = 'gulf'\nSQL);", true, [$read, $key]],
            'an escape in a SINGLE-quoted string is not the name, because PHP reads no escape in one' => ["<?php \$x = 'sharing\\x5fgroup';", true, []],
            'a nowdoc reads no escape either' => ["<?php \$x = <<<'SQL'\nsharing\\x5fgroup\nSQL;", true, []],

            // Naming it is not reading it: the one thing the two models are listed for.
            'hidden from a model answer, which is the opposite of a read' => ["<?php class A extends Model { protected \$hidden = ['sharing_group']; }", true, [$key]],
            'listed for mass assignment, which is a declaration and not a query' => ["<?php class A extends Model { protected \$fillable = ['market', 'sharing_group']; }", true, [$key]],
            'read off a model attribute array by the name it spells' => ["<?php class A extends Model { public function g() { return \$this->attributes['sharing_group']; } }", true, [$read, $key]],
            'a scope that groups accounts by the name it spells' => ["<?php class A extends Model { public function scopeShared(\$q) { return \$q->where('sharing_group', 'gulf'); } }", true, [$read, $key]],

            // A name this scan cannot read, at every position a query takes one.
            'a name this scan cannot read, against an account table' => ["<?php DB::table('{$accounts}')->value(\$column);", true, [$unknown]],
            'a name this scan cannot read as an array key, against an account table' => ["<?php DB::table('customer_market_loyalty_accounts')->update([\$column => 'gulf']);", true, [$unknown]],
            'a name read off a model of an account by name' => ['<?php $account = CustomerMarketWalletAccount::first(); $x = $account->{$column};', true, [$unknown]],
            'built into a raw select' => [$built . "DB::select(\"select {\$column} from {$accounts} where id = ?\", [1]);", true, [$unknown]],
            'built into a raw update' => [$built . "DB::update(\"update {$accounts} set {\$column} = ?\", ['gulf']);", true, [$unknown]],
            'built into a raw insert' => [$built . "DB::insert(\"insert into {$accounts} ({\$column}) values (?)\", ['gulf']);", true, [$unknown]],
            'built into a raw delete' => [$built . "DB::delete(\"delete from {$accounts} where {\$column} = ?\", ['gulf']);", true, [$unknown]],
            'built into the column list of get' => [$built . "DB::table('{$accounts}')->get([\$column]);", true, [$unknown]],
            'built into the column list of first' => [$built . "DB::table('{$accounts}')->first([\$column]);", true, [$unknown]],
            'built into an or-where' => [$built . "DB::table('{$accounts}')->whereNull('id')->orWhere(\$column, 'gulf');", true, [$unknown]],
            'built into a comparison of two columns' => [$built . "DB::table('{$accounts}')->whereColumn(\$column, 'market');", true, [$unknown]],
            'built into an ordering' => [$built . "DB::table('{$accounts}')->latest(\$column)->first();", true, [$unknown]],
            'built into a model attribute write' => [$built . "\$a = CustomerMarketWalletAccount::find(1); \$a->setAttribute(\$column, 'gulf'); \$a->save();", true, [$unknown]],
            'built into a model attribute read' => [$built . "\$a = CustomerMarketWalletAccount::find(1); \$x = \$a->getAttribute(\$column);", true, [$unknown]],
            'built into an index of what a model answers' => [$built . "\$a = CustomerMarketWalletAccount::find(1); \$x = \$a->getAttributes()[\$column];", true, [$unknown]],
            'built into raw ordering SQL' => [$built . "DB::table('{$accounts}')->orderByRaw(\$column)->get();", true, [$unknown]],

            // And what is none of the above.
            'a name this scan cannot read, against a table that is not an account' => ["<?php DB::table('orders')->value(\$column);", true, []],
            'an account table read by a name it spells out' => ["<?php DB::table('{$accounts}')->value('market');", true, []],
            'nothing of the sort' => ["<?php DB::table('orders')->value('id');", true, []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('waysOfNamingTheKeyThatHidBehindAnEarlierScan')]
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

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), self::EXTENSIONS, true)) {
                $files[] = $file->getPathname();
            }
        }

        foreach (self::SOURCE_SCRIPTS_AT_THE_ROOT as $script) {
            if (is_file($root . '/' . $script)) {
                $files[] = $root . '/' . $script;
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
