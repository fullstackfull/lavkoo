<?php

namespace Tests\Support;

use App\Http\Controllers\Admin\Product\ProductController;
use App\Services\ProductService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rap2hpoutre\FastExcel\FastExcel;

/**
 * The admin bulk import of products, driven through the controller with a sheet written for the test. The test class builds the product table first
 * (BuildsCodeScopeCatalog) and calls createImportTables() in its set-up; generateTheseCodes() decides the codes the import generates.
 */
trait RunsBulkImport
{
    private function createImportTables(): void
    {
        foreach (['shops', 'system_tax_setups', 'taxes', 'taxables'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
        });
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);
        // Guarded: more than one world builder creates this table, and a test that builds two of them raised "table already exists" before a single assertion ran.
        if (!Schema::hasTable('system_tax_setups')) {
            Schema::create('system_tax_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(true);
            });
        }
        DB::table('system_tax_setups')->insert(['id' => 1]);
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
        });
        DB::table('taxes')->insert([['id' => 1], ['id' => 2], ['id' => 9]]);
        Schema::create('taxables', function (Blueprint $table) {
            $table->id();
            $table->string('taxable_type');
            $table->unsignedBigInteger('taxable_id');
            $table->unsignedBigInteger('system_tax_setup_id')->nullable();
            $table->unsignedBigInteger('tax_id');
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });
    }

    /** @param list<string> $taxIds one entry per sheet row, such as '1,2' */
    private function importRequest(array $taxIds): Request
    {
        $rows = array_map(static fn (string $taxes, int $index): array => [
            'name' => 'Imported ' . ($index + 1), 'category_id' => 1, 'sub_category_id' => 2, 'sub_sub_category_id' => 3, 'brand_id' => 4, 'unit' => 'pc',
            'minimum_order_qty' => 1, 'status' => 1, 'refundable' => 0, 'youtube_video_url' => 'u', 'unit_price' => 10, 'tax_ids' => $taxes, 'discount' => 0,
            'discount_type' => 'flat', 'current_stock' => 5, 'details' => 'd', 'thumbnail' => 'p/t.webp',
        ], $taxIds, array_keys($taxIds));
        $path = sys_get_temp_dir() . '/bulk_import_' . getmypid() . '.xlsx';
        (new FastExcel(collect($rows)))->export($path);

        return Request::create('/admin/products/bulk-import', 'POST', [], [], ['products_file' => new UploadedFile($path, 'products.xlsx', null, null, true)]);
    }

    /**
     * The random strings of six letters the import asks for are, in turn, the generated code of a row and the tail of its slug: the codes are
     * the given ones, in order, and the slug tails and every other random string are filler.
     *
     * @param list<string> $codes
     */
    private function generateTheseCodes(array $codes): void
    {
        $asked = 0;
        Str::createRandomStringsUsing(static function (int $length) use ($codes, &$asked): string {
            if ($length !== 6) {
                return str_repeat('r', $length);
            }

            return $asked++ % 2 === 0 ? ($codes[intdiv($asked - 1, 2)] ?? 'zzzzzz') : 'slugtl';
        });
    }

    private function runImport(array $taxIds): void
    {
        app(ProductController::class)->importBulkProduct($this->importRequest($taxIds), app(ProductService::class));
    }

    /** @return list<int> */
    private function taxesOf(int $productId): array
    {
        return DB::table('taxables')->where('taxable_id', $productId)->orderBy('tax_id')->pluck('tax_id')->map(fn ($id) => (int) $id)->all();
    }

    private function idOfImported(string $code): int
    {
        return (int) DB::table('products')->where('code', $code)->where('storefront_country', 'SA')->value('id');
    }
}
