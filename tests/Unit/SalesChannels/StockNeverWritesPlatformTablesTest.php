<?php

namespace Tests\Unit\SalesChannels;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The shared-stock code reads the storefront's stock and the platform's tables and writes none of them: the storefront's `current_stock` and variants, the stock ledger, the
 * warehouses, purchasing and suppliers belong to the platform. Checked in the source of the modules (code, not comments), because a write there would only show as a wrong stock count
 * in production. A lint cannot prove a file is clean, so it is strict instead: the ways to write that it cannot judge (raw SQL, a connection object, a table or a model chosen at
 * run time) are not allowed at all, and the few files that need one are named below with the reason.
 */
class StockNeverWritesPlatformTablesTest extends TestCase
{
    private const PLATFORM_TABLES = 'products|stock_movements|warehouse_stock|warehouses|suppliers|purchase_orders|purchase_order_items|product_batches';

    private const WRITE_VERBS = 'insert|insertOrIgnore|insertGetId|upsert|update|updateOrInsert|delete|truncate|increment|decrement';

    private const RAW_WRITE_VERBS = 'update|insert|delete|replace|truncate|alter|drop';

    /**
     * The dispatcher makes the internal order of a channel order, and a line of an order has a `variation` column of its own (the JSON text of the chosen options), which is not the product's
     * `variation` the check looks for. It is the one writer allowed to name that key; the product's stock and variants stay off limits to it like everyone else.
     */
    private const MAY_NAME_AN_ORDER_LINES_VARIATION = ['app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php'];

    /**
     * Files that choose the table of a write at run time. Each takes its table from a list of its own (the fulfilment's log and sync-state tables); the check also requires that no platform
     * table is named anywhere in them.
     */
    private const MAY_WRITE_A_TABLE_CHOSEN_AT_RUN_TIME = ['app/Services/SalesChannels/Dispatch/BuyerEchoScrubber.php'];

    /** Files that use the platform's product model, to read names and codes; the check requires that they never write through it. */
    private const MAY_READ_THE_PRODUCT_MODEL = [
        'app/Http/Controllers/Admin/SalesChannels/Support/ProductDirectory.php',
        'app/Http/Controllers/Admin/SalesChannels/Support/RuleSummary.php',
    ];

    public function test_no_module_file_writes_a_platform_table(): void
    {
        $offenders = [];
        foreach ($this->moduleFiles() as $file) {
            $relative = str_replace(base_path() . '/', '', $file);
            $reasons = $this->reasonsToRefuse((string) file_get_contents($file), $relative);
            if ($reasons !== []) {
                $offenders[$relative] = $reasons;
            }
        }

        $this->assertSame([], $offenders, 'These files write a table that belongs to the platform, use the code that does, or write in a way this check cannot judge.');
    }

    public function test_the_check_sees_what_it_claims_to_and_would_catch_a_write(): void
    {
        $files = $this->moduleFiles();

        $this->assertContains(app_path('Services/SalesChannels/Inventory/LocationStockReader.php'), $files, 'the reader of the storefront\'s stock is under the check');
        $this->assertContains(app_path('Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php'), $files);
        $this->assertContains(app_path('Console/Commands/SalesChannelsStockUsePlatformForOwnWarehouseCommand.php'), $files);
        $this->assertSame([], $this->reasonsToRefuse("<?php\nDB::table('products')->where('id', 1)->value('variation');\n", 'app/Services/SalesChannels/Clean.php'), 'reading is fine');
        $this->assertEqualsCanonicalizing(['writes a platform table', 'names the product stock in a write'], $this->reasonsToRefuse("<?php\nDB::table('products')->where('id', 1)->decrement('current_stock');\n", 'app/Services/SalesChannels/Bad.php'));
        $this->assertContains('names the product stock in a write', $this->reasonsToRefuse("<?php\nDB::table('x')->update(['current_stock' => 3]);\n", 'app/Services/SalesChannels/Bad.php'));
        $this->assertSame([], $this->reasonsToRefuse("<?php\n// DB::statement('update products set current_stock = 0');\n/** DB::connection() is not allowed */\n", 'app/Services/SalesChannels/Clean.php'), 'a comment is not code');
    }

