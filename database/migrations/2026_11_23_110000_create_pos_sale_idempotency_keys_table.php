<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Idempotency-Key a seller app sends with a point-of-sale sale. The key is claimed in the sale's own transaction, so a repeated
 * submit finds the order the first one placed and neither stocks nor debits the wallet again. A key whose sale rolled back is free to use.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pos_sale_idempotency_keys')) {
            return;
        }

        Schema::create('pos_sale_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('idempotency_key', 191);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_idempotency_keys');
    }
};
