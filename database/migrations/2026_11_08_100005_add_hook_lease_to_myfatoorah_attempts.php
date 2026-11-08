<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease on the success hook, so exactly one process runs it at a time.
 *
 * `settled_at` already guaranteed that only one caller flips the payment to paid. It did NOT guarantee
 * that only one caller runs the success hook: a second delivery arriving while the first is still inside
 * the hook found `settled_at` set and `hook_completed_at` still null, and went on to run the hook too.
 * The order-already-exists check narrowed that window but could not close it, because the hook creates
 * the order some way into its work — and `generateOrder` is not quick.
 *
 * `hook_started_at` closes it. The right to run the hook is taken with a conditional UPDATE, so one
 * caller gets it and the rest are told it is in progress. The lease goes stale after a few minutes so a
 * process that was killed mid-hook does not block recovery for ever.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('myfatoorah_payment_attempts')) {
            return;
        }

        if (Schema::hasColumn('myfatoorah_payment_attempts', 'hook_started_at')) {
            return;
        }

        Schema::table('myfatoorah_payment_attempts', function (Blueprint $table): void {
            $table->timestamp('hook_started_at')->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('myfatoorah_payment_attempts') && Schema::hasColumn('myfatoorah_payment_attempts', 'hook_started_at')) {
            Schema::table('myfatoorah_payment_attempts', function (Blueprint $table): void {
                $table->dropColumn('hook_started_at');
            });
        }
    }
};
