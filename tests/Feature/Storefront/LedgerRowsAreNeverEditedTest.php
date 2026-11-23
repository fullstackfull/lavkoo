<?php

namespace Tests\Feature\Storefront;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\LedgerEditScanner;

/**
 * E-08.0 of the master plan: a customer's wallet and loyalty ledgers (`wallet_transactions`, `loyalty_point_transactions`) are appended to and read, never edited. A correction is a new row, and a
 * balance that can be checked against its ledger is only worth anything while no row of the ledger is rewritten or removed. This test reads the source of app/, Modules/ and routes/ and fails when a
 * statement updates or deletes a ledger row in any of the ways listed by waysToEditALedgerRow(), each of which is proved on a fixture below. It needs no database.
 *
 * THE RULE IS NOW WHOLE. It was not: the two ledger repositories (`WalletTransactionRepository`, `LoyaltyPointTransactionRepository`) carried the generic `update()` and `delete()` of the repository contract --
 * four statements that updated and deleted rows of their ledger through the model each holds in a property, the way the scan calls INJECTED_MODEL. Nothing called them, but they were there, so the sentence "no
 * application code updates or deletes a ledger row" was false of main and E-08.0 could not be read as met. What stood here instead was a pin of the finding itself, by file and text, so that a fifth could not be
 * added unnoticed, and the instruction that when the four were removed the pin had done its work and INJECTED_MODEL should join waysToEditALedgerRow().
 *
 * The four are gone. The append-only lane removed `update()` and `delete()` from both repositories AND from both interfaces, and no caller is left anywhere in app/ or Modules/ (a ledger correction is a new row,
 * which tests/Unit/Customer/LedgerRowsAreAppendOnlyTest pins). So that pin and its list are deleted, as it said to, and INJECTED_MODEL is one of the ways below: every way the scan knows is now forbidden outright
 * rather than eight forbidden and one merely counted.
 *
 * What the scan cannot see is listed in the docblock of LedgerEditScanner. The files outside the three roots (resources/views, config, database, tests) are not read.
 */
class LedgerRowsAreNeverEditedTest extends TestCase
{
    private const ROOTS = ['app', 'Modules', 'routes'];

    private const TABLES = ['wallet_transactions', 'loyalty_point_transactions'];

    private const MODELS = ['WalletTransaction', 'LoyaltyPointTransaction'];

    private const REPOSITORIES = ['WalletTransactionRepository', 'WalletTransactionRepositoryInterface', 'LoyaltyPointTransactionRepository', 'LoyaltyPointTransactionRepositoryInterface'];

    /** @var list<string> the five files that hold the six writers of ledger rows: four of the wallet ledger, two of the loyalty ledger, one file writing both */
    private const WRITERS = [
        'app/Repositories/WalletTransactionRepository.php',
        'app/Repositories/LoyaltyPointTransactionRepository.php',
        'app/Traits/CustomerTrait.php',
        'app/Utils/CustomerManager.php',
        'app/Utils/OrderManager.php',
    ];

    /** @var list<string> the files that read the ledger for the customer, by user_id */
    private const READERS = [
        'app/Http/Controllers/RestAPI/v1/UserWalletController.php',
        'app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php',
    ];

    /** @var list<array{file: string, kind: string, line: int, statement: string}>|null */
    private static ?array $editsInTheTree = null;

