<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per first-order referral reward paid, keyed by (referee, beneficiary).
 *
 * The unique index is the exactly-once authority: the row is inserted in the same transaction as the wallet credit,
 * and a second writer, a repeated delivery or a concurrent request for the same referee finds the key taken and credits
 * nothing. Additive only: no foreign keys, no backfill, and no change to wallet_transactions, users or referral_customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referral_reward_grants')) {
            return;
        }

        Schema::create('referral_reward_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('referee_id');
            $table->unsignedBigInteger('beneficiary_id');
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['referee_id', 'beneficiary_id'], 'referral_reward_grants_referee_beneficiary_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_reward_grants');
    }
};
