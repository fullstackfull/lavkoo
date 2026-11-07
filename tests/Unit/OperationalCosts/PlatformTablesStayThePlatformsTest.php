<?php

namespace Tests\Unit\OperationalCosts;

use App\Http\Middleware\EnsureSalesChannelsMigrated;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

/**
 * The platform's tables stay the platform's. The cost and sales-channel modules read them; the commission, wallet and settlement tables they do not touch at all, and the
 * procurement, stock and catalogue tables they never write. It is checked in the source (like SingleHttpExitTest checks the one way out of the server): every write into a table
 * the modules do not own has to be on the list below, with the file that makes it. Every file the modules own is read, wherever it lives (their directories, their models, commands,
 * provider, middleware, migrations, routes and views), by the scanner in `tests/Support/PlatformWriteScanner.php`, which sees a table named by a string, a constant, an alias or a
 * variable, a builder kept in a variable, raw SQL, the schema builder and the platform's models.
 */
class PlatformTablesStayThePlatformsTest extends TestCase
{
    /** The platform's own commission, wallet and settlement tables and the classes around them: no file of the modules mentions any of them, to read or to write. */
    private const COMMISSIONS = '/\b(commission_rules|order_item_commissions|sales_commission|seller_wallets|vendor_ledger_entries|vendor_settlements)\b|\b(CommissionRule|CommissionEngine|OrderItemCommission|SellerWallet|VendorLedger|VendorLedgerEntry|VendorSettlement|SettlementEngine)\b/';

    /** What the modules must never write, whoever asks: the procurement, stock and catalogue tables and the wallet and commission tables. */
    private const NEVER_WRITTEN = [
        'products', 'stock_movements', 'warehouse_stock', 'warehouses', 'purchase_orders', 'purchase_order_items', 'suppliers', 'product_batches', 'product_stocks',
        'commission_rules', 'order_item_commissions', 'sales_commission', 'seller_wallets', 'vendor_ledger_entries', 'vendor_settlements',
    ];

    /** The platform's models whose save, create, update or delete would write one of its tables. */
    private const PLATFORM_MODELS = [
        'Product', 'Order', 'OrderDetail', 'Seller', 'Supplier', 'Warehouse', 'WarehouseStock', 'StockMovement', 'PurchaseOrder', 'PurchaseOrderItem', 'ProductBatch',
        'CommissionRule', 'OrderItemCommission', 'SellerWallet', 'VendorLedgerEntry', 'VendorSettlement',
    ];

    /**
     * Every write the modules make into a table that is not their own, by the file that makes it. The sales channels' dispatch makes the internal order the Torod Point and M5AZN
     * pipeline ships (`orders`, `order_details`), changes `orders` where the channel or the provider cancels it or the buyer's details are removed, and scrubs the buyer's text out of
     * the tables the buyer's words were echoed into (a table named in a variable). Nothing of the cost module is here: it writes only its own tables.
     *
     * @var array<string, list<string>>
     */
    private const WRITES_INTO_THE_PLATFORMS_TABLES = [
        'Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php' => ['order_details', 'orders'],
        'Services/SalesChannels/Dispatch/BuyerDataPurger.php' => ['orders'],
        'Services/SalesChannels/Dispatch/ManagedShipmentMirror.php' => ['orders'],
        'Services/SalesChannels/Dispatch/BuyerEchoScrubber.php' => ['(a table named in a variable)'],
    ];

    /**
     * The only places the modules name a model of the platform, and which: each reads (an order for the shipment it is about, a product's purchase price, product names for a picker and a
     * routing rule's summary, whether a seller exists, the product a channel listing is mapped to) and writes nothing.
     *
     * @var array<string, list<string>>
     */
    private const MODELS_OF_THE_PLATFORM_READ = [
        'Http/Controllers/Admin/SalesChannels/Support/AccountForm.php' => ['Seller'],
        'Http/Controllers/Admin/SalesChannels/Support/ProductDirectory.php' => ['Product'],
        'Http/Controllers/Admin/SalesChannels/Support/RuleSummary.php' => ['Product'],
        'Models/SalesChannelProductMapping.php' => ['Product'],
        'Services/OperationalCosts/InventoryCostLayerService.php' => ['Product'],
        'Services/OperationalCosts/Shipments/ShipmentFactsFactory.php' => ['Order'],
    ];

