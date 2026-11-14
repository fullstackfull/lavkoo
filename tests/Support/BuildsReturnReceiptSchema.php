<?php

namespace Tests\Support;

use App\Models\ReturnShipment;
use App\Services\Marketplace\ReturnLogisticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables a return receipt reads and writes: `products`, `order_details`, `return_shipments` and `stock_movements`.
 *
 * `return_shipments` and `stock_movements` have no migration in this repository (they come from the installation dump), so their
 * columns here are what `ReturnShipment::$fillable` and `InventoryService::record()` write, nothing more. The tables are dropped
 * before and after, because on MySQL they outlive the test process.
 */
trait BuildsReturnReceiptSchema
{
    private const RECEIPT_TABLES = ['return_shipments', 'stock_movements', 'order_details', 'products'];

    /** @var array<string, string> */
    private array $languageFileHashes = [];

    protected function buildReturnReceiptSchema(): void
    {
        $this->languageFileHashes = $this->hashLanguageFiles();
        $this->dropReturnReceiptSchema();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->integer('current_stock')->default(0);
            $table->text('variation')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('variant')->nullable();
            $table->boolean('is_stock_decreased')->default(1);
            $table->string('delivery_status')->nullable();
            $table->timestamps();
        });

        Schema::create('return_shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_request_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->string('reason')->nullable();
            $table->string('status', 20)->default(ReturnShipment::STATUS_AUTHORIZED);
            $table->boolean('restock')->default(1);
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('type', 24);
            $table->integer('qty_change');
            $table->integer('balance_after')->nullable();
            $table->string('reason', 40)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_type', 24)->nullable();
            $table->timestamps();
        });
    }

    protected function dropReturnReceiptSchema(): void
    {
        foreach (self::RECEIPT_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * One product, one order line of it and an authorised return of part (or all) of that line.
     *
     * @param array<array-key, array{type: string, qty: int|string}>|null $variations
     */
    protected function returnOf(int $lineQty, int $returnQty, int $stock, ?string $variant = null, ?array $variations = null): ReturnShipment
    {
        $productId = DB::table('products')->insertGetId([
            'current_stock' => $stock,
            'variation' => $variations === null ? null : json_encode($variations),
        ]);
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => 1,
            'product_id' => $productId,
            'qty' => $lineQty,
            'variant' => $variant,
            'is_stock_decreased' => 1,
        ]);

        return app(ReturnLogisticsService::class)->authorize(
            data: ['order_id' => 1, 'order_details_id' => $lineId, 'product_id' => $productId, 'seller_id' => 7, 'qty' => $returnQty, 'restock' => true],
            by: 1,
        );
    }

    protected function assertLanguageFilesUnchanged(): void
    {
        $this->assertSame($this->languageFileHashes, $this->hashLanguageFiles(), 'resources/lang was written to');
    }

    /** @return array<string, string> */
    private function hashLanguageFiles(): array
    {
        $hashes = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $path) {
            $hashes[$path] = (string) md5_file($path);
        }

        return $hashes;
    }
}
