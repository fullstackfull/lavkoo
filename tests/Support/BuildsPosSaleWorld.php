<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller app's point-of-sale world: two products in stock for one seller, a customer with 100 in the wallet, and the wallet and sale-key
 * tables a sale writes. Its rows are committed, so a concurrency worker process sees the same store.
 */
trait BuildsPosSaleWorld
{
    protected function buildPosSaleWorld(int $sellerId): User
    {
        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        Schema::create('pos_sale_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('idempotency_key', 191);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'idempotency_key']);
        });
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => $sellerId, 'unit_price' => 30, 'current_stock' => 10],
            ['id' => 2, 'name' => 'Vitamin C', 'added_by' => 'seller', 'user_id' => $sellerId, 'unit_price' => 20, 'current_stock' => 10],
        ]);

        return $this->makeCustomer(['wallet_balance' => 100]);
    }
}