    /** The directories that hold nothing but the modules' own code. */
    private const MODULE_DIRECTORIES = [
        'app/Services/SalesChannels', 'app/Services/OperationalCosts', 'app/Http/Controllers/Admin/SalesChannels', 'app/Http/Controllers/Admin/OperationalCosts',
        'app/Http/Requests/Admin/OperationalCosts', 'app/Enums/OperationalCosts', 'app/Enums/SalesChannels',
        'resources/views/admin-views/operational-costs', 'resources/views/admin-views/sales-channels',
    ];

    /** What the name of a file anywhere under app/ says it belongs to the modules: their models, their commands (and the traits of the commands), their provider and their middleware. */
    private const MODULE_FILE_NAMES = '/^(OperationalCost|SalesChannel|InventoryCost|InventoryLocation|InventoryPool|InventoryReservation|InventorySourcePolicy|ChannelOrderDispatch|Costs[A-Z]|SkipsUntilSalesChannels|SkipsWhileOperationalCosts|EnsureSalesChannels)/';

    /** Files of the modules that are in neither of the above and are read all the same. */
    private const MODULE_FILES_BY_PATH = ['routes/admin/operational-costs.php', 'routes/admin/sales-channels.php', 'app/Http/Controllers/Api/TrendyolWebhookController.php'];

    /** The files of the modules outside their directories that the check must be reading: the day the rule that finds them stops finding one, this fails. */
    private const FILES_OUTSIDE_THE_MODULE_DIRECTORIES = [
        'Providers/SalesChannelsServiceProvider.php', 'Http/Middleware/EnsureSalesChannelsMigrated.php', 'Http/Controllers/Api/TrendyolWebhookController.php',
        'Console/Commands/Concerns/SkipsUntilSalesChannelsMigrated.php', 'Console/Commands/Concerns/SkipsWhileOperationalCostsAreOff.php',
        'Console/Commands/CostsAccrueStorageCommand.php', 'Console/Commands/CostsLayersFromPurchaseOrdersCommand.php', 'Console/Commands/CostsPostPendingFeesCommand.php',
        'Console/Commands/CostsReconcilePlatformCommand.php', 'Console/Commands/CostsRefreshCommand.php', 'Console/Commands/SalesChannelsDispatchCommand.php',
        'Console/Commands/SalesChannelsPurgeBuyerDataCommand.php', 'Console/Commands/SalesChannelsStockUsePlatformForOwnWarehouseCommand.php', 'Console/Commands/SalesChannelsSyncCommand.php',
        'Models/ChannelOrderDispatch.php', 'Models/InventoryCostConsumption.php', 'Models/InventoryCostLayer.php', 'Models/InventoryLocation.php', 'Models/InventoryLocationBalance.php',
        'Models/InventoryPool.php', 'Models/InventoryReservation.php', 'Models/InventorySourcePolicy.php', 'Models/OperationalCostEntry.php', 'Models/OperationalCostInvoice.php',
        'Models/OperationalCostInvoiceItem.php', 'Models/SalesChannelAccount.php', 'Models/SalesChannelEvent.php',
    ];

    /** The tables of the platform no migration of the modules may so much as name (the ones the modules never write, and the store's own order, user and settings tables). */
    private const PLATFORM_TABLES_MIGRATIONS_NEVER_NAME = ['orders', 'order_details', 'users', 'sellers', 'categories', 'brands', 'business_settings', 'currencies'];

    /** @var list<string>|null */
    private static ?array $moduleFiles = null;

    public function test_no_file_of_the_modules_reads_or_writes_the_commission_wallet_or_settlement_tables(): void
    {
        $offenders = [];
        foreach ($this->moduleFiles() as $file) {
            if (preg_match(self::COMMISSIONS, $this->code($file), $found) === 1) {
                $offenders[$this->relative($file)] = $found[0];
            }
        }

        $this->assertSame([], $offenders, 'The platform\'s commissions are income from vendors, the modules\' fees are paid to Amazon and Trendyol: the two are never mixed or read from one another.');
    }

