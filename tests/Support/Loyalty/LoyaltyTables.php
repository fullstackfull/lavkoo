<?php

namespace Tests\Support\Loyalty;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Wallet\WalletTables;

/**
 * The loyalty-point ledger and the loyalty settings the point writers read. The customer's balance column lives in the users table
 * that WalletTables builds.
 */
final class LoyaltyTables
{
    public static function create(): void
    {
        WalletTables::guardTestDatabase();

        Schema::create('loyalty_point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->string('transaction_type', 191)->nullable();
            $table->string('reference', 191)->nullable();
            $table->decimal('balance', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->decimal('debit', 18, 4)->default(0);
            $table->timestamps();
        });
    }

    public static function drop(): void
    {
        WalletTables::guardTestDatabase();

        Schema::dropIfExists('loyalty_point_transactions');
    }

    public static function enableLoyaltyPoints(): void
    {
        WalletTables::setSetting('loyalty_point_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        WalletTables::setSetting('loyalty_point_item_purchase_point', '10');
    }

    public static function loyaltyPointsOf(int $customerId): float
    {
        return (float) DB::table('users')->where('id', $customerId)->value('loyalty_point');
    }
}
