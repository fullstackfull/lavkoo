<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fencing token for the success-hook lease.
 *
 * The lease alone stops two processes STARTING the hook together. It does not stop a lease from expiring
 * while its holder is still working: a hook that creates an order, decrements stock and books a shipment
 * can outlive a ten-minute window on a slow day, and then the recovery sweep takes the lease and runs it
 * again while the first process is still inside it.
 *
 * For an order that is survivable, because the second run finds an order already carrying the payment's
 * reference and stops. A wallet top-up has no such mark — `add_fund_to_wallet_success` credits the
 * balance with no idempotency key — so a second run credits it twice.
 *
 * The token makes the overrun detectable. Each lease is stamped with one; a process may only record
 * completion while its own token is still on the row. A process that finds someone else's token knows it
 * lost the lease mid-flight and says so loudly instead of overwriting the winner's result.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('myfatoorah_payment_attempts')) {
            return;
        }

        if (Schema::hasColumn('myfatoorah_payment_attempts', 'hook_lease_token')) {
            return;
        }

        Schema::table('myfatoorah_payment_attempts', function (Blueprint $table): void {
            $table->string('hook_lease_token', 40)->nullable()->after('hook_started_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('myfatoorah_payment_attempts') && Schema::hasColumn('myfatoorah_payment_attempts', 'hook_lease_token')) {
            Schema::table('myfatoorah_payment_attempts', function (Blueprint $table): void {
                $table->dropColumn('hook_lease_token');
            });
        }
    }
};
