<?php

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderWriterScanner;

/**
 * The scan behind StorefrontOrderWritersTest: every spelling of a writer of orders it is meant to find, in the method that contains it; what is not a writer and so is left alone; and what it
 * cannot see, kept here so that nobody reads the list of writers as covering it.
 */
class OrderWriterScannerTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> a member of a class, and what the scan must find in it */
    public static function spellingsOfAWriter(): array
    {
        return [
            'a static create' => ['public function run() { Order::create([\'id\' => 1]); }', ['Order::create()']],
            'a qualified name' => ['public function run() { \App\Models\Order::forceCreate($data); }', ['Order::forceCreate()']],
            'firstOrCreate' => ['public function run() { Order::firstOrCreate([\'id\' => 1]); }', ['Order::firstOrCreate()']],
            'updateOrCreate' => ['public function run() { Order::updateOrCreate([\'id\' => 1], []); }', ['Order::updateOrCreate()']],
            'a static insert' => ['public function run() { Order::insert([[\'id\' => 1]]); }', ['Order::insert()']],
            'through a builder' => ['public function run() { Order::query()->create($data); }', ['Order::create()']],
            'after scopes and conditions' => ['public function run() { Order::withoutGlobalScopes()->where(\'id\', 1)->firstOrCreate($data); }', ['Order::firstOrCreate()']],
            'a new model' => ['public function run() { $order = new Order($data); $order->save(); }', ['new Order']],
            'a new qualified model' => ['public function run() { $order = new \App\Models\Order; }', ['new Order']],
            'the query builder' => ['public function run() { DB::table(\'orders\')->insert($row); }', ["table('orders')->insert()"]],
            'a chain over several lines' => ["public function run() {\n DB::table('orders')\n ->insertGetId(\$row);\n }", ["table('orders')->insertGetId()"]],
            'a table with an alias' => ['public function run() { DB::table(\'orders as o\')->insert($row); }', ["table('orders')->insert()"]],
            'a connection and double quotes' => ['public function run() { DB::connection(\'shop\')->table("orders")->insertOrIgnore($rows); }', ["table('orders')->insertOrIgnore()"]],
            'an upsert' => ['public function run() { DB::table(\'orders\')->upsert($rows, [\'id\']); }', ["table('orders')->upsert()"]],
            'a builder kept in a variable' => ['public function run() { $query = DB::table(\'orders\'); $query->insert($row); }', ['a builder of orders ->insert()']],
            'raw SQL' => ['public function run() { DB::statement(\'INSERT INTO orders (id) VALUES (1)\'); }', ['raw SQL into orders']],
            'quoted raw SQL' => ['public function run() { DB::insert("insert into `orders` (id) values (?)", [1]); }', ['raw SQL into orders']],
            'a replace' => ['public function run() { DB::statement(\'replace into orders (id) values (1)\'); }', ['raw SQL into orders']],
            'interpolated raw SQL' => ['public function run($id) { DB::statement("insert into orders (id) values ({$id})"); }', ['raw SQL into orders']],
            'a promoted property' => ['public function __construct(private readonly Order $order) {} public function run() { $this->order->create($data); }', ['a declared Order ->create()']],
            'a parameter' => ['public function run(Order $order) { $order->forceCreate($data); }', ['a declared Order ->forceCreate()']],
            'a nullable parameter' => ['public function run(?Order $order) { $order->create([]); }', ['a declared Order ->create()']],
            'a copy of a declared order' => ['public function run(Order $order) { $order->replicate()->save(); }', ['a declared Order ->replicate()']],
            'the container' => ['public function run() { app(Order::class)->create([]); }', ['container Order ->create()']],
            'a relation of the customer' => ['public function run($customer) { $customer->orders()->create([]); }', ['->orders()->create()']],
            'inside a closure' => ['public function run() { DB::transaction(function () { DB::table(\'orders\')->insert([]); }); }', ["table('orders')->insert()"]],
        ];
    }

    #[DataProvider('spellingsOfAWriter')]
    public function test_the_scan_finds_every_spelling_of_a_writer_in_the_method_that_contains_it(string $members, array $spellings): void
    {
        $this->assertSame(['App\Fake\Writer::run' => $spellings], $this->writersIn(members: $members));
    }

    /** @return array<string, array{string}> */
    public static function whatIsNotAWriter(): array
    {
        return [
            'another model' => ['public function run() { Product::create([]); PurchaseOrder::create([]); SubOrder::insert([]); }'],
            'the lines of an order' => ['public function run() { OrderDetail::create([]); DB::table(\'order_details\')->insert([]); }'],
            'a relation of a fetched order' => ['public function run() { Order::find(1)->details()->create([]); Order::with(\'details\')->first()->details()->create([]); }'],
            'a relation of a declared order' => ['public function run(Order $order) { $order->details()->create([]); $order->update([]); $order->save(); }'],
            'reads and updates' => ['public function run() { Order::where(\'id\', 1)->update([]); DB::table(\'orders\')->where(\'id\', 1)->update([]); Order::all(); DB::table(\'orders\')->get(); }'],
            'a comment and a string' => ['public function run() { /* Order::create([]); */ // DB::table(\'orders\')->insert([]);' . "\n" . ' $note = \'Order::create(1)\'; }'],
            'the order of a payment gateway' => ['public function run($api) { $api->order->create([]); }'],
            'the declaration of the writer\'s own method' => ['public function create(array $data) { return $data; } public function make() {}'],
        ];
    }

    #[DataProvider('whatIsNotAWriter')]
    public function test_the_scan_leaves_alone_what_does_not_make_a_row_of_orders(string $members): void
    {
        $this->assertSame([], $this->writersIn(members: $members));
    }

    /** @return array<string, array{string}> */
    public static function whatTheScanCannotSee(): array
    {
        return [
            'a table named by a constant' => ['private const TABLE = \'orders\'; public function run() { DB::table(self::TABLE)->insert([]); }'],
            'a class named by a string' => ['public function run() { $class = \'App\Models\Order\'; $class::create([]); }'],
            'a receiver the file does not declare as an Order' => ['public function run($order) { $order->create([]); }'],
            'a copy of a fetched order' => ['public function run() { Order::find(1)->replicate()->save(); }'],
            'a model filled and saved on the receiver of a call' => ['public function run(Order $order) { $order->fill($data)->save(); }'],
        ];
    }

    #[DataProvider('whatTheScanCannotSee')]
    public function test_what_the_scan_cannot_see_stays_unseen_so_that_nobody_reads_the_list_as_covering_it(string $members): void
    {
        $this->assertSame([], $this->writersIn(members: $members));
    }

    public function test_a_hit_is_named_by_the_function_that_contains_it(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Fake;
            use function strlen;
            interface Contract { public function make(): void; }
            final class Writer implements Contract {
                public function make(): void { $later = function () { Order::create([]); }; }
                public static function other() { return Order::class; }
                public function braced() { return "{$this->name}"; }
                public function last() { Order::insert([]); }
            }
            function loose() { Order::create([]); }
            $anonymous = new class { public function inside() { Order::create([]); } };
            Order::create([]);
            PHP;

        $this->assertSame(
            ['App\Fake\Writer::make', 'App\Fake\Writer::last', 'loose', 'class@anonymous::inside', '(file) Fake.php'],
            array_keys((new OrderWriterScanner())->writersIn(code: $source, file: 'Fake.php')),
        );
    }

    /** @return array<string, array{string, list<string>, string, list<string>}> a member of a class, the types, the method and the code that calls it */
    public static function callsOfAWriter(): array
    {
        $caller = ['App\Fake\Writer::run'];

        return [
            'a static call' => ['public function run() { OrderManager::generateOrder([]); }', ['OrderManager'], 'generateOrder', $caller],
            'a promoted property' => ['public function __construct(private readonly OrderRepositoryInterface $orderRepo) {} public function run() { $this->orderRepo->add(data: []); }', ['OrderRepositoryInterface', 'OrderRepository'], 'add', $caller],
            'a parameter' => ['public function run(OrderRepository $repo) { $repo->add([]); }', ['OrderRepositoryInterface', 'OrderRepository'], 'add', $caller],
            'the container' => ['public function run() { app(OrderRepositoryInterface::class)->add([]); }', ['OrderRepositoryInterface', 'OrderRepository'], 'add', $caller],
            'the same method of another repository' => ['public function __construct(private readonly OrderRepositoryInterface $orderRepo, private readonly ProductRepositoryInterface $productRepo) {} public function run() { $this->productRepo->add(data: []); }', ['OrderRepositoryInterface', 'OrderRepository'], 'add', []],
            'the same method on something else' => ['public function run(OrderRepository $repo, $list) { $list->add(1); }', ['OrderRepositoryInterface', 'OrderRepository'], 'add', []],
            'another method of the class' => ['public function run() { OrderManager::generateOrderAgain([]); }', ['OrderManager'], 'generateOrder', []],
            'its own declaration' => ['public function add(array $data) { return $data; } public function run(OrderRepositoryInterface $repo) {}', ['OrderRepositoryInterface', 'OrderRepository'], 'add', []],
        ];
    }

    #[DataProvider('callsOfAWriter')]
    public function test_the_scan_finds_the_code_that_calls_a_creator_and_only_that(string $members, array $types, string $method, array $callers): void
    {
        $source = "<?php\nnamespace App\\Fake;\nclass Writer\n{\n{$members}\n}\n";

        $this->assertSame($callers, (new OrderWriterScanner())->callersIn(code: $source, types: $types, method: $method, file: 'Writer.php'));
    }

    /** @return array<string, list<string>> */
    private function writersIn(string $members): array
    {
        return (new OrderWriterScanner())->writersIn(code: "<?php\nnamespace App\\Fake;\nuse App\\Models\\Order;\nclass Writer\n{\n{$members}\n}\n", file: 'Writer.php');
    }
}
