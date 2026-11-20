<?php

namespace Tests\Support;

use App\Models\PaymentRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A shop in which a customer can pay money into the wallet: one currency, the wallet switched on, a customer, and a payment
 * that a gateway has confirmed and whose success hook is the wallet top-up.
 *
 * Every table here comes from the installed SQL dump, not from a migration, so each is built with the columns the top-up and
 * the return of a gateway read and write. The register of top-ups is the exception: it is installed from its real migration,
 * so a wrong migration fails a test.
 */
trait BuildsWalletTopUpWorld
{
    protected function buildWalletTopUpWorld(): void
    {
        $this->dropTheTablesOfAnEarlierTest();

        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->text('additional_data')->nullable();
            $table->text('payer_information')->nullable();
            $table->text('receiver_information')->nullable();
            $table->text('external_redirect_link')->nullable();
            $table->string('attribute')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('payment_platform')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });

        Schema::create('addon_settings', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code', 8);
            $table->decimal('exchange_rate', 24, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        // Read by the page a gateway sends the customer to after a payment, which looks for the orders of that payment.
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_ref')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('wallet_balance', 24, 6)->default(0);
            $table->decimal('loyalty_point', 24, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('transaction_id', 36);
            $table->decimal('credit', 24, 6)->default(0);
            $table->decimal('debit', 24, 6)->default(0);
            $table->decimal('admin_bonus', 24, 6)->default(0);
            $table->decimal('balance', 24, 6)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('add_fund_bonus_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('bonus_type', 50);
            $table->decimal('bonus_amount', 24, 6)->default(0);
            $table->decimal('min_add_money_amount', 24, 6)->default(0);
            $table->decimal('max_bonus_amount', 24, 6)->default(0);
            $table->dateTime('start_date_time')->nullable();
            $table->dateTime('end_date_time')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $riyal = DB::table('currencies')->insertGetId(['name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->setting(type: 'currency_model', value: 'single_currency');
        $this->setting(type: 'system_default_currency', value: (string) $riyal);
        $this->walletIs(on: true);
    }

    /** The register of top-ups, from its real migration. */
    protected function installTheTopUpRegister(): void
    {
        (require database_path('migrations/2026_11_23_100000_create_wallet_top_ups_table.php'))->up();
    }

    protected function walletIs(bool $on): void
    {
        $this->setting(type: 'wallet_status', value: $on ? '1' : '0');
    }

    protected function aCustomer(float $balance = 0): int
    {
        return DB::table('users')->insertGetId(['f_name' => 'Noura', 'l_name' => 'Customer', 'email' => 'noura@example.test', 'wallet_balance' => $balance, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A payment the gateway has confirmed, as the callback of any gateway hands it to the success hook. */
    protected function aPaidTopUp(int $customer, float $amount): PaymentRequest
    {
        $id = (string) Str::uuid();
        DB::table('payment_requests')->insert([
            'id' => $id, 'payer_id' => (string) $customer, 'payment_amount' => $amount, 'currency_code' => 'SAR', 'payment_method' => 'paymob_accept',
            'success_hook' => 'add_fund_to_wallet_success', 'failure_hook' => 'add_fund_to_wallet_fail', 'additional_data' => json_encode(['payment_mode' => 'web']),
            'attribute' => 'add_fund', 'payment_platform' => 'web', 'is_paid' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return PaymentRequest::findOrFail($id);
    }

    /** A bonus of a fixed amount on every top-up of at least the given amount, running now. */
    protected function aTopUpBonus(float $bonus, float $fromAmount): void
    {
        DB::table('add_fund_bonus_categories')->insert([
            'title' => 'A bonus', 'bonus_type' => 'fixed', 'bonus_amount' => $bonus, 'min_add_money_amount' => $fromAmount, 'max_bonus_amount' => 0,
            'start_date_time' => now()->subDay(), 'end_date_time' => now()->addDay(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function walletBalanceOf(int $customer): float
    {
        return (float) DB::table('users')->where('id', $customer)->value('wallet_balance');
    }

    private function setting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value, 'updated_at' => now()]);
    }

    /**
     * On a real database the tables and their rows outlive the test that made them. In-memory SQLite starts empty every time.
     * A database whose name does not say "test", or an environment that is not `testing`, is refused outright.
     */
    private function dropTheTablesOfAnEarlierTest(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }

        Schema::disableForeignKeyConstraints();
        foreach (['payment_requests', 'addon_settings', 'currencies', 'business_settings', 'orders', 'users', 'wallet_transactions', 'add_fund_bonus_categories', 'wallet_top_ups'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
