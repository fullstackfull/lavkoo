<?php

namespace Tests\Unit\SalesChannels;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The shared-stock code reads the storefront's stock and the platform's tables and writes none of them: the storefront's `current_stock` and variants, the stock ledger, the
 * warehouses, purchasing and suppliers belong to the platform. Checked in the source of the modules, because a write there would only show as a wrong stock count in production.
 */
class StockNeverWritesPlatformTablesTest extends TestCase
{
    private const PLATFORM_TABLES = 'products|stock_movements|warehouse_stock|warehouses|suppliers|purchase_orders|purchase_order_items|product_batches';

    private const WRITE_VERBS = 'insert|insertOrIgnore|insertGetId|upsert|update|updateOrInsert|delete|truncate|increment|decrement';

    public function test_no_module_file_writes_a_platform_table(): void
    {
        $offenders = [];
        foreach ($this->moduleFiles() as $file) {
            $source = (string) file_get_contents($file);
            $writesATable = preg_match('/DB::table\(\s*[\'"](' . self::PLATFORM_TABLES . ')[\'"]\s*\)(?:(?!;).)*->(' . self::WRITE_VERBS . ')\(/s', $source) === 1;
            $writesTheStock = preg_match('/[\'"]current_stock[\'"]\s*=>|[\'"]variation[\'"]\s*=>|->(increment|decrement)\(\s*[\'"]current_stock[\'"]/', $source) === 1;
            $usesTheLedger = preg_match('/\b(StockMovement|WarehouseStock|PurchaseOrder|InventoryService|WarehouseService|PurchaseOrderService)\b/', $source) === 1;
            if ($writesATable || $writesTheStock || $usesTheLedger) {
                $offenders[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'These files write a table that belongs to the platform, or use the code that does.');
    }

    public function test_the_check_sees_what_it_claims_to_and_would_catch_a_write(): void
    {
        $files = $this->moduleFiles();

        $this->assertContains(app_path('Services/SalesChannels/Inventory/LocationStockReader.php'), $files, 'the reader of the storefront\'s stock is under the check');
        $this->assertContains(app_path('Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php'), $files);
        $this->assertContains(app_path('Console/Commands/SalesChannelsStockUsePlatformForOwnWarehouseCommand.php'), $files);
        $this->assertSame(1, preg_match('/DB::table\(\s*[\'"](' . self::PLATFORM_TABLES . ')[\'"]\s*\)(?:(?!;).)*->(' . self::WRITE_VERBS . ')\(/s', "DB::table('products')->where('id', 1)->decrement('current_stock');"));
        $this->assertSame(1, preg_match('/[\'"]current_stock[\'"]\s*=>/', "DB::table('x')->update(['current_stock' => 3]);"));
        $this->assertSame(0, preg_match('/DB::table\(\s*[\'"](' . self::PLATFORM_TABLES . ')[\'"]\s*\)(?:(?!;).)*->(' . self::WRITE_VERBS . ')\(/s', "DB::table('products')->where('id', 1)->value('variation');"));
    }

    /** @return list<string> */
    private function moduleFiles(): array
    {
        $files = [];
        foreach (['Services/SalesChannels', 'Http/Controllers/Admin/SalesChannels'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach (glob(app_path('Console/Commands/SalesChannels*.php')) ?: [] as $command) {
            $files[] = $command;
        }

        return $files;
    }
}
