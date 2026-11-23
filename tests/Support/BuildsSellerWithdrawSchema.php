<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The minimal tables the seller withdraw paths read and write, shared by the SQLite feature tests and the MySQL concurrency tests.
 *
 * On a real server the tables are dropped and rebuilt first, and only in a database whose name says it is a test database.
 */
trait BuildsSellerWithdrawSchema
{
    private const SELLER_WITHDRAW_TABLES = ['business_settings', 'currencies', 'sellers', 'seller_wallets', 'withdraw_requests', 'withdrawal_methods'];

    protected function buildSellerWithdrawSchema(): void
    {
        $this->dropSellerWithdrawTables();

        Schema::create('business_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code');
            $table->decimal('exchange_rate', 16, 6)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('sellers', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('seller_wallets', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 16, 2)->default(0);
            $table->decimal('withdrawn', 16, 2)->default(0);
            $table->decimal('commission_given', 16, 2)->default(0);
            $table->decimal('pending_withdraw', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('withdraw_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->unsignedBigInteger('withdrawal_method_id')->nullable();
            $table->text('withdrawal_method_fields')->nullable();
            $table->string('transaction_note')->nullable();
            $table->unsignedTinyInteger('approved')->default(0);
            $table->string('request_method_type')->nullable();
            $table->timestamps();
        });

        Schema::create('withdrawal_methods', static function (Blueprint $table): void {
            $table->id();
            $table->string('method_name');
            $table->text('method_fields')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('vendor_status')->default(true);
            $table->timestamps();
        });
    }

    protected function dropSellerWithdrawTables(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }

        foreach (self::SELLER_WITHDRAW_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
}
