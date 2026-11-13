<?php

namespace Tests\Support\Wallet;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The tables the customer wallet writers read and write, with the columns the wallet code uses.
 *
 * Changing them is refused on a non-SQLite connection unless the database name says it is a test database and APP_ENV is
 * testing, the same guard the address and MyFatoorah schema helpers use. A run pointed at a real database cannot drop its customers.
 */
final class WalletTables
{
    public static function create(): void
    {
        self::guardTestDatabase();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->decimal('wallet_balance', 18, 12)->default(0);
            $table->decimal('loyalty_point', 18, 4)->nullable()->default(0);
            $table->timestamps();
        });
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
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('add_fund_bonus_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('bonus_type', 50);
            $table->float('bonus_amount', 14, 2)->default(0);
            $table->float('min_add_money_amount', 14, 2)->default(0);
            $table->float('max_bonus_amount', 14, 2)->default(0);
            $table->dateTime('start_date_time')->nullable();
            $table->dateTime('end_date_time')->nullable();
            $table->boolean('is_active')->default(0);
            $table->timestamps();
        });
    }

    public static function drop(): void
    {
        self::guardTestDatabase();

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('add_fund_bonus_categories');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('users');
    }

    public static function seedCustomer(int $customerId, float $walletBalance): void
    {
        DB::table('users')->insert([
            'id' => $customerId,
            'wallet_balance' => $walletBalance,
            'loyalty_point' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function setSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => $type],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public static function walletBalance(int $customerId): float
    {
        return (float) DB::table('users')->where('id', $customerId)->value('wallet_balance');
    }

    private static function guardTestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to change the wallet tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }
}
