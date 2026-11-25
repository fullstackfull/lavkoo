<?php

namespace Tests\Support;

use App\Models\ReturnShipment;
use App\Services\Returns\CrossBorderReturn;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two tables a return writes that have NO migration in this repository: `return_shipments` and `stock_movements`. Both come from the installation
 * dump, so a test that exercises a return has to build them itself.
 *
 * One definition, used by every such test, because two drifting copies of a dump table is how a test comes to pass against a shape the dump does not
 * have. The columns are what `ReturnShipment::$fillable` and `InventoryService::record()` write, plus the three a migrated database carries for the market
 * and the money of a cross-border return (`App\Services\Returns\CrossBorderReturn`) -- all nullable, written only while that mechanism is on.
 */
trait BuildsReturnDumpTables
{
    protected const RETURN_DUMP_TABLES = ['return_shipments', 'stock_movements'];

    protected function createReturnDumpTables(): void
    {
        $this->dropReturnDumpTables();

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
            $table->char(CrossBorderReturn::SOURCE_COLUMN, 2)->nullable();
            $table->string(CrossBorderReturn::CURRENCY_COLUMN, 8)->nullable();
            $table->unsignedBigInteger(CrossBorderReturn::ACCOUNT_COLUMN)->nullable();
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

    protected function dropReturnDumpTables(): void
    {
        foreach (self::RETURN_DUMP_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
