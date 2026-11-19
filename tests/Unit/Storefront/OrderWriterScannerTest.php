<?php

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderWriterScanner;

/**
 * The scan behind StorefrontOrderWritersTest: every spelling of a writer of orders it is meant to find, in the method that contains it, one data set for each word of each list it reads; what is
 * not a writer and so is left alone; and what it cannot see, kept here so that nobody reads the list of writers as covering it. The lists are written out in this class, so that taking a word out
 * of the scan, or putting one in, fails a test.
 */
class OrderWriterScannerTest extends TestCase
{
    private const CREATING = ['create', 'forceCreate', 'createQuietly', 'forceCreateQuietly', 'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'firstOrCreate', 'updateOrCreate', 'firstOrNew', 'updateOrInsert', 'make', 'replicate', 'newInstance', 'newModelInstance'];

    private const BUILDER_INSERTS = ['insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'updateOrInsert'];

    private const RELATION_WRITES = ['create', 'forceCreate', 'createMany', 'firstOrCreate', 'updateOrCreate', 'save', 'saveMany', 'insert'];

    private const LEAVES_THE_BUILDER = ['find', 'findOrFail', 'findMany', 'findOr', 'first', 'firstOrFail', 'firstWhere', 'sole', 'get', 'all', 'cursor', 'lazy', 'paginate', 'simplePaginate', 'cursorPaginate', 'pluck', 'value', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntExist', 'toArray', 'toSql', 'chunk', 'each', 'delete', 'update', 'increment', 'decrement', 'truncate', 'restore', 'forceDelete'];

    private const BUILDER_STEPS = ['query', 'newQuery', 'newModelQuery', 'newQueryWithoutScopes', 'newQueryWithoutScope', 'newQueryWithoutRelationships', 'on', 'onWriteConnection', 'withoutGlobalScopes', 'withoutGlobalScope', 'withGlobalScope', 'withTrashed', 'withoutTrashed', 'onlyTrashed', 'where', 'orWhere', 'whereNot', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween', 'whereDate', 'whereKey', 'whereHas', 'whereDoesntHave', 'whereRaw', 'whereColumn', 'whereExists', 'has', 'doesntHave', 'with', 'withCount', 'without', 'lockForUpdate', 'sharedLock', 'latest', 'oldest', 'orderBy', 'orderByDesc', 'select', 'selectRaw', 'distinct', 'from', 'join', 'leftJoin', 'groupBy', 'limit', 'take', 'skip', 'offset', 'when', 'unless', 'tap', 'scopes', 'toBase', 'getQuery'];

    private const DECLARED_ORDER = 'public function __construct(private readonly Order $order) {} public function run() { %s }';

    /** @return array<string, array{string, list<string>}> a member of a class, and what the scan must find in it */
    public static function spellingsOfAWriter(): array
    {
        $sets = [
            'a qualified name' => ['public function run() { \App\Models\Order::forceCreate($data); }', ['Order::forceCreate()']],
            'a name that starts at the root of the namespace' => ['public function run() { \Order::create($data); }', ['Order::create()']],
            'through a builder' => ['public function run() { Order::query()->create($data); }', ['Order::create()']],
            'after scopes and conditions' => ['public function run() { Order::withoutGlobalScopes()->where(\'id\', 1)->firstOrCreate($data); }', ['Order::firstOrCreate()']],
            'through a local scope' => ['public function run() { Order::ofSeller(1)->create($data); }', ['Order::create()']],
            'through a factory' => ['public function run() { Order::factory()->create(); }', ['Order::create()']],
            'a new model' => ['public function run() { $order = new Order($data); $order->save(); }', ['new Order']],
            'a new qualified model' => ['public function run() { $order = new \App\Models\Order; }', ['new Order']],
            'the query builder' => ['public function run() { DB::table(\'orders\')->insert($row); }', ["table('orders')->insert()"]],
            'a chain over several lines' => ["public function run() {\n DB::table('orders')\n ->insertGetId(\$row);\n }", ["table('orders')->insertGetId()"]],
            'a table with an alias' => ['public function run() { DB::table(\'orders as o\')->insert($row); }', ["table('orders')->insert()"]],
            'a table with a database name' => ['public function run() { DB::table(\'shop.orders\')->insert($row); }', ["table('orders')->insert()"]],
            'a builder from a connection kept in a variable' => ['public function run() { $query = DB::connection(\'shop\')->table(\'orders\'); $query->insert($row); }', ['a builder of orders ->insert()']],
            'a builder from an injected connection kept in a variable' => ['public function run() { $query = $this->db->table(\'orders\'); $query->insertGetId($row); }', ['a builder of orders ->insertGetId()']],
            'a builder kept in a variable with conditions of its own' => ['public function run() { $query = DB::table(\'orders\')->where(\'paid\', 1); $query->insert($row); }', ['a builder of orders ->insert()']],
            'a builder kept in a variable and written after conditions' => ['public function run() { $query = DB::table(\'orders\'); $query->where(\'id\', 1)->insertOrIgnore($row); }', ['a builder of orders ->insertOrIgnore()']],
            'a builder kept in a variable inside a closure' => ['public function run() { DB::transaction(function () { $query = DB::table(\'orders\'); $query->insert([]); }); }', ['a builder of orders ->insert()']],
            'a connection and double quotes' => ['public function run() { DB::connection(\'shop\')->table("orders")->insertOrIgnore($rows); }', ["table('orders')->insertOrIgnore()"]],
            'raw SQL' => ['public function run() { DB::statement(\'INSERT INTO orders (id) VALUES (1)\'); }', ['raw SQL into orders']],
            'quoted raw SQL' => ['public function run() { DB::insert("insert into `orders` (id) values (?)", [1]); }', ['raw SQL into orders']],
            'a replace' => ['public function run() { DB::statement(\'replace into orders (id) values (1)\'); }', ['raw SQL into orders']],
            'an insert ignore' => ['public function run() { DB::statement(\'insert ignore into orders (id) values (1)\'); }', ['raw SQL into orders']],
            'raw SQL with a database name' => ['public function run() { DB::unprepared(\'insert into shop.orders (id) values (1)\'); }', ['raw SQL into orders']],
            'interpolated raw SQL' => ['public function run($id) { DB::statement("insert into orders (id) values ({$id})"); }', ['raw SQL into orders']],
            'raw SQL in a heredoc' => ["public function run(\$id) {\n DB::statement(<<<SQL\n insert into orders (id) values ({\$id})\n SQL);\n }", ['raw SQL into orders']],
            'raw SQL in a nowdoc' => ["public function run() {\n DB::statement(<<<'SQL'\n insert into orders (id) values (1)\n SQL);\n }", ['raw SQL into orders']],
            'a promoted property' => [sprintf(self::DECLARED_ORDER, '$this->order->create($data);'), ['a declared Order ->create()']],
            'a typed property' => ['private Order $order; public function run() { $this->order->create($data); }', ['a declared Order ->create()']],
            'a parameter' => ['public function run(Order $order) { $order->forceCreate($data); }', ['a declared Order ->forceCreate()']],
            'a nullable parameter' => ['public function run(?Order $order) { $order->create([]); }', ['a declared Order ->create()']],
            'a parameter of a union type' => ['public function run(Order|Product $order) { $order->create([]); }', ['a declared Order ->create()']],
            'a parameter passed by reference' => ['public function run(Order &$order) { $order->create([]); }', ['a declared Order ->create()']],
            'a copy of a declared order' => ['public function run(Order $order) { $order->replicate()->save(); }', ['a declared Order ->replicate()']],
            'a declared order through a builder' => [sprintf(self::DECLARED_ORDER, '$this->order->query()->create($data);'), ['a declared Order ->create()']],
            'a declared order through scopes' => [sprintf(self::DECLARED_ORDER, '$this->order->where(\'id\', 0)->firstOrCreate($data);'), ['a declared Order ->firstOrCreate()']],
            'a declared order through a new builder' => [sprintf(self::DECLARED_ORDER, '$this->order->newQuery()->insert($rows);'), ['a declared Order ->insert()']],
            'a declared order through several calls' => [sprintf(self::DECLARED_ORDER, '$this->order->withTrashed()->where(\'id\', 0)->with(\'details\')->updateOrCreate([], $data);'), ['a declared Order ->updateOrCreate()']],
            'a declared order, nullsafe' => ['public function run(?Order $order) { $order?->create([]); }', ['a declared Order ->create()']],
            'a variable assigned from the container' => ['public function run() { $order = app(Order::class); $order->create($data); }', ['a declared Order ->create()']],
            'a property assigned from the container' => ['public function __construct() { $this->order = resolve(Order::class); } public function run() { $this->order->create($data); }', ['a declared Order ->create()']],
            'a variable assigned from new' => ['public function run() { $order = new Order(); $order->create($data); }', ['new Order', 'a declared Order ->create()']],
            'the container' => ['public function run() { app(Order::class)->create([]); }', ['container Order ->create()']],
            'the container through a builder' => ['public function run() { app(Order::class)->query()->create([]); }', ['container Order ->create()']],
            'the container through scopes' => ['public function run() { resolve(\App\Models\Order::class)->where(\'id\', 0)->firstOrCreate([]); }', ['container Order ->firstOrCreate()']],
            'the container asked to make it' => ['public function run() { app()->make(Order::class)->create([]); }', ['container Order ->create()']],
            'the container asked to make it with arguments' => ['public function run() { app()->makeWith(Order::class, [])->create([]); }', ['container Order ->create()']],
            'a relation of the customer' => ['public function run($customer) { $customer->orders()->create([]); }', ['->orders()->create()']],
            'a relation through a condition' => ['public function run($customer) { $customer->orders()->where(\'paid\', 1)->firstOrCreate([]); }', ['->orders()->firstOrCreate()']],
            'inside a closure' => ['public function run() { DB::transaction(function () { DB::table(\'orders\')->insert([]); }); }', ["table('orders')->insert()"]],
            'inside an arrow function' => ['public function run() { return array_map(fn ($data) => Order::create($data), []); }', ['Order::create()']],
        ];

        foreach (self::CREATING as $method) {
            $sets["Order::{$method}()"] = [sprintf('public function run() { Order::%s($data); }', $method), ["Order::{$method}()"]];
            $sets["a declared Order ->{$method}()"] = [sprintf(self::DECLARED_ORDER, sprintf('$this->order->%s($data);', $method)), ["a declared Order ->{$method}()"]];
            $sets["container Order ->{$method}()"] = [sprintf('public function run() { app(Order::class)->%s($data); }', $method), ["container Order ->{$method}()"]];
        }

        foreach (self::BUILDER_INSERTS as $method) {
            $sets["table('orders')->{$method}()"] = [sprintf('public function run() { DB::table(\'orders\')->%s($data); }', $method), ["table('orders')->{$method}()"]];
            $sets["a builder of orders ->{$method}()"] = [sprintf('public function run() { $query = DB::table(\'orders\'); $query->%s($data); }', $method), ["a builder of orders ->{$method}()"]];
        }

        foreach (self::RELATION_WRITES as $method) {
            $sets["->orders()->{$method}()"] = [sprintf('public function run($customer) { $customer->orders()->%s($data); }', $method), ["->orders()->{$method}()"]];
            $sets["->order()->{$method}()"] = [sprintf('public function run($customer) { $customer->order()->%s($data); }', $method), ["->order()->{$method}()"]];
        }

        foreach (self::BUILDER_STEPS as $step) {
            $sets["a declared Order ->{$step}()->create()"] = [sprintf(self::DECLARED_ORDER, sprintf('$this->order->%s()->create($data);', $step)), ['a declared Order ->create()']];
            $sets["->orders()->{$step}()->create()"] = [sprintf('public function run($customer) { $customer->orders()->%s()->create($data); }', $step), ['->orders()->create()']];
        }

        return $sets;
    }

    #[DataProvider('spellingsOfAWriter')]
    public function test_the_scan_finds_every_spelling_of_a_writer_in_the_method_that_contains_it(string $members, array $spellings): void
    {
        $this->assertSame(['App\Fake\Writer::run' => $spellings], $this->writersIn(source: self::file(members: $members)));
    }

    /** @return array<string, array{string, array<string, list<string>>}> a whole file, and what the scan must find in it */
    public static function spellingsThatDependOnTheFile(): array
    {
        $writer = static fn (array $spellings): array => ['App\Fake\Writer::run' => $spellings];

        return [
            'a name imported with as' => [self::file(members: 'public function run() { OrderModel::create([]); }', imports: 'use App\Models\Order as OrderModel;'), $writer(['Order::create()'])],
            'a name imported with as, from a root name' => [self::file(members: 'public function run() { OrderModel::forceCreate([]); }', imports: 'use \App\Models\Order as OrderModel;'), $writer(['Order::forceCreate()'])],
            'a name imported with as, in a group' => [self::file(members: 'public function run() { OrderModel::create([]); }', imports: 'use App\Models\{Order as OrderModel, Product};'), $writer(['Order::create()'])],
            'a name imported with as, in a list' => [self::file(members: 'public function run() { OrderModel::create([]); }', imports: 'use App\Models\Product as Item, App\Models\Order as OrderModel;'), $writer(['Order::create()'])],
            'a new model imported with as' => [self::file(members: 'public function run() { return new OrderModel([]); }', imports: 'use App\Models\Order as OrderModel;'), $writer(['new Order'])],
            'a declared property imported with as' => [self::file(members: 'public function __construct(private readonly OrderModel $order) {} public function run() { $this->order->create([]); }', imports: 'use App\Models\Order as OrderModel;'), $writer(['a declared Order ->create()'])],
            'the container asked for a name imported with as' => [self::file(members: 'public function run() { app(OrderModel::class)->create([]); }', imports: 'use App\Models\Order as OrderModel;'), $writer(['container Order ->create()'])],
            'a name relative to an imported namespace' => [self::file(members: 'public function run() { Models\Order::create([]); }', imports: 'use App\Models;'), $writer(['Order::create()'])],
            'another class imported under the name Order' => [self::file(members: 'public function run() { Order::create([]); }', imports: 'use App\Models\Product as Order;'), []],
            'a function imported with as' => [self::file(members: 'public function run() { OrderModel(); Order::create([]); }', imports: 'use function App\order as OrderModel;'), $writer(['Order::create()'])],
            'a closure that uses a variable is not an import' => [self::file(members: 'public function run($Order) { return function () use ($Order) { return Order::create([]); }; }'), $writer(['Order::create()'])],
            'a trait method renamed is not an import' => [self::file(members: 'use OrderCreator { Order as OrderModel; } public function run() { OrderModel::create([]); }'), []],
            'self inside the model' => [self::file(members: 'public function run() { return self::create([]); }', class: 'Order'), ['App\Fake\Order::run' => ['Order::create()']]],
            'static inside the model' => [self::file(members: 'public function run() { return static::query()->firstOrCreate([]); }', class: 'Order'), ['App\Fake\Order::run' => ['Order::firstOrCreate()']]],
            'self inside the model, after a call that leaves the builder' => [self::file(members: 'public function run() { return self::find(1)->details()->create([]); }', class: 'Order'), []],
            'self inside a model that extends the Order' => [self::file(members: 'public function run() { return self::create([]); }', class: 'SpecialOrder extends Order'), ['App\Fake\SpecialOrder::run' => ['Order::create()']]],
            'static inside a model that extends the Order' => [self::file(members: 'public static function run() { return static::create([]); }', class: 'SpecialOrder extends Order'), ['App\Fake\SpecialOrder::run' => ['Order::create()']]],
            'parent inside a model that extends the Order' => [self::file(members: 'public static function run() { return parent::query()->firstOrCreate([]); }', class: 'SpecialOrder extends Order'), ['App\Fake\SpecialOrder::run' => ['Order::firstOrCreate()']]],
            'parent inside a model that extends the Order by a name imported with as' => [self::file(members: 'public static function run() { return parent::create([]); }', imports: 'use App\Models\Order as OrderModel;', class: 'SpecialOrder extends OrderModel'), ['App\Fake\SpecialOrder::run' => ['Order::create()']]],
            'parent inside a model that extends the Order by its qualified name' => [self::file(members: 'public static function run() { return parent::create([]); }', imports: '', class: 'SpecialOrder extends \App\Models\Order'), ['App\Fake\SpecialOrder::run' => ['Order::create()']]],
            'parent inside the Order itself' => [self::file(members: 'public static function run() { return parent::create([]); }', class: 'Order extends Model'), []],
            'parent inside a class that extends another model' => [self::file(members: 'public static function run() { return parent::create([]); }', class: 'SpecialProduct extends Product'), []],
            'self inside another class' => [self::file(members: 'public function run() { return self::create([]); }'), []],
            'self inside a class whose name only ends with Order' => [self::file(members: 'public function run() { return self::create([]); }', class: 'PurchaseOrder'), []],
        ];
    }

    #[DataProvider('spellingsThatDependOnTheFile')]
    public function test_the_scan_reads_the_names_a_file_imports_and_the_class_it_stands_in(string $source, array $writers): void
    {
        $this->assertSame($writers, $this->writersIn(source: $source));
    }

    /** @return array<string, array{string}> */
    public static function whatIsNotAWriter(): array
    {
        $sets = [
            'another model' => ['public function run() { Product::create([]); PurchaseOrder::create([]); SubOrder::insert([]); }'],
            'the lines of an order' => ['public function run() { OrderDetail::create([]); DB::table(\'order_details\')->insert([]); }'],
            'a relation of a fetched order' => ['public function run() { Order::find(1)->details()->create([]); Order::with(\'details\')->first()->details()->create([]); }'],
            'a relation of a declared order' => ['public function run(Order $order) { $order->details()->create([]); $order->update([]); $order->save(); }'],
            'a relation of a declared order after a condition' => ['public function run(Order $order) { $order->where(\'id\', 1)->first()->details()->create([]); }'],
            'a relation of a declared property' => [sprintf(self::DECLARED_ORDER, '$this->order->details()->create([]); $this->order->find(1)->details()->create([]);')],
            'a relation named by a method that is not a builder' => ['public function run($customer) { $customer->orders()->first()->details()->create([]); $customer->orders()->count(); }'],
            'reads and updates' => ['public function run() { Order::where(\'id\', 1)->update([]); DB::table(\'orders\')->where(\'id\', 1)->update([]); Order::all(); DB::table(\'orders\')->get(); }'],
            'a comment and a string' => ['public function run() { /* Order::create([]); */ // DB::table(\'orders\')->insert([]);' . "\n" . ' $note = \'Order::create(1)\'; }'],
            'the order of a payment gateway' => ['public function run($api) { $api->order->create([]); }'],
            'the declaration of the writer\'s own method' => ['public function create(array $data) { return $data; } public function make() {}'],
            'the name of the class' => ['public function run() { return [Order::class, Order::$table, Order::CREATED_AT]; }'],
            'an instance check' => ['public function run($order) { return $order instanceof Order; }'],
            'a builder kept in a variable that is only read or updated' => ['public function run() { $query = DB::table(\'orders\'); $query->get(); $query->where(\'id\', 1)->update([]); $query->delete(); }'],
            'a builder of another table kept in a variable' => ['public function run() { $query = DB::table(\'order_details\'); $query->insert([]); }'],
            'a builder kept in a variable of another function' => ['public function first() { $query = DB::table(\'orders\'); return $query->count(); } public function second($query) { $query->insert([]); }'],
            'an insert on a variable that is not the builder' => ['public function run() { $query = DB::table(\'orders\'); $other = []; $other->insert([]); return $query; }'],
            'a table that is not the orders table' => ['public function run() { DB::table(\'orders_archive\')->insert([]); DB::table(\'order_orders\')->insert([]); DB::statement(\'insert into orders_archive (id) values (1)\'); }'],
            'a select from the orders table' => ['public function run() { DB::select(\'select * from orders\'); DB::statement(\'update orders set id = 1\'); DB::statement(\'delete from orders\'); }'],
            'the container asked for another class' => ['public function run() { app(OrderDetail::class)->create([]); resolve(Product::class)->create([]); }'],
            'a call named like the container on something else' => ['public function run($cart) { $cart->make(Order::class); app(\'orders\')->create([]); }'],
        ];

        foreach (self::LEAVES_THE_BUILDER as $method) {
            $sets["a relation after {$method}() on the model"] = [sprintf('public function run() { Order::where(\'id\', 1)->%s()->details()->create([]); }', $method)];
            $sets["a relation after {$method}() on a declared order"] = [sprintf(self::DECLARED_ORDER, sprintf('$this->order->where(\'id\', 1)->%s()->details()->create([]);', $method))];
            $sets["a relation after {$method}() on the container"] = [sprintf('public function run() { app(Order::class)->where(\'id\', 1)->%s()->details()->create([]); }', $method)];
        }

        return $sets;
    }

    #[DataProvider('whatIsNotAWriter')]
    public function test_the_scan_leaves_alone_what_does_not_make_a_row_of_orders(string $members): void
    {
        $this->assertSame([], $this->writersIn(source: self::file(members: $members)));
    }

    /** @return array<string, array{string}> */
    public static function whatTheScanCannotSee(): array
    {
        return [
            'a table named by a constant' => ['private const TABLE = \'orders\'; public function run() { DB::table(self::TABLE)->insert([]); }'],
            'a table named by a variable' => ['public function run() { $table = \'orders\'; DB::table($table)->insert([]); }'],
            'raw SQL that names the table by a variable' => ['public function run($table) { DB::statement("insert into {$table} (id) values (1)"); }'],
            'raw SQL made of pieces' => ['public function run() { DB::statement(\'insert into \' . \'orders\' . \' (id) values (1)\'); }'],
            'a class named by a string' => ['public function run() { $class = \'App\Models\Order\'; $class::create([]); }'],
            'a class named by a string in a call' => ['public function run() { return call_user_func([\App\Models\Order::class, \'create\'], []); }'],
            'a method named by a variable' => ['public function run($method) { Order::{$method}([]); }'],
            'a model created by the name of its class' => ['public function run() { $class = Order::class; return new $class([]); }'],
            'a class name in another letter case' => ['public function run() { order::create([]); }'],
            'a receiver the file does not declare as an Order' => ['public function run($order) { $order->create([]); }'],
            'a receiver typed only in a docblock' => ['/** @param Order $order */ public function run($order) { $order->create([]); }'],
            'a receiver typed as the base model' => ['public function __construct(private readonly Model $model) {} public function run() { $this->model->create([]); }'],
            'a property declared in a parent class' => ['public function run() { $this->order->create([]); }'],
            'a variable assigned from a method' => ['public function run() { $order = $this->factory->order(); $order->create([]); }'],
            'the container asked by the name of the class as a string' => ['public function run() { app(\'App\Models\Order\')->create([]); }'],
            'a relation kept in a variable' => ['public function run($customer) { $orders = $customer->orders(); $orders->create([]); }'],
            'a builder of the Order kept in a variable' => ['public function run() { $query = Order::query(); $query->create([]); }'],
            'a model that extends the Order, called by its own name from another class' => ['public function run() { return SpecialOrder::create([]); }'],
            'a copy of a fetched order' => ['public function run() { Order::find(1)->replicate()->save(); }'],
            'a model filled and saved on the receiver of a call' => ['public function run(Order $order) { $order->fill($data)->save(); }'],
            'a new instance of the model filled by the container' => ['public function run() { app(Order::class)->fill($data)->save(); }'],
        ];
    }

    #[DataProvider('whatTheScanCannotSee')]
    public function test_what_the_scan_cannot_see_stays_unseen_so_that_nobody_reads_the_list_as_covering_it(string $members): void
    {
        $this->assertSame([], $this->writersIn(source: self::file(members: $members)));
    }

    /** @return array<string, array{string}> */
    public static function whatTheScanCannotSeeInTheFile(): array
    {
        return [
            'new static inside the model' => [self::file(members: 'public function run() { return new static([]); }', class: 'Order')],
            'new self inside the model' => [self::file(members: 'public function run() { return new self([]); }', class: 'Order')],
            'another model over the orders table' => [self::file(members: 'public function run() { return OrderRow::create([]); }', imports: 'use App\Models\Order;')],
        ];
    }

    #[DataProvider('whatTheScanCannotSeeInTheFile')]
    public function test_what_the_scan_cannot_see_in_a_file_stays_unseen_too(string $source): void
    {
        $this->assertSame([], $this->writersIn(source: $source));
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
                public function list() { Order::create([]); }
                public function new() { Order::create([]); }
                public function match() { Order::create([]); }
                public static function print() { Order::create([]); }
                public function &default() { Order::create([]); }
                public static function static() { Order::create([]); }
            }
            function loose() { Order::create([]); }
            $anonymous = new class { public function inside() { Order::create([]); } };
            Order::create([]);
            PHP;

        $this->assertSame(
            ['App\Fake\Writer::make', 'App\Fake\Writer::last', 'App\Fake\Writer::list', 'App\Fake\Writer::new', 'App\Fake\Writer::match', 'App\Fake\Writer::print', 'App\Fake\Writer::default', 'App\Fake\Writer::static', 'loose', 'class@anonymous::inside', '(file) Fake.php'],
            array_keys((new OrderWriterScanner())->writersIn(code: $source, file: 'Fake.php')),
        );
    }

    public function test_every_word_the_scan_looks_for_is_pinned_here_and_nothing_pinned_here_is_gone(): void
    {
        $this->assertEqualsCanonicalizing(self::CREATING, OrderWriterScanner::CREATING, 'The creating methods.');
        $this->assertEqualsCanonicalizing(self::BUILDER_INSERTS, OrderWriterScanner::BUILDER_INSERTS, 'The inserts of the query builder.');
        $this->assertEqualsCanonicalizing(self::RELATION_WRITES, OrderWriterScanner::RELATION_WRITES, 'The writes of a relation.');
        $this->assertEqualsCanonicalizing(self::LEAVES_THE_BUILDER, OrderWriterScanner::LEAVES_THE_BUILDER, 'The methods that leave the builder.');
        $this->assertEqualsCanonicalizing(self::BUILDER_STEPS, OrderWriterScanner::BUILDER_STEPS, 'The methods that give a builder.');
    }

    /** @return array<string, array{string, list<string>, string, list<string>}> a member of a class, the types, the method and the code that calls it */
    public static function callsOfAWriter(): array
    {
        $caller = ['App\Fake\Writer::run'];
        $repository = ['OrderRepositoryInterface', 'OrderRepository'];
        $declared = 'public function __construct(private readonly OrderRepositoryInterface $orderRepo) {} public function run() { %s }';

        return [
            'a static call' => ['public function run() { OrderManager::generateOrder([]); }', ['OrderManager'], 'generateOrder', $caller],
            'a static call by a qualified name' => ['public function run() { \App\Utils\OrderManager::generateOrder([]); }', ['OrderManager'], 'generateOrder', $caller],
            'a static call with named arguments' => ['public function run() { OrderManager::generateOrder(data: []); }', ['OrderManager'], 'generateOrder', $caller],
            'a static call as a callable' => ['public function run() { return OrderManager::generateOrder(...); }', ['OrderManager'], 'generateOrder', $caller],
            'a promoted property' => [sprintf($declared, '$this->orderRepo->add(data: []);'), $repository, 'add', $caller],
            'a parameter' => ['public function run(OrderRepository $repo) { $repo->add([]); }', $repository, 'add', $caller],
            'the container' => ['public function run() { app(OrderRepositoryInterface::class)->add([]); }', $repository, 'add', $caller],
            'the container asked to resolve it' => ['public function run() { resolve(OrderRepositoryInterface::class)->add([]); }', $repository, 'add', $caller],
            'the container asked to make it' => ['public function run() { app()->make(OrderRepositoryInterface::class)->add([]); }', $repository, 'add', $caller],
            'the container as a facade' => ['public function run() { App::make(OrderRepositoryInterface::class)->add([]); }', $repository, 'add', $caller],
            'a variable assigned from the container' => ['public function run() { $repo = app(OrderRepositoryInterface::class); $repo->add([]); }', $repository, 'add', $caller],
            'a variable assigned from the container and called later' => ['public function run() { $repo = app(OrderRepositoryInterface::class); $this->log(); return $repo->add([]); }', $repository, 'add', $caller],
            'a property assigned from the container' => ['public function __construct() { $this->repo = app(OrderRepositoryInterface::class); } public function run() { $this->repo->add([]); }', $repository, 'add', ['App\Fake\Writer::run']],
            'a variable assigned from new' => ['public function run() { $repo = new OrderRepository($order); $repo->add([]); }', $repository, 'add', $caller],
            'a new object called at once' => ['public function run() { (new OrderRepository($order))->add([]); }', $repository, 'add', $caller],
            'a new object without arguments called at once' => ['public function run() { (new OrderRepository)->add([]); }', $repository, 'add', $caller],
            'a new object called without parentheses around it' => ['public function run() { new OrderRepository($order)->add([]); }', $repository, 'add', $caller],
            'a new object that is not called' => ['public function run() { $this->items[] = new OrderRepository($order); $this->list->add(1); }', $repository, 'add', []],
            'a call inside a closure' => ['public function run() { return DB::transaction(function () { return OrderManager::generateOrder([]); }); }', ['OrderManager'], 'generateOrder', $caller],
            'the same method of another repository' => ['public function __construct(private readonly OrderRepositoryInterface $orderRepo, private readonly ProductRepositoryInterface $productRepo) {} public function run() { $this->productRepo->add(data: []); }', $repository, 'add', []],
            'the same method on something else' => ['public function run(OrderRepository $repo, $list) { $list->add(1); }', $repository, 'add', []],
            'another method of the class' => ['public function run() { OrderManager::generateOrderAgain([]); }', ['OrderManager'], 'generateOrder', []],
            'its own declaration' => ['public function add(array $data) { return $data; } public function run(OrderRepositoryInterface $repo) {}', $repository, 'add', []],
            'the container asked for another class' => ['public function run() { app(ProductRepositoryInterface::class)->add([]); }', $repository, 'add', []],
            'a call on this in a class that is not one of the types' => ['public function run() { return $this->add([]); }', $repository, 'add', []],
            'self in a class that is not one of the types' => ['public function run() { return self::generateOrder([]); }', ['OrderManager'], 'generateOrder', []],
        ];
    }

    #[DataProvider('callsOfAWriter')]
    public function test_the_scan_finds_the_code_that_calls_a_creator_and_only_that(string $members, array $types, string $method, array $callers): void
    {
        $this->assertSame($callers, $this->callersIn(source: self::file(members: $members, imports: ''), types: $types, method: $method));
    }

    /** @return array<string, array{string, list<string>, string, list<string>}> a whole file, the types, the method and the code that calls it */
    public static function callsThatDependOnTheFile(): array
    {
        $manager = ['OrderManager'];
        $repository = ['OrderRepositoryInterface', 'OrderRepository'];

        return [
            'a name imported with as' => [self::file(members: 'public function run() { OM::generateOrder([]); }', imports: 'use App\Utils\OrderManager as OM;'), $manager, 'generateOrder', ['App\Fake\Writer::run']],
            'a name imported with as, in a group' => [self::file(members: 'public function run() { OM::generateOrder([]); }', imports: 'use App\Utils\{OrderManager as OM, Cart};'), $manager, 'generateOrder', ['App\Fake\Writer::run']],
            'a parameter declared with a name imported with as' => [self::file(members: 'public function run(Repo $repo) { $repo->add([]); }', imports: 'use App\Contracts\Repositories\OrderRepositoryInterface as Repo;'), $repository, 'add', ['App\Fake\Writer::run']],
            'a container asked for a name imported with as' => [self::file(members: 'public function run() { app(Repo::class)->add([]); }', imports: 'use App\Repositories\OrderRepository as Repo;'), $repository, 'add', ['App\Fake\Writer::run']],
            'another class imported under the name of the type' => [self::file(members: 'public function run() { OrderManager::generateOrder([]); }', imports: 'use App\Other\Cart as OrderManager;'), $manager, 'generateOrder', []],
            'self inside the class' => [self::file(members: 'public function placeScratch() { return self::generateOrder([]); }', imports: '', class: 'OrderManager'), $manager, 'generateOrder', ['App\Fake\OrderManager::placeScratch']],
            'static inside the class' => [self::file(members: 'public static function placeScratch() { return static::generateOrder([]); }', imports: '', class: 'OrderManager'), $manager, 'generateOrder', ['App\Fake\OrderManager::placeScratch']],
            'self inside the class, in a closure' => [self::file(members: 'public static function placeScratch() { return DB::transaction(fn () => self::generateOrder([])); }', imports: '', class: 'OrderManager'), $manager, 'generateOrder', ['App\Fake\OrderManager::placeScratch']],
            'this inside the class' => [self::file(members: 'public function placeScratch() { return $this->add([]); }', imports: '', class: 'OrderRepository'), $repository, 'add', ['App\Fake\OrderRepository::placeScratch']],
            'this inside a class named like the interface' => [self::file(members: 'public function placeScratch() { return $this->add([]); }', imports: '', class: 'OrderRepositoryInterface'), $repository, 'add', ['App\Fake\OrderRepositoryInterface::placeScratch']],
            'self inside a class that extends the class' => [self::file(members: 'public static function run() { return self::generateOrder([]); }', imports: 'use App\Utils\OrderManager;', class: 'ScratchManager extends OrderManager'), $manager, 'generateOrder', ['App\Fake\ScratchManager::run']],
            'static inside a class that extends the class' => [self::file(members: 'public static function run() { return static::generateOrder([]); }', imports: 'use App\Utils\OrderManager;', class: 'ScratchManager extends OrderManager'), $manager, 'generateOrder', ['App\Fake\ScratchManager::run']],
            'parent inside a class that extends the class' => [self::file(members: 'public static function run() { return parent::generateOrder([]); }', imports: 'use App\Utils\OrderManager;', class: 'ScratchManager extends OrderManager'), $manager, 'generateOrder', ['App\Fake\ScratchManager::run']],
            'this inside a class that extends the class' => [self::file(members: 'public function run() { return $this->add([]); }', imports: 'use App\Repositories\OrderRepository;', class: 'ScratchRepository extends OrderRepository'), $repository, 'add', ['App\Fake\ScratchRepository::run']],
            'parent inside a class that extends the class, in a method of its own' => [self::file(members: 'public function add(array $data): string|object { return parent::add($data); }', imports: 'use App\Repositories\OrderRepository;', class: 'ScratchRepository extends OrderRepository'), $repository, 'add', ['App\Fake\ScratchRepository::add']],
            'parent inside a class that extends the class by a name imported with as' => [self::file(members: 'public static function run() { return parent::generateOrder([]); }', imports: 'use App\Utils\OrderManager as OM;', class: 'ScratchManager extends OM'), $manager, 'generateOrder', ['App\Fake\ScratchManager::run']],
            'parent inside a class that extends the class by its qualified name' => [self::file(members: 'public static function run() { return parent::generateOrder([]); }', imports: '', class: 'ScratchManager extends \App\Utils\OrderManager'), $manager, 'generateOrder', ['App\Fake\ScratchManager::run']],
            'parent inside a class that extends another class' => [self::file(members: 'public static function run() { return parent::generateOrder([]); }', imports: '', class: 'ScratchManager extends Cart'), $manager, 'generateOrder', []],
            'parent inside the class itself' => [self::file(members: 'public static function run() { return parent::generateOrder([]); }', imports: '', class: 'OrderManager extends Cart'), $manager, 'generateOrder', []],
            'another method inside the class' => [self::file(members: 'public function placeScratch() { return self::generateOrderAgain([]); }', imports: '', class: 'OrderManager'), $manager, 'generateOrder', []],
            'the method of another object inside the class' => [self::file(members: 'public function placeScratch() { return $this->other->add([]); }', imports: '', class: 'OrderRepository'), $repository, 'add', []],
            'a private method called by the class' => [self::file(members: 'public function materialize() { return $this->makeOrder(); } private function makeOrder() {}', imports: '', class: 'ChannelOrderMaterializer'), ['ChannelOrderMaterializer'], 'makeOrder', ['App\Fake\ChannelOrderMaterializer::materialize']],
        ];
    }

    #[DataProvider('callsThatDependOnTheFile')]
    public function test_the_scan_finds_the_calls_a_file_makes_by_the_names_it_imports_and_inside_the_class_that_has_the_method(string $source, array $types, string $method, array $callers): void
    {
        $this->assertSame($callers, $this->callersIn(source: $source, types: $types, method: $method));
    }

    /** @return array<string, array{string, list<string>, string}> a whole file, the types and the method it calls without being seen to */
    public static function whatTheScanCannotSeeOfACaller(): array
    {
        $manager = ['OrderManager'];
        $repository = ['OrderRepositoryInterface', 'OrderRepository'];

        return [
            'a callable named by a string' => [self::file(members: 'public function run() { return call_user_func(\'App\Utils\OrderManager::generateOrder\', []); }', imports: ''), $manager, 'generateOrder'],
            'a callable named by an array' => [self::file(members: 'public function run() { return call_user_func([OrderManager::class, \'generateOrder\'], []); }', imports: ''), $manager, 'generateOrder'],
            'a method named by a variable' => [self::file(members: 'public function run($method) { return OrderManager::{$method}([]); }', imports: ''), $manager, 'generateOrder'],
            'a class named by a variable' => [self::file(members: 'public function run($class) { return $class::generateOrder([]); }', imports: ''), $manager, 'generateOrder'],
            'a receiver the file does not declare' => [self::file(members: 'public function run($repo) { $repo->add([]); }', imports: ''), $repository, 'add'],
            'a receiver typed only in a docblock' => [self::file(members: '/** @param OrderRepository $repo */ public function run($repo) { $repo->add([]); }', imports: ''), $repository, 'add'],
            'a property declared in a parent class' => [self::file(members: 'public function run() { $this->repo->add([]); }', imports: 'use App\Repositories\OrderRepository;'), $repository, 'add'],
            'a variable assigned from a method' => [self::file(members: 'public function run() { $repo = $this->factory->repository(); $repo->add([]); }', imports: 'use App\Repositories\OrderRepository;'), $repository, 'add'],
            'the container asked by a variable' => [self::file(members: 'public function run($class) { app($class)->add([]); }', imports: 'use App\Repositories\OrderRepository;'), $repository, 'add'],
            'a receiver typed by a class that extends the class' => [self::file(members: 'public function run(ScratchRepository $repo) { $repo->add([]); }', imports: ''), $repository, 'add'],
            'a receiver typed by another interface that the container binds to the class' => [self::file(members: 'public function run(BaseRepositoryInterface $repo) { $repo->add([]); }', imports: ''), $repository, 'add'],
            'a class that extends the class, called by its own name' => [self::file(members: 'public function run() { return ScratchManager::generateOrder([]); }', imports: ''), $manager, 'generateOrder'],
        ];
    }

    #[DataProvider('whatTheScanCannotSeeOfACaller')]
    public function test_what_the_scan_cannot_see_of_a_caller_stays_unseen_so_that_nobody_reads_the_list_as_covering_it(string $source, array $types, string $method): void
    {
        $this->assertSame([], $this->callersIn(source: $source, types: $types, method: $method));
    }

    private static function file(string $members, string $imports = 'use App\Models\Order;', string $class = 'Writer'): string
    {
        return "<?php\nnamespace App\\Fake;\n{$imports}\nclass {$class}\n{\n{$members}\n}\n";
    }

    /** @return array<string, list<string>> */
    private function writersIn(string $source): array
    {
        return (new OrderWriterScanner())->writersIn(code: $source, file: 'Writer.php');
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    private function callersIn(string $source, array $types, string $method): array
    {
        return (new OrderWriterScanner())->callersIn(code: $source, types: $types, method: $method, file: 'Writer.php');
    }
}