    public function test_every_way_of_hiding_a_write_in_the_fixture_is_reported(): void
    {
        $fixture = (string) file_get_contents(base_path('tests/Support/Fixtures/StockWriteEvasions.php.txt'));

        $reasons = $this->reasonsToRefuse($fixture, 'app/Services/SalesChannels/Inventory/StockWriteEvasions.php');

        $this->assertEqualsCanonicalizing([
            'names the product stock in a write',
            'writes with raw SQL',
            'names a platform table in a SQL write',
            'opens a connection of its own',
            'chooses the table of a write at run time',
            'uses the platform\'s product model',
        ], $reasons);
    }

    public function test_the_allowed_files_are_still_the_only_ones_and_still_clean_in_what_they_were_allowed_for(): void
    {
        foreach (self::MAY_WRITE_A_TABLE_CHOSEN_AT_RUN_TIME as $relative) {
            $this->assertSame(0, preg_match('/\b(' . self::PLATFORM_TABLES . ')\b/', $this->codeOf((string) file_get_contents(base_path($relative)))), "{$relative} names a platform table");
        }
        foreach (self::MAY_READ_THE_PRODUCT_MODEL as $relative) {
            $this->assertSame(0, preg_match('/->(save|update|delete|forceDelete|increment|decrement|insert|upsert|fill|forceFill|create|firstOrCreate|updateOrCreate)\(/', $this->codeOf((string) file_get_contents(base_path($relative)))), "{$relative} writes through the product model");
        }
    }

    /**
     * Why a file's source is refused, in words; nothing when it is clean.
     *
     * @return list<string>
     */
    private function reasonsToRefuse(string $source, string $relative): array
    {
        $code = $this->codeOf($source);
        $reasons = [];

        if (preg_match('/DB::table\(\s*[\'"](' . self::PLATFORM_TABLES . ')[\'"]\s*\)(?:(?!;).)*->(' . self::WRITE_VERBS . ')\(/s', $code) === 1) {
            $reasons[] = 'writes a platform table';
        }
        $namesVariation = !in_array($relative, self::MAY_NAME_AN_ORDER_LINES_VARIATION, true) && preg_match('/[\'"]variation[\'"]\s*=>/', $code) === 1;
        if ($namesVariation || preg_match('/[\'"]current_stock[\'"]\s*=>|->(increment|decrement)\(\s*[\'"]current_stock[\'"]/', $code) === 1) {
            $reasons[] = 'names the product stock in a write';
        }
        if (preg_match('/\b(StockMovement|WarehouseStock|PurchaseOrder|InventoryService|WarehouseService|PurchaseOrderService)\b/', $code) === 1) {
            $reasons[] = 'uses the code that writes the ledger';
        }
        if (preg_match('/\bDB::(update|insert|delete|statement|unprepared|affectingStatement)\s*\(/', $code) === 1) {
            $reasons[] = 'writes with raw SQL';
        }
        if ($this->aStringNamesAPlatformTableInAWrite($source)) {
            $reasons[] = 'names a platform table in a SQL write';
        }
        if (preg_match('/\bDB::(connection|getPdo|getDoctrineConnection)\s*\(|->(getConnection|getPdo)\s*\(/', $code) === 1) {
            $reasons[] = 'opens a connection of its own';
        }
        if (!in_array($relative, self::MAY_WRITE_A_TABLE_CHOSEN_AT_RUN_TIME, true) && preg_match('/DB::table\(\s*(?![\'"])/', $code) === 1) {
            $reasons[] = 'chooses the table of a write at run time';
        }
        if (!in_array($relative, self::MAY_READ_THE_PRODUCT_MODEL, true) && preg_match('/\bProduct::|\bnew\s+Product\b|use\s+App\\\\Models\\\\Product\s*;/', $code) === 1) {
            $reasons[] = 'uses the platform\'s product model';
        }

        return $reasons;
    }

    /** Does any string of the code say, in SQL, that a platform table is written? Comments and the words around the code do not count. */
    private function aStringNamesAPlatformTableInAWrite(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/\b(' . self::RAW_WRITE_VERBS . ')\b[^;]*\b(' . self::PLATFORM_TABLES . ')\b/i', $token[1]) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The source without its comments: what the check looks at, so that a sentence about a write is not mistaken for one. */
    private function codeOf(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
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
