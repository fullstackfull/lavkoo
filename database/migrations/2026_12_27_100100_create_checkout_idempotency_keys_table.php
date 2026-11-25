<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per `Idempotency-Key` a customer's checkout sends (D1-14).
 *
 * The key is claimed inside the order's own transaction, so a repeated submit finds the orders the first one placed and is answered with
 * them -- neither stocking, debiting nor charging again. A key whose order write rolled back is free to use, because the claim rolled
 * back with it.
 *
 * `order_ids` is a list, not one id: one submit of a basket holding two sellers' goods places one order per seller, and the repeat has to
 * be answered with all of them.
 *
 * Scoped to the submitter, so two customers who send the same key are two submits. The unique key is the whole mechanism.
 *
 * It arrives empty and nothing writes it until a client sends the header, which no app of this platform does yet
 * (App\Services\Checkout\OneOrderPerCheckoutSubmit).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('checkout_idempotency_keys')) {
            return;
        }

        Schema::create('checkout_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_scope', 64);
            $table->string('idempotency_key', 191);
            $table->text('order_ids')->nullable();
            $table->timestamps();

            // Short enough for MySQL's 64-character identifier limit, which a name Laravel builds from this table and these two
            // columns would be over (MigrationIdentifierLengthTest).
            $table->unique(['customer_scope', 'idempotency_key'], 'checkout_idem_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_idempotency_keys');
    }
};
