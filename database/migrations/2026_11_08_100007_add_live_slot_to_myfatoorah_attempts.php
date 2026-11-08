<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "At most one LIVE payment attempt per order", as a unique index.
 *
 * An attempt is live from the moment it is created until it reaches a terminal state — paid, failed,
 * cancelled, expired or flagged. While one is live, a second must not be started for the same order:
 * either the customer is looking at its payment page (so they should be sent back to it) or its create
 * call is unaccounted for (so we cannot know whether MyFatoorah already has an invoice). Both end the
 * same way — one order, one invoice at a time.
 *
 * `live_slot` holds 1 while the attempt is live and NULL once it is not. MySQL and MariaDB ignore NULLs
 * in unique indexes, so any number of finished attempts coexist while a second live one is refused by
 * the database.
 *
 * Why not locks. Two earlier versions used them and the MySQL concurrency test broke both: a plain read
 * inside the transaction could not see a sibling's just-committed attempt, because REPEATABLE READ gives
 * the transaction a snapshot from when it began; and making that read a locking one took gap locks on
 * the `payment_request_id` range, which made *different* orders block each other.
 *
 * Why the slot is not released as soon as the payment page arrives. It was, in the first draft, and the
 * window it protected then lasted only as long as the create call — microseconds against a fast
 * provider. Six simultaneous checkouts each slipped through it and made their own invoice. Correctness
 * must not depend on how slow the provider happens to be.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('myfatoorah_payment_attempts')) {
            return;
        }

        if (Schema::hasColumn('myfatoorah_payment_attempts', 'live_slot')) {
            return;
        }

        Schema::table('myfatoorah_payment_attempts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('live_slot')->nullable()->after('status');
            $table->unique(['payment_request_id', 'live_slot'], 'mf_attempts_one_live_per_request');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('myfatoorah_payment_attempts') || !Schema::hasColumn('myfatoorah_payment_attempts', 'live_slot')) {
            return;
        }

        Schema::table('myfatoorah_payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('mf_attempts_one_live_per_request');
            $table->dropColumn('live_slot');
        });
    }
};
