<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateOrderTaxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('order_taxes', function (Blueprint $table) {
            $table->id();
            $table->string('tax_name');
            $table->string('tax_type');
            $table->string('tax_on');
            $table->double('tax_rate', 23, 8)->default(0);
            $table->double('tax_amount', 23, 8)->default(0);
            $table->double('before_tax_amount', 23, 8)->default(0);
            $table->double('after_tax_amount', 23, 8)->default(0);
            $table->string('tax_payer')->nullable();
            $table->integer('order_id')->nullable();
            $table->string('order_type')->nullable();
            $table->integer('quantity')->default(1)->nullable();
            $table->integer('tax_id');
            $table->integer('taxable_id')->nullable();
            $table->string('taxable_type')->nullable();
            $table->integer('seller_id')->nullable();
            $table->string('seller_type')->nullable();
            $table->integer('system_tax_setup_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('order_taxes');
    }
}