    public function test_no_file_of_the_modules_writes_a_table_of_the_platform_except_the_ones_listed(): void
    {
        $found = [];
        foreach ($this->moduleFiles() as $file) {
            $outside = array_values(array_diff($this->writesIn($this->code($file)), $this->moduleTables(), $this->isMigration($file) ? [PlatformWriteScanner::UNKNOWN_TABLE] : []));
            if ($outside !== []) {
                sort($outside);
                $found[$this->relative($file)] = $outside;
            }
        }
        ksort($found);
        $listed = self::WRITES_INTO_THE_PLATFORMS_TABLES;
        ksort($listed);

        $this->assertSame($listed, $found, 'A file writes a table of the platform that is not on the list (or a listed write is gone): the platform\'s tables are written by the platform.');
    }

    public function test_the_modules_name_a_model_of_the_platform_only_where_they_read_with_it(): void
    {
        $found = [];
        foreach ($this->moduleFiles() as $file) {
            $models = $this->platformModelsIn($this->code($file));
            if ($models !== []) {
                $found[$this->relative($file)] = $models;
            }
        }
        ksort($found);
        $listed = self::MODELS_OF_THE_PLATFORM_READ;
        ksort($listed);

        $this->assertSame($listed, $found, 'A file of the modules names a model of the platform that is not on the list: reading is done with the query builder, and a model that is only held in a variable can be saved without this check seeing it.');
    }

    public function test_the_list_names_no_procurement_stock_catalogue_or_commission_table(): void
    {
        $listed = array_merge(...array_values(self::WRITES_INTO_THE_PLATFORMS_TABLES));

        $this->assertSame([], array_values(array_intersect($listed, self::NEVER_WRITTEN)));
        foreach (array_keys(self::WRITES_INTO_THE_PLATFORMS_TABLES) as $file) {
            $this->assertFileExists(app_path($file));
        }
    }

