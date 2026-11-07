<?php

namespace Tests\Unit\OperationalCosts;

use App\Http\Middleware\EnsureSalesChannelsMigrated;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The platform's tables stay the platform's. The cost and sales-channel modules read them; the commission, wallet and settlement tables they do not touch at all, and the
 * procurement, stock and catalogue tables they never write. It is checked in the source (like SingleHttpExitTest checks the one way out of the server): every write into a table
 * the modules do not own has to be on the list below, with the file that makes it.
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
     * routing rule's summary, whether a seller exists) and writes nothing.
     *
     * @var array<string, list<string>>
     */
    private const MODELS_OF_THE_PLATFORM_READ = [
        'Http/Controllers/Admin/SalesChannels/Support/AccountForm.php' => ['Seller'],
        'Http/Controllers/Admin/SalesChannels/Support/ProductDirectory.php' => ['Product'],
        'Http/Controllers/Admin/SalesChannels/Support/RuleSummary.php' => ['Product'],
        'Services/OperationalCosts/InventoryCostLayerService.php' => ['Product'],
        'Services/OperationalCosts/Shipments/ShipmentFactsFactory.php' => ['Order'],
    ];

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
            $outside = array_values(array_diff($this->writesIn($this->code($file)), $this->moduleTables()));
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
        ];
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
        $this->assertGreaterThan(150, count($files));
        $this->assertContains('products', $this->tablesRead($this->code(app_path('Services/OperationalCosts/PurchaseOrders/PlatformProcurement.php'))), 'The platform\'s tables are read, in the one place that reads the purchase orders.');
        $this->assertContains('inventory_cost_layers', $this->moduleTables());
        $this->assertNotContains('orders', $this->moduleTables());
    }

    /** @return list<string> */
    private function moduleFiles(): array
    {
        $files = [];
        foreach (['Services/SalesChannels', 'Services/OperationalCosts', 'Http/Controllers/Admin/SalesChannels', 'Http/Controllers/Admin/OperationalCosts', 'Http/Requests/Admin/OperationalCosts'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($directory), FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return array_values(array_unique([
            ...$files,
            app_path('Http/Controllers/Api/TrendyolWebhookController.php'),
            ...glob(app_path('Console/Commands/SalesChannels*Command.php')),
            ...glob(app_path('Console/Commands/Costs*Command.php')),
        ]));
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

    private function relative(string $file): string
    {
        return str_replace(app_path() . '/', '', $file);
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
     * Every table (or platform model) a statement of this code writes.
     *
     * @return list<string> sorted
     */
    private function writesIn(string $code): array
    {
        $written = [];
        foreach (explode(';', $code) as $statement) {
            if (preg_match('/(?:DB::table|->table)\(\s*(?:[\'"]([a-z0-9_]+)[\'"]|(\$))/', $statement, $table) === 1
                && preg_match('/->(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach)\(/', $statement) === 1) {
                $written[] = ($table[1] ?? '') !== '' ? $table[1] : '(a table named in a variable)';
            }
            if (preg_match('/new\s+(' . implode('|', self::PLATFORM_MODELS) . ')\b/', $statement, $new) === 1) {
                $written[] = 'model ' . $new[1];
            } elseif (preg_match('/\b(' . implode('|', self::PLATFORM_MODELS) . ')\s*::/', $statement, $model) === 1
                && preg_match('/(->|::)(create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|forceFill)\(/', $statement) === 1) {
                $written[] = 'model ' . $model[1];
            }
            if (preg_match('/DB::(?:statement|unprepared|insert|update|delete)\(|->(?:statement|unprepared)\(/', $statement) === 1
                && preg_match('/\b(?:insert\s+into|replace\s+into|update|delete\s+from|truncate\s+table|alter\s+table|drop\s+table)\s+[`"\']?([a-z0-9_]+)/i', $statement, $sql) === 1) {
                $written[] = strtolower($sql[1]);
            }
        }
        $written = array_values(array_unique($written));
        sort($written);

        return $written;
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
