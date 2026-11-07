<?php

namespace Tests\Support;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Marketplace\PurchaseOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's procurement, warehouse and stock-ledger tables, INFERRED.
 *
 * `suppliers`, `purchase_orders`, `purchase_order_items`, `warehouses`, `warehouse_stock`, `stock_movements` and `product_batches` have no migration in this repository: they come from
 * the installation dump (the platform's own compatibility command says so). Their columns here are what the models (`$fillable`, `$casts`) and the services that write them
 * (`PurchaseOrderService`, `InventoryService`, `WarehouseService`) show, and nothing more: column types, lengths, defaults, nullability and indexes are guesses. They prove the
 * module's own logic against a plausible shape; they do NOT prove behaviour against the real tables, which has to be checked against a restored copy of the real database.
 *
 * Receipts in the tests go through the platform's own `PurchaseOrderService`, so a receipt writes exactly what the platform writes: the line's `qty_received`, the product's
 * `current_stock`, and one `stock_movements` row whose `reference_id` is the order (not the line).
 */
trait BuildsPlatformProcurementSchema
{
    protected function buildPlatformProcurementSchema(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('seller_id')->nullable()->index();
            $table->string('status', 24)->default('draft');
            $table->string('currency', 10)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->date('expected_at')->nullable();
            $table->dateTime('ordered_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('description')->nullable();
            $table->string('sku', 120)->nullable();
            $table->unsignedInteger('qty_ordered')->default(0);
            $table->unsignedInteger('qty_received')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
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
            $table->index(['product_id', 'created_at'], 'sm_product_time_idx');
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_stock', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id')->index('ws_product_idx');
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id']);
        });

        Schema::create('product_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('batch_number', 80)->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('initial_quantity')->default(0);
            $table->string('status', 20)->default('active');
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * A catalogue product as the platform stores it: physical, the platform's own, no variants, no provider, a recorded purchase price of zero (what its form writes).
     *
     * @param array<string, mixed> $attributes columns of `products` to set (variation as a list is stored as JSON)
     */
    protected function platformProduct(array $attributes = []): int
    {
        if (isset($attributes['variation']) && is_array($attributes['variation'])) {
            $attributes['variation'] = json_encode($attributes['variation']);
        }

        return (int) DB::table('products')->insertGetId($attributes + [
            'name' => 'Paracetamol 500mg ' . (DB::table('products')->count() + 1), 'code' => 'P-' . (DB::table('products')->count() + 1), 'added_by' => 'admin', 'product_type' => 'physical',
            'current_stock' => 0, 'purchase_price' => 0, 'variation' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function supplier(string $name = 'Acme Pharma Supply', ?int $sellerId = null): Supplier
    {
        return Supplier::create(['name' => $name, 'seller_id' => $sellerId, 'status' => Supplier::STATUS_ACTIVE]);
    }

    /**
     * A placed purchase order, made by the platform's own service. A line is [product id or null, ordered quantity, unit cost] with an optional fourth entry, a description.
     *
     * @param list<array{0: int|null, 1: int, 2: float|int|null, 3?: string}> $lines
     */
    protected function purchaseOrder(Supplier|int $supplier, array $lines, ?string $currency = 'SAR', ?int $sellerId = null, bool $place = true): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->create(
            supplierId: $supplier instanceof Supplier ? $supplier->id : $supplier,
            sellerId: $sellerId,
            attributes: ['currency' => $currency],
            items: array_map(static fn (array $line): array => ['product_id' => $line[0], 'qty_ordered' => $line[1], 'unit_cost' => $line[2] ?? 0, 'description' => $line[3] ?? null], $lines),
        );
        if ($place) {
            $service->place($order);
        }

        return $order->fresh(['items']);
    }

    /** Receives units on a line through the platform's own service, which is the only writer of what a receipt leaves behind. */
    protected function receiveOnPlatform(PurchaseOrderItem|int $line, int $quantity): PurchaseOrderItem
    {
        $item = $line instanceof PurchaseOrderItem ? $line : PurchaseOrderItem::findOrFail($line);
        $result = app(PurchaseOrderService::class)->receiveItem($item, $quantity);
        $this->assertTrue($result['ok'], 'The platform refused the receipt: ' . ($result['reason'] ?? ''));

        return $result['item'];
    }
}