    private static int $filesRead = 0;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> the PHP files of the three roots, relative to the repository */
    private static function phpFiles(): array
    {
        $files = [];
        foreach (self::ROOTS as $root) {
            if (!is_dir(self::root() . '/' . $root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/' . $root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen(self::root()) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $relations
     * @param array<string, string> $properties
     * @param array<string, string> $constants
     */
    private static function scanner(array $relations = [], array $properties = [], array $constants = []): LedgerEditScanner
    {
        return new LedgerEditScanner(tables: self::TABLES, models: self::MODELS, repositories: self::REPOSITORIES, relations: $relations, properties: $properties, constants: $constants);
    }

    /** The relations of a User model that has them, the repository property a trait declares for the classes that use it, and the constants a class of tables declares. */
    private static function fixtureScanner(): LedgerEditScanner
    {
        return self::scanner(
            relations: ['walletTransactions', 'loyaltyPointTransactions'],
            properties: ['loyaltyPointTransactionRepo' => LedgerEditScanner::REPOSITORY],
            constants: ['WALLET' => 'wallet_transactions', 'LOYALTY' => 'loyalty_point_transactions'],
        );
    }

    /** @return list<array{file: string, kind: string, line: int, statement: string}> */
    private static function editsInTheTree(): array
    {
        if (self::$editsInTheTree !== null) {
            return self::$editsInTheTree;
        }

        $files = self::phpFiles();
        $sources = array_combine($files, array_map(static fn (string $file): string => (string) file_get_contents(self::root() . '/' . $file), $files));

        $probe = self::scanner();
        $naming = array_values(array_filter($sources, static fn (string $code): bool => $probe->mentionsTheLedger(code: $code)));
        $relations = array_values(array_unique(array_merge(...array_map(static fn (string $code): array => LedgerEditScanner::relationsTo(models: self::MODELS, code: $code), $naming))));
        $properties = array_merge(...array_map(static fn (string $code): array => $probe->propertiesDeclaredIn(code: $code), $naming));
        $constants = array_merge(...array_map(static fn (string $code): array => $probe->constantsDeclaredIn(code: $code), $naming));
        $scanner = self::scanner(relations: $relations, properties: $properties, constants: $constants);

        self::$filesRead = count($files);
        self::$editsInTheTree = [];
        foreach ($sources as $file => $code) {
            if (!$scanner->mentionsTheLedger(code: $code)) {
                continue;
            }

            foreach ($scanner->editsIn(code: $code) as $edit) {
                self::$editsInTheTree[] = ['file' => $file] + $edit;
            }
        }

        return self::$editsInTheTree;
    }

    /** @return array<string, array{string}> every way the scan knows, INJECTED_MODEL among them now that the four statements it used to find are gone (see the class comment) */
    public static function waysToEditALedgerRow(): array
    {
        $kinds = [
            LedgerEditScanner::MODEL_CLASS, LedgerEditScanner::QUERY_BUILDER, LedgerEditScanner::RAW_SQL, LedgerEditScanner::SCHEMA,
            LedgerEditScanner::RELATION, LedgerEditScanner::HELD_ROW, LedgerEditScanner::PARAMETER_MODEL, LedgerEditScanner::REPOSITORY,
            LedgerEditScanner::INJECTED_MODEL,
        ];

        return array_combine($kinds, array_map(static fn (string $kind): array => [$kind], $kinds));
    }

    #[DataProvider('waysToEditALedgerRow')]
    public function test_no_code_updates_or_deletes_a_ledger_row_this_way(string $kind): void
    {
        $found = array_values(array_filter(self::editsInTheTree(), static fn (array $edit): bool => $edit['kind'] === $kind));

        $this->assertSame(
            [],
            array_map(static fn (array $edit): string => $edit['file'] . ':' . $edit['line'] . '  ' . $edit['statement'], $found),
            "A statement edits a row of the wallet or loyalty ledger through {$kind}. A correction is a new row: add one with the ledger's writers instead of rewriting or removing a row.",
        );
    }

    public function test_the_scan_reads_the_code_that_writes_and_reads_the_ledger_so_a_wrong_root_cannot_make_it_pass(): void
    {
        self::editsInTheTree();
        $this->assertGreaterThan(1000, self::$filesRead);

        $scanner = self::scanner();
        foreach ([...self::WRITERS, ...self::READERS] as $file) {
            $this->assertFileExists(self::root() . '/' . $file);
            $this->assertTrue($scanner->mentionsTheLedger(code: (string) file_get_contents(self::root() . '/' . $file)), "{$file} no longer names a ledger table, model or repository: the scan would be reading nothing of it.");
        }
    }

    /** @return array<string, array{string, list<string>}> a piece of code and the kinds of edit the scan must report in it, in order */
    public static function editsAndTheirKinds(): array
    {
        $model = LedgerEditScanner::MODEL_CLASS;
        $builder = LedgerEditScanner::QUERY_BUILDER;
        $sql = LedgerEditScanner::RAW_SQL;
        $schema = LedgerEditScanner::SCHEMA;
        $relation = LedgerEditScanner::RELATION;
        $held = LedgerEditScanner::HELD_ROW;
        $injected = LedgerEditScanner::INJECTED_MODEL;
        $parameter = LedgerEditScanner::PARAMETER_MODEL;
        $repository = LedgerEditScanner::REPOSITORY;

        return [
            'a model class: where and update' => ["WalletTransaction::where('id', 1)->update(['credit' => 0]);", [$model]],
            'a model class: where and delete' => ["LoyaltyPointTransaction::where('user_id', \$id)->delete();", [$model]],
            'a model class: destroy' => ['WalletTransaction::destroy([1, 2]);', [$model]],
            'a model class: truncate' => ['LoyaltyPointTransaction::truncate();', [$model]],
            'a model class: forceDelete after a scope' => ["WalletTransaction::withTrashed()->where('id', 1)->forceDelete();", [$model]],
            'a model class: find and delete' => ['WalletTransaction::findOrFail($id)->delete();', [$model]],
            'a model class: updateOrCreate updates a row that exists' => ["WalletTransaction::updateOrCreate(['transaction_id' => \$uuid], ['credit' => 5]);", [$model]],
            'a model class: increment' => ["LoyaltyPointTransaction::where('user_id', 1)->increment('balance', 5);", [$model]],
            'a model class: fully qualified' => ["\\App\\Models\\WalletTransaction::where('id', 1)->update(['debit' => 0]);", [$model]],
            'a model class: under an alias of the import' => ["use App\\Models\\WalletTransaction as Ledger;\nLedger::where('id', 1)->delete();", [$model]],
            'a model class: a chain over several lines with a closure in it' => ["WalletTransaction::where(function (\$query) {\n    \$query->where('a', 1)->orWhere('b', 2);\n})\n    ->update(['balance' => 0]);", [$model]],
            'a model class: inside a transaction closure' => ["DB::transaction(function () {\n    WalletTransaction::where('id', 1)->delete();\n});", [$model]],
            'a model class: from the container' => ["app(WalletTransaction::class)->where('id', 1)->update(['credit' => 0]);", [$model]],
            'a model class: from the container with make' => ["App::make(WalletTransaction::class)->where('id', 1)->delete();", [$model]],
            'a model class: from the container through the application property' => ["\$this->app->make(LoyaltyPointTransaction::class)->where('id', 1)->update(['credit' => 0]);", [$model]],
            'a model class: from the container through app()' => ["app()->make(WalletTransaction::class)->where('id', 1)->delete();", [$model]],
            'a model class: from a new instance' => ["(new WalletTransaction())->where('id', 1)->delete();", [$model]],
            'a model class: each row deleted by the higher order proxy' => ["WalletTransaction::where('user_id', 1)->get()->each->delete();", [$model]],
            'a query builder: update' => ["DB::table('wallet_transactions')->where('id', 1)->update(['credit' => 0]);", [$builder]],
            'a query builder: delete' => ["DB::table('loyalty_point_transactions')->where('user_id', 1)->delete();", [$builder]],
            'a query builder: an alias' => ["DB::table('wallet_transactions as w')->where('w.id', 1)->delete();", [$builder]],
            'a query builder: double quotes' => ['DB::table("wallet_transactions")->delete();', [$builder]],
            'a query builder: a named connection' => ["DB::connection('mysql')->table('wallet_transactions')->truncate();", [$builder]],
            'a query builder: updateOrInsert' => ["DB::table('wallet_transactions')->updateOrInsert(['transaction_id' => \$id], ['credit' => 1]);", [$builder]],
            'a query builder: upsert' => ["DB::table('wallet_transactions')->upsert(\$rows, ['transaction_id'], ['credit']);", [$builder]],
            'a query builder: decrement' => ["DB::table('wallet_transactions')->where('id', 1)->decrement('balance', 2);", [$builder]],
            'a query builder: an update that joins the ledger' => ["DB::table('users')->join('wallet_transactions', 'users.id', '=', 'wallet_transactions.user_id')->update(['wallet_transactions.balance' => 0]);", [$builder]],
            'a query builder: from' => ["\$this->connection->query()->from('wallet_transactions')->where('id', 1)->delete();", [$builder]],
            'a query builder: a table named by a constant of the class' => ["class Purge\n{\n    private const LEDGER = 'wallet_transactions';\n\n    public function run()\n    {\n        DB::table(self::LEDGER)->delete();\n    }\n}", [$builder]],
            'a query builder: a table named by a constant that another file declares' => ["DB::table(LedgerTables::WALLET)->where('id', 1)->update(['credit' => 0]);", [$builder]],
            'a query builder: kept in a variable and edited on a later statement' => ["\$query = DB::table('wallet_transactions')->where('id', 1);\n\$query->delete();", [$held]],
            'raw SQL: update' => ["DB::statement('UPDATE wallet_transactions SET credit = 0 WHERE id = 1');", [$sql]],
            'raw SQL: delete' => ["DB::delete('delete from loyalty_point_transactions where id = ?', [1]);", [$sql]],
            'raw SQL: truncate' => ["DB::unprepared('TRUNCATE TABLE wallet_transactions');", [$sql]],
            'raw SQL: quoted names' => ['DB::statement("update `wallet_transactions` set balance = 0");', [$sql]],
            'raw SQL: a schema in front of the name' => ["DB::statement('DELETE FROM shop.wallet_transactions');", [$sql]],
            'raw SQL: replace into' => ["DB::statement('REPLACE INTO wallet_transactions (id, credit) VALUES (1, 2)');", [$sql]],
            'raw SQL: an upsert' => ["DB::statement('INSERT INTO wallet_transactions (id, credit) VALUES (1, 2) ON DUPLICATE KEY UPDATE credit = 2');", [$sql]],
            'raw SQL: an update that joins the ledger' => ["DB::statement('UPDATE users u JOIN wallet_transactions w ON w.user_id = u.id SET w.balance = 0');", [$sql]],
            'raw SQL: a multi-table delete by alias' => ["DB::statement('DELETE wt FROM wallet_transactions wt WHERE wt.id = 1');", [$sql]],
            'raw SQL: drop table' => ["DB::statement('DROP TABLE IF EXISTS wallet_transactions');", [$sql]],
            'raw SQL: literals joined across a dot' => ["DB::statement('UPDATE ' . 'wallet_transactions SET credit = 0');", [$sql]],
            'raw SQL: a heredoc' => ["DB::statement(<<<SQL\n    DELETE FROM wallet_transactions WHERE id = 1\n    SQL);", [$sql]],
            'the schema builder: dropIfExists' => ["Schema::dropIfExists('wallet_transactions');", [$schema]],
            'the schema builder: drop' => ["Schema::drop('loyalty_point_transactions');", [$schema]],
            'the schema builder: a table named by a constant that another file declares' => ['Schema::dropIfExists(LedgerTables::LOYALTY);', [$schema]],
            'the schema builder: rename' => ["Schema::rename('wallet_transactions', 'wallet_transactions_old');", [$schema]],
            'a relation: delete' => ['$user->walletTransactions()->delete();', [$relation]],
            'a relation: where and update' => ["\$user->walletTransactions()->where('id', 1)->update(['credit' => 0]);", [$relation]],
            'a relation: each row deleted through the property' => ['$user->loyaltyPointTransactions->each->delete();', [$relation]],
            'a held row: update' => ["\$row = WalletTransaction::find(\$id);\n\$row->update(['credit' => 0]);", [$held]],
            'a held row: first and delete' => ["\$row = WalletTransaction::where('id', 1)->first();\n\$row->delete();", [$held]],
            'a held row: an attribute set and saved' => ["\$row = WalletTransaction::find(\$id);\n\$row->credit = 0;\n\$row->save();", [$held]],
            'a held row: locked, then incremented' => ["\$row = WalletTransaction::lockForUpdate()->find(\$id);\n\$row->increment('balance', 5);", [$held]],
            'a held row: a query kept in a variable' => ["\$query = WalletTransaction::where('id', 1);\n\$query->delete();", [$held]],
            'a held row: in a loop over the rows' => ["foreach (WalletTransaction::where('user_id', 1)->get() as \$row) {\n    \$row->delete();\n}", [$held]],
            'a held row: in a loop over rows kept in a variable' => ["\$rows = LoyaltyPointTransaction::where('user_id', 1)->get();\nforeach (\$rows as \$key => \$row) {\n    \$row->forceDelete();\n}", [$held]],
            'a held row: the parameter of each' => ["WalletTransaction::where('user_id', 1)->get()->each(function (\$row) {\n    \$row->delete();\n});", [$held]],
            'a held row: the parameter of an arrow function' => ["WalletTransaction::where('user_id', 1)->get()->each(fn (\$row) => \$row->update(['credit' => 0]));", [$held]],
            'a held row: a query that is assigned from itself' => ["\$query = WalletTransaction::where('user_id', 1);\n\$query = \$query->where('id', 1);\n\$query->delete();", [$held]],
            'a held row: a model made with new, then used as a query' => ["\$transaction = new WalletTransaction();\n\$transaction->where('id', 1)->delete();", [$held]],
            'a held row: a model taken from the container, then used as a query' => ["\$transaction = app(WalletTransaction::class);\n\$transaction->where('id', 1)->update(['credit' => 0]);", [$held]],
            'a held row: a model made with make, then used as a query' => ["\$transaction = App::make(LoyaltyPointTransaction::class);\n\$transaction->where('id', 1)->delete();", [$held]],
            'a held row: rows kept in a variable, each deleted by the higher order proxy' => ["\$rows = WalletTransaction::where('user_id', 1)->get();\n\$rows->each->delete();", [$held]],
            'a held row: rows kept in a variable, the first one deleted' => ["\$rows = WalletTransaction::where('user_id', 1)->get();\n\$rows->first()->delete();", [$held]],
            'a held row: rows kept in a variable, the parameter of each' => ["\$rows = LoyaltyPointTransaction::where('user_id', 1)->get();\n\$rows->each(function (\$row) {\n    \$row->update(['credit' => 0]);\n});", [$held]],
            'a held row: a row fetched with getFirstWhere, set and saved' => [
                "class Controller\n{\n    public function __construct(private readonly WalletTransactionRepositoryInterface \$walletTransactionRepo)\n    {\n    }\n\n    public function fix(\$id)\n    {\n        \$transaction = \$this->walletTransactionRepo->getFirstWhere(params: ['id' => \$id]);\n        \$transaction->credit = 0;\n        \$transaction->save();\n    }\n}",
                [$held],
            ],
            'an injected model: the shape of the two ledger repositories' => [
                "class Repository\n{\n    public function __construct(private readonly WalletTransaction \$walletTransaction)\n    {\n    }\n\n    public function update(string \$id, array \$data): bool\n    {\n        return \$this->walletTransaction->where('id', \$id)->update(\$data);\n    }\n\n    public function delete(array \$params): bool\n    {\n        \$this->walletTransaction->where(\$params)->delete();\n\n        return true;\n    }\n}",
                [$injected, $injected],
            ],
            'an injected model: a find and an update' => ["class Repository\n{\n    private LoyaltyPointTransaction \$loyaltyPointTransaction;\n\n    public function update(\$id, array \$data)\n    {\n        return \$this->loyaltyPointTransaction->find(\$id)->update(\$data);\n    }\n}", [$injected]],
            'a handed-in model: a typed parameter deleted' => ["function destroy(WalletTransaction \$transaction)\n{\n    \$transaction->delete();\n}", [$parameter]],
            'a handed-in model: a typed parameter updated through a query' => ["function purge(LoyaltyPointTransaction \$ledger)\n{\n    \$ledger->where('id', 1)->update(['credit' => 0]);\n}", [$parameter]],
            'a repository: delete' => ["class Controller\n{\n    public function __construct(private readonly WalletTransactionRepositoryInterface \$walletTransactionRepo)\n    {\n    }\n\n    public function purge()\n    {\n        \$this->walletTransactionRepo->delete(params: ['id' => 1]);\n    }\n}", [$repository]],
            'a repository: update' => ["class Controller\n{\n    public function __construct(private readonly LoyaltyPointTransactionRepository \$loyaltyPointTransactionRepo)\n    {\n    }\n\n    public function fix()\n    {\n        \$this->loyaltyPointTransactionRepo->update(id: 1, data: ['balance' => 0]);\n    }\n}", [$repository]],
            'a repository: taken from the container' => ["app(WalletTransactionRepositoryInterface::class)->delete(params: ['id' => 1]);", [$repository]],
            'a repository: taken from the container with make' => ["app()->make(WalletTransactionRepositoryInterface::class)->delete(params: ['id' => 1]);", [$repository]],
            'a repository: held in a variable from the container' => ["\$repository = app(WalletTransactionRepositoryInterface::class);\n\$repository->delete(params: ['id' => 1]);", [$repository]],
            'a repository: held in a variable from new' => ["\$repository = new LoyaltyPointTransactionRepository(\$model, \$settings, \$user);\n\$repository->update(id: 1, data: ['balance' => 0]);", [$repository]],
            'a repository: held in a variable from the property of the class' => [
                "class Controller\n{\n    public function __construct(private readonly WalletTransactionRepositoryInterface \$walletTransactionRepo)\n    {\n    }\n\n    public function purge()\n    {\n        \$repository = \$this->walletTransactionRepo;\n        \$repository->delete(params: ['id' => 1]);\n    }\n}",
                [$repository],
            ],
            'a repository: made with new' => ["(new LoyaltyPointTransactionRepository(\$model, \$settings, \$user))->update(id: 1, data: ['balance' => 0]);", [$repository]],
            'a repository: a property a trait declares, in a class that only uses the trait' => ["class Controller\n{\n    use CustomerTrait;\n\n    public function fix()\n    {\n        \$this->loyaltyPointTransactionRepo->delete(params: ['id' => 1]);\n    }\n}", [$repository]],
            'two edits in one piece of code are two findings, in the order they are written' => ["WalletTransaction::where('id', 1)->delete();\nDB::table('loyalty_point_transactions')->truncate();\nDB::statement('UPDATE wallet_transactions SET credit = 0');", [$model, $builder, $sql]],
        ];
    }

    #[DataProvider('editsAndTheirKinds')]
    public function test_the_scan_finds_every_way_to_edit_a_ledger_row(string $code, array $kinds): void
    {
        $this->assertSame($kinds, array_column(self::fixtureScanner()->editsIn(code: $code), 'kind'));
    }

    /** @return array<string, array{string}> code that reads, inserts or edits something that is not a ledger row */
    public static function codeThatEditsNoLedgerRow(): array
    {
        return [
            'a read with a filter and a page' => ["WalletTransaction::where('user_id', 1)->latest()->paginate(10);"],
            'reads of every kind' => ["WalletTransaction::where('user_id', 1)->get();\nLoyaltyPointTransaction::where('user_id', 1)->sum('credit');\nWalletTransaction::query()->count();\nWalletTransaction::where('id', 1)->exists();"],
            'a row fetched and only read' => ["\$row = WalletTransaction::find(\$id);\n\$total = \$row->credit + 5;\necho \$row->balance;"],
            'a create' => ["WalletTransaction::create(['user_id' => 1, 'credit' => 5]);"],
            'an insert through the model' => ["WalletTransaction::insert([['user_id' => 1]]);"],
            'a firstOrCreate' => ["LoyaltyPointTransaction::firstOrCreate(['transaction_id' => \$id], ['credit' => 5]);"],
            'inserts through the query builder' => ["DB::table('wallet_transactions')->insert(['user_id' => 1]);\nDB::table('wallet_transactions')->insertGetId(['user_id' => 1]);\nDB::table('loyalty_point_transactions')->insertOrIgnore(['user_id' => 1]);"],
            'a new model saved is an insert' => ["\$row = new WalletTransaction();\n\$row->user_id = 1;\n\$row->credit = 5;\n\$row->save();"],
            'a new model made in brackets and saved is an insert' => ["\$row = (new WalletTransaction())->forceFill(['user_id' => 1]);\n\$row->save();"],
            'a new model saved inside a method that takes a typed parameter of another class' => ["public function add(User \$user)\n{\n    \$row = new LoyaltyPointTransaction();\n    \$row->user_id = \$user->id;\n    \$row->save();\n}"],
            'a typed property whose model is only read and created from' => ["class Repository\n{\n    public function __construct(private readonly WalletTransaction \$walletTransaction)\n    {\n    }\n\n    public function add(array \$data)\n    {\n        return \$this->walletTransaction->create(\$data);\n    }\n\n    public function find(array \$params)\n    {\n        return \$this->walletTransaction->where(\$params)->first();\n    }\n}"],
            'a repository that is asked to add and to list' => ["class Controller\n{\n    public function __construct(private readonly WalletTransactionRepositoryInterface \$walletTransactionRepo)\n    {\n    }\n\n    public function index()\n    {\n        \$this->walletTransactionRepo->addWalletTransaction(user_id: '1', amount: 5.0, transactionType: 'add_fund_by_admin', reference: 'x');\n        return \$this->walletTransactionRepo->getListWhere(filters: []);\n    }\n}"],
            'a row fetched with getFirstWhere and only read' => ["class Controller\n{\n    public function __construct(private readonly WalletTransactionRepositoryInterface \$walletTransactionRepo)\n    {\n    }\n\n    public function show(\$id)\n    {\n        \$transaction = \$this->walletTransactionRepo->getFirstWhere(params: ['id' => \$id]);\n\n        return \$transaction->credit - \$transaction->debit;\n    }\n}"],
            'a repository held in a variable and asked to add and to list' => ["\$repository = app(WalletTransactionRepositoryInterface::class);\n\$repository->addWalletTransaction(user_id: '1', amount: 5.0, transactionType: 'add_fund_by_admin', reference: 'x');\n\$repository->getListWhere(filters: []);"],
            'a model made with new or taken from the container, and only read or created from' => ["\$model = new WalletTransaction();\n\$model->where('user_id', 1)->get();\n\$other = app(LoyaltyPointTransaction::class);\n\$other->create(['user_id' => 1]);\n\$other->where('id', 1)->first();\nApp::make(WalletTransaction::class)->where('id', 1)->exists();"],
            'rows kept in a variable and only read' => ["\$rows = WalletTransaction::where('user_id', 1)->get();\n\$total = \$rows->sum('credit');\n\$rows->each(function (\$row) {\n    echo \$row->credit;\n});\n\$balance = \$rows->first()->balance;"],
            'a query that is assigned from itself and only read' => ["\$query = WalletTransaction::where('user_id', 1);\n\$query = \$query->where('id', 1);\n\$query->get();"],
            'a table named by a constant that is not a ledger table, or by one the scan does not know' => ["class Purge\n{\n    private const ORDERS = 'orders';\n\n    public function run()\n    {\n        DB::table(self::ORDERS)->delete();\n        DB::table(self::SOMEWHERE_ELSE)->delete();\n    }\n}"],
            'a class that is not a ledger class taken from the container' => ["App::make(Order::class)->where('id', 1)->delete();\n\$this->app->make(Cart::class)->where('id', 1)->update(['x' => 1]);\napp()->make(Mailer::class)->delete();"],
            'a relation that is read and added to' => ["\$user->walletTransactions()->where('id', 1)->get();\n\$user->walletTransactions()->create(['credit' => 5]);\n\$user->walletTransactions->sum('credit');"],
            'raw SQL that reads' => ["DB::select('select * from wallet_transactions where id = 1');\nDB::select('SELECT * FROM loyalty_point_transactions WHERE user_id = 1 FOR UPDATE');"],
            'raw SQL that inserts' => ["DB::statement('INSERT INTO wallet_transactions (user_id) VALUES (1)');"],
            'raw SQL on a table whose name only starts or ends like a ledger' => ["DB::statement('UPDATE wallet_transactions_archive SET credit = 0');\nDB::statement('DELETE FROM seller_wallet_transactions');\nDB::statement('TRUNCATE loyalty_point_transactions_log');"],
            'an update of the balance column of the customer is not an edit of the ledger' => ["User::where('id', 1)->update(['wallet_balance' => 5]);\nDB::table('users')->where('id', 1)->update(['loyalty_point' => 5]);"],
            'models and tables whose name only starts like a ledger' => ["WalletTransactionNote::where('id', 1)->delete();\nDB::table('wallet_transaction_notes')->delete();"],
            'other rows updated and deleted' => ["\$order = Order::find(1);\n\$order->update(['status' => 'x']);\n\$order->delete();\n\$user->update(['name' => 'y']);"],
            'a variable that held a ledger row and is assigned another row' => ["\$row = WalletTransaction::find(1);\n\$row = Order::find(2);\n\$row->delete();"],
            'a closure parameter of rows that are not the ledger' => ["User::all()->each(function (\$user) {\n    \$user->delete();\n});"],
            'a row named like a ledger row in another method' => ["function first()\n{\n    \$row = WalletTransaction::find(1);\n\n    return \$row;\n}\n\nfunction second(\$row)\n{\n    \$row->update(['x' => 1]);\n}"],
            'the schema builder reading or changing columns' => ["Schema::hasTable('wallet_transactions');\nSchema::table('wallet_transactions', function (\$table) {\n    \$table->dropColumn('payment_method');\n});"],
            'comments of every kind' => ["// WalletTransaction::where('id', 1)->delete();\n/* DB::table('wallet_transactions')->truncate(); */\n# DB::statement('UPDATE wallet_transactions SET credit = 0');\n/** @example \$user->walletTransactions()->delete(); */"],
            'a table named in a variable is not seen (see the scanner for what it cannot see)' => ["DB::table(\$table)->delete();"],
        ];
    }

    #[DataProvider('codeThatEditsNoLedgerRow')]
    public function test_the_scan_reports_nothing_for_reads_inserts_other_tables_and_comments(string $code): void
    {
        $this->assertSame([], self::fixtureScanner()->editsIn(code: $code));
    }

    public function test_a_finding_names_the_line_and_the_statement(): void
    {
        $code = "<?php\n\nclass Scratch\n{\n    public function purge(): void\n    {\n        \$ok = WalletTransaction::where('id', 1)->get();\n        WalletTransaction::where('user_id', 7)\n            ->delete();\n    }\n}\n";

        $this->assertSame(
            [['kind' => LedgerEditScanner::MODEL_CLASS, 'line' => 8, 'statement' => "WalletTransaction::where('user_id', 7) ->delete()"]],
            self::scanner()->editsIn(code: $code),
        );
    }

    public function test_the_relations_to_the_ledger_are_the_methods_that_return_its_rows(): void
    {
        $code = "<?php\nclass User\n{\n    public function walletTransactions(): HasMany\n    {\n        return \$this->hasMany(WalletTransaction::class);\n    }\n\n    public function loyaltyPointTransactions()\n    {\n        return \$this->hasMany(\\App\\Models\\LoyaltyPointTransaction::class, 'user_id');\n    }\n\n    public function orders()\n    {\n        return \$this->hasMany(Order::class);\n    }\n\n    public function user()\n    {\n        return \$this->belongsTo(User::class);\n    }\n}\n";

        $this->assertSame(['walletTransactions', 'loyaltyPointTransactions'], LedgerEditScanner::relationsTo(models: self::MODELS, code: $code));
    }

    public function test_the_constants_that_hold_a_ledger_table_are_found_by_name_and_no_other_constant_is(): void
    {
        $code = "<?php\nfinal class Tables\n{\n    public const WALLET = 'wallet_transactions';\n    private const LOYALTY = \"loyalty_point_transactions\";\n    public const ORDERS = 'orders';\n    public const LIMIT = 10;\n}\n";

        $this->assertSame(['WALLET' => 'wallet_transactions', 'LOYALTY' => 'loyalty_point_transactions'], self::scanner()->constantsDeclaredIn(code: $code));
    }

    public function test_a_file_is_read_when_it_names_the_ledger_only_by_a_constant_that_holds_its_table(): void
    {
        $code = "DB::table(LedgerTables::WALLET)->where('id', 1)->delete();";

        $this->assertFalse(self::scanner()->mentionsTheLedger(code: $code));
        $this->assertTrue(self::scanner(constants: ['WALLET' => 'wallet_transactions'])->mentionsTheLedger(code: $code));
        $this->assertFalse(self::scanner(constants: ['WALLET' => 'wallet_transactions'])->mentionsTheLedger(code: 'DB::table(OtherTables::ORDERS)->delete();'));
    }

    public function test_the_properties_that_hold_a_ledger_model_or_repository_are_found_by_name_and_parameters_are_not_properties(): void
    {
        $code = "trait Holder\n{\n    public function __construct(\n        private readonly WalletTransactionRepositoryInterface \$walletTransactionRepo,\n        protected WalletTransaction \$walletTransaction,\n        private readonly User \$user,\n    ) {\n    }\n\n    public function purge(LoyaltyPointTransaction \$transaction)\n    {\n    }\n}";

        $this->assertSame(
            ['walletTransactionRepo' => LedgerEditScanner::REPOSITORY, 'walletTransaction' => LedgerEditScanner::INJECTED_MODEL],
            self::scanner()->propertiesDeclaredIn(code: $code),
        );
    }
}
