<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The register of gateway confirmations: one row for each transaction a gateway has told the shop about, naming the payment it
 * settled.
 *
 * The unique key over the gateway and its own reference for the transaction is the whole point. A confirmation a gateway sends
 * to the shop can be presented again -- the address the customer is returned to carries it -- and nothing stopped one
 * confirmation from settling one payment after another. The row is written in the same transaction as the settlement, so the
 * first presentation settles and every later one finds the reference taken (App\Services\Payments\GatewayConfirmations).
 *
 * Until this migration has run, a gateway's confirmation is still checked against the payment it names (the amount and the
 * currency it carries must be that payment's), but one confirmation is not yet held to one payment.
 */
return new class extends Migration {
    private const TABLE = 'gateway_confirmations';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->id();
            $table->string('gateway', 32);
            $table->string('reference', 191);
            $table->char('payment_request_id', 36);
            $table->timestamp('created_at')->nullable();
            $table->unique(['gateway', 'reference']);
        });
    }

    /** Dropping the register forgets which confirmations were used: each of them could then settle another payment. */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
