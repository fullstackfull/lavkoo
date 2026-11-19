<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The register of wallet top-ups: one row for each payment that was credited to a wallet, naming the customer and the ledger
 * row of the credit.
 *
 * The unique key on the payment is the whole point. A gateway may hand one confirmed payment to the top-up more than once, and
 * the row is written in the same transaction as the credit: whoever writes it first credits, and everybody after finds it
 * there (App\Services\Payments\WalletTopUpRegister). Until this migration has run the top-up credits as it always did.
 */
return new class extends Migration {
    private const TABLE = 'wallet_top_ups';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->char('payment_request_id', 36)->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /** Dropping the register forgets which payments were credited: each of them could then be credited again. */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