    public function test_the_cost_module_writes_no_table_but_its_own(): void
    {
        foreach ($this->moduleFiles() as $file) {
            if (str_contains($file, '/Services/OperationalCosts/') || str_contains($file, '/Admin/OperationalCosts/') || str_contains($file, 'Commands/Costs')) {
                $this->assertSame([], array_values(array_diff($this->writesIn($this->code($file)), $this->moduleTables())), $this->relative($file));
            }
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function writes(): array
    {
        return [
            'a query on a table' => ["DB::table('stock_movements')->insert(['x' => 1]);", ['stock_movements']],
            'an update after conditions' => ["\$x = DB::table('products')->where('id', 1)->update(['current_stock' => 0]);", ['products']],
            'a delete' => ["DB::table('purchase_orders')->where('id', 1)->delete();", ['purchase_orders']],
            'an increment' => ["DB::table('warehouse_stock')->increment('quantity');", ['warehouse_stock']],
            'a query on the connection' => ["\$connection->table('suppliers')->upsert([], ['id']);", ['suppliers']],
            'a table named in a variable' => ['DB::table($table)->where($key, $id)->update([$column => 1]);', ['(a table named in a variable)']],
            'a read with a subquery is not a write' => ["DB::table('orders')->whereNotIn('id', DB::table('external_order_mappings')->select('order_id'))->get();", []],
            'a plain read' => ["DB::table('products')->where('id', 1)->value('name');", []],
            'the platform\'s model' => ["Product::where('id', 1)->update(['current_stock' => 1]);", ['model Product']],
            'a new platform model saved' => ["\$po = new PurchaseOrder(); \$po->save();", ['model PurchaseOrder']],
            'a platform model read' => ["Product::query()->whereKey(1)->value('purchase_price');", []],
            'raw SQL' => ["DB::statement('UPDATE stock_movements SET qty_change = 0');", ['stock_movements']],
            'raw SQL insert' => ['DB::insert("insert into `commission_rules` (a) values (1)");', ['commission_rules']],
            'a comment that names a write is not one' => ["// DB::table('products')->update(['x' => 1]);\n\$x = 1;", []],
            'a word in a sentence is not raw SQL' => ["\$this->audit->record(action: 'update the product');", []],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> ways of writing a table of the platform that the first version of the check did not see, and the table each one writes */
    public static function writesThatHidBehindTheFirstCheck(): array
    {
        return [
            'a builder kept in a variable, written on the next statement' => ["\$query = DB::table('products')->where('id', 1);\n\$query->update(['current_stock' => 0]);", 'products'],
            'the table named by a class constant' => ["private const PRODUCTS = 'products';\nDB::table(self::PRODUCTS)->where('id', 1)->update(['current_stock' => 0]);", 'products'],
            'a table with an alias, as the modules\' own reads write it' => ["DB::table('products as product')->where('product.id', 1)->update(['current_stock' => 0]);", 'products'],
            'a purchase order table with an alias deleted' => ["DB::table('purchase_orders as po')->where('po.id', 1)->delete();", 'purchase_orders'],
            'a query started with from()' => ["DB::query()->from('stock_movements')->insert(['product_id' => 1]);", 'stock_movements'],
            'raw SQL with the table interpolated' => ["DB::statement(\"UPDATE {\$table} SET current_stock = 0\");", PlatformWriteScanner::UNKNOWN_TABLE],
            'raw SQL with the table in a quoted PHP variable' => ["\$table = 'products'; DB::update(\"update \$table set current_stock = 0\");", PlatformWriteScanner::UNKNOWN_TABLE],
            'two statements in one string' => ["DB::unprepared(\"UPDATE inventory_cost_layers SET status = 'void'; UPDATE products SET current_stock = 0\");", 'products'],
            'a column added to a platform table by the schema builder' => ["Schema::table('products', function (\$table) { \$table->integer('cost_layer_units')->nullable(); });", 'products'],
            'an index added to a platform table' => ["Schema::table('stock_movements', fn (\$table) => \$table->unique(['reference_type', 'reference_id']));", 'stock_movements'],
            'a platform table dropped' => ["Schema::dropIfExists('suppliers');", 'suppliers'],
            'a model fetched on one statement and saved on the next' => ["\$product = Product::find(1);\n\$product->current_stock = 0;\n\$product->save();", 'model Product'],
            'a model written through a relation of a model of the modules' => ["\$mapping->product->update(['current_stock' => 0]);", 'model Product'],
            'a model written through a relation method' => ["\$mapping->product()->update(['current_stock' => 0]);", 'model Product'],
            'a model of a loop saved in the loop' => ["foreach (Product::query()->get() as \$product) {\n\$product->forceFill(['current_stock' => 0])->save();\n}", 'model Product'],
            'a model bound to the container' => ["app(Product::class)->newQuery()->where('id', 1)->update(['current_stock' => 0]);", 'model Product'],
            'a connection helper' => ["DB::connection('mysql')->table('suppliers')->where('id', 1)->delete();", 'suppliers'],
            'an update written as a join update' => ["DB::table('inventory_cost_layers as layer')->join('products as p', 'p.id', '=', 'layer.product_id')->update(['p.current_stock' => 0]);", 'products'],
        ];
    }

    #[DataProvider('writesThatHidBehindTheFirstCheck')]
    public function test_the_check_names_the_platform_table_in_every_form_the_first_version_of_it_was_blind_to(string $code, string $table): void
    {
        $this->assertContains($table, $this->writesIn($code), 'Not seen: ' . str_replace("\n", ' / ', $code));
    }

    /** @param list<string> $expected */
    #[DataProvider('writes')]
    public function test_the_check_sees_a_write_in_each_of_the_ways_the_platform_could_be_written(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->writesIn($code));
    }

    public function test_the_check_reads_the_modules_it_claims_to_and_the_files_on_its_list_really_make_the_writes(): void
    {
        $files = array_map(fn (string $file): string => $this->relative($file), $this->moduleFiles());

        $this->assertContains('Services/OperationalCosts/CostLedger.php', $files);
        $this->assertContains('Services/OperationalCosts/PurchaseOrders/PlatformProcurement.php', $files);
        $this->assertContains('Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php', $files);
        $this->assertContains('Console/Commands/CostsLayersFromPurchaseOrdersCommand.php', $files);
        $this->assertGreaterThan(300, count($files));
        $this->assertContains('products', $this->tablesRead($this->code(app_path('Services/OperationalCosts/PurchaseOrders/PlatformProcurement.php'))), 'The platform\'s tables are read, in the one place that reads the purchase orders.');
        $this->assertContains('inventory_cost_layers', $this->moduleTables());
        $this->assertNotContains('orders', $this->moduleTables());
    }

    public function test_the_check_reads_every_file_the_modules_own_wherever_it_lives_not_only_the_ones_in_their_directories(): void
    {
        $files = array_map(fn (string $file): string => $this->relative($file), $this->moduleFiles());

        $this->assertSame([], array_values(array_diff(self::FILES_OUTSIDE_THE_MODULE_DIRECTORIES, $files)), 'A file of the modules is not read by the check: it could write a table of the platform unseen.');
        foreach (EnsureSalesChannelsMigrated::MIGRATIONS as $migration) {
            $this->assertContains("database/migrations/{$migration}.php", $files);
        }
        $this->assertContains('routes/admin/operational-costs.php', $files);
        $this->assertTrue(count(array_filter($files, static fn (string $file): bool => str_starts_with($file, 'resources/views/admin-views/operational-costs/'))) > 20, 'The views of the modules are read too.');
    }

    public function test_no_migration_of_the_modules_names_a_table_of_the_platform(): void
    {
        $platform = [...self::NEVER_WRITTEN, ...self::PLATFORM_TABLES_MIGRATIONS_NEVER_NAME];
        foreach (EnsureSalesChannelsMigrated::MIGRATIONS as $migration) {
            preg_match_all("/'([a-z][a-z0-9_]*)'/", $this->code(database_path("migrations/{$migration}.php")), $literals);

            $this->assertSame([], array_values(array_intersect(array_unique($literals[1]), $platform)), "{$migration} names a table of the platform: a migration of the modules makes and changes their own tables and nothing else.");
        }
    }

    /**
     * Every file the modules own: their directories, the files under app/ whose names say they are theirs, their migrations, routes and views.
     *
     * @return list<string>
     */
    private function moduleFiles(): array
    {
        if (self::$moduleFiles !== null) {
            return self::$moduleFiles;
        }
        $files = [];
        foreach (self::MODULE_DIRECTORIES as $directory) {
            array_push($files, ...$this->phpFilesUnder(base_path($directory)));
        }
        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (preg_match(self::MODULE_FILE_NAMES, basename($file)) === 1) {
                $files[] = $file;
            }
        }
        foreach (EnsureSalesChannelsMigrated::MIGRATIONS as $migration) {
            $files[] = database_path("migrations/{$migration}.php");
        }
        foreach (self::MODULE_FILES_BY_PATH as $path) {
            $files[] = base_path($path);
        }

        $files = array_values(array_unique($files));
        sort($files);

        return self::$moduleFiles = $files;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function isMigration(string $file): bool
    {
        return str_starts_with($file, database_path('migrations'));
    }

    /** @return list<string> the tables the modules create themselves (the ones their migrations make) */
    private function moduleTables(): array
    {
        $tables = [];
        foreach (EnsureSalesChannelsMigrated::MIGRATIONS as $migration) {
            preg_match_all("/Schema::create\\('([a-z0-9_]+)'/", (string) file_get_contents(database_path("migrations/{$migration}.php")), $created);
            array_push($tables, ...$created[1]);
        }

        return array_values(array_unique($tables));
    }

    /** The path under app/ for a file there, and under the project for any other. */
    private function relative(string $file): string
    {
        return str_replace([app_path() . '/', base_path() . '/'], '', $file);
    }

    /** The file's code without its comments (a sentence that names a table in a comment is not a write). */
    private function code(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * Every table (or platform model) this code writes, whatever the way.
     *
     * @return list<string> sorted
     */
    private function writesIn(string $code): array
    {
        return (new PlatformWriteScanner(self::PLATFORM_MODELS))->writesIn($code);
    }

    /** @return list<string> the platform's models a piece of code names, in an import or a static call or a `new`, sorted */
    private function platformModelsIn(string $code): array
    {
        $models = implode('|', self::PLATFORM_MODELS);
        preg_match_all('/use\s+App\\\\Models\\\\(' . $models . ');|\b(' . $models . ')\s*::|new\s+(' . $models . ')\b/', $code, $found);
        $named = array_values(array_unique(array_filter([...$found[1], ...$found[2], ...$found[3]])));
        sort($named);

        return $named;
    }

    /** @return list<string> the tables a piece of code names in a query builder call, written or not */
    private function tablesRead(string $code): array
    {
        preg_match_all('/(?:DB::table|->table)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $code, $found);

        return array_values(array_unique($found[1]));
    }
}
