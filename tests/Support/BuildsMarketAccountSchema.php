<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The database the market account tests start from: a customer with the one wallet balance and the one point balance the platform keeps
 * today, the two ledgers exactly as the platform's own migrations leave them — with no market on either — and the currency rows and
 * settings of a platform that sells in riyals, which is what tells the service what currency a market's money is in. The feature's own
 * tables and columns come from its REAL migration, which the tests run themselves, so what they prove is what `php artisan migrate` would
 * do.
 *
 * Changing the tables is refused on a non-SQLite connection unless the database name says it is a test database and APP_ENV is testing,
 * the same guard the address, fulfillment and MyFatoorah schema helpers use. A run pointed at a real database cannot drop its customers.
 */
trait BuildsMarketAccountSchema
{
    protected const MARKET_ACCOUNT_MIGRATION = '2026_12_05_120000_create_customer_market_balance_accounts.php';

    /** The currency each of the six markets sells in, and the id of its row. The platform's own money is the riyal. */
    protected const MARKET_CURRENCIES = ['SAR' => 1, 'AED' => 2, 'KWD' => 3, 'QAR' => 4, 'BHD' => 5, 'OMR' => 6];

    /** Balances are DECIMAL(40,20) on main since 2026_05_07_000001_update_all_decimal_columns_to_40_20. */
    protected function buildMarketAccountSchema(): void
    {
        $this->startFromAnEmptyMarketAccountDatabase();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->decimal('wallet_balance', 40, 20)->nullable()->default(0);
            $table->decimal('loyalty_point', 40, 20)->nullable()->default(0);
            $table->timestamps();
        });

        $this->createLedger('wallet_transactions', withAdminBonus: true);
        $this->createLedger('loyalty_point_transactions', withAdminBonus: false);
        $this->createMoneyOfTheSixMarkets();
    }

    /** The feature's REAL migration, run the way `php artisan migrate --path=…` runs it. */
    protected function runMarketAccountMigration(): void
    {
        (require database_path('migrations/' . self::MARKET_ACCOUNT_MIGRATION))->up();
    }

    protected function rollBackMarketAccountMigration(): void
    {
        (require database_path('migrations/' . self::MARKET_ACCOUNT_MIGRATION))->down();
    }

    /**
     * The six storefronts on, each selling in its own currency, the market accounts key on, and the feature's tables in place: the state a
     * switched-over market runs in. The currency switch is part of it: an account is opened in the currency the market SELLS in, and while
     * that switch is off a storefront is still selling in the platform's own money, so there is no market currency to open one in.
     */
    protected function withMarketAccountsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.market_accounts.enabled' => true]);

        foreach (array_keys((array) config('gcc.storefronts.countries')) as $country) {
            config(["gcc.storefronts.countries.{$country}.active" => true]);
        }
    }

    protected function seedCustomer(int $customerId, float $walletBalance = 0, float $loyaltyPoints = 0): void
    {
        DB::table('users')->insert([
            'id' => $customerId,
            'name' => 'Customer ' . $customerId,
            'email' => 'customer' . $customerId . '@example.test',
            'wallet_balance' => $walletBalance,
            'loyalty_point' => $loyaltyPoints,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A movement of a market's ledger, as a writer that has been switched over will write one. The bonus of an add-fund goes in its own
     * column, not in the credit, which is how `App\Utils\CustomerManager::create_wallet_transaction` writes one: only the wallet ledger
     * has that column.
     */
    protected function recordMovement(string $ledger, int $customerId, ?string $market, float $credit = 0, float $debit = 0, float $adminBonus = 0): void
    {
        $movement = [
            'user_id' => $customerId,
            'transaction_id' => (string) Str::uuid(),
            'credit' => $credit,
            'debit' => $debit,
            'balance' => $credit + $adminBonus - $debit,
            'transaction_type' => 'test',
            'storefront_country' => $market,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($ledger === 'wallet_transactions') {
            $movement['admin_bonus'] = $adminBonus;
        }

        DB::table($ledger)->insert($movement);
    }

    protected function marketWalletBalance(int $customerId, string $market): ?float
    {
        $balance = DB::table('customer_market_wallet_accounts')->where(['customer_id' => $customerId, 'market' => $market])->value('balance');

        return $balance === null ? null : (float) $balance;
    }

    protected function marketLoyaltyPoints(int $customerId, string $market): ?float
    {
        $points = DB::table('customer_market_loyalty_accounts')->where(['customer_id' => $customerId, 'market' => $market])->value('points');

        return $points === null ? null : (float) $points;
    }

    private function createLedger(string $ledger, bool $withAdminBonus): void
    {
        Schema::create($ledger, function (Blueprint $table) use ($withAdminBonus): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);

            if ($withAdminBonus) {
                $table->decimal('admin_bonus', 40, 20)->default(0);
            }

            $table->decimal('balance', 40, 20)->default(0);
            $table->string('transaction_type', 191)->nullable();
            $table->string('reference', 191)->nullable();
            $table->timestamps();
        });
    }

    /**
     * What the platform sells in: the six currencies as active rows, and the settings that say the platform's own money is the riyal and
     * that an amount is stored in the currency it is shown in. `App\Services\Storefront\StorefrontCurrency` reads these to answer what
     * currency a market's money is in, and the service refuses to open an account when it cannot be told.
     */
    private function createMoneyOfTheSixMarkets(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('business_settings');

        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        foreach (self::MARKET_CURRENCIES as $code => $id) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }

        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'single_currency'],
            ['type' => 'system_default_currency', 'value' => (string) self::MARKET_CURRENCIES['SAR']],
            ['type' => 'decimal_point_settings', 'value' => '2'],
        ]);

        Cache::flush();
    }

    private function startFromAnEmptyMarketAccountDatabase(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();

        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop every table of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }

        Schema::dropAllTables();
    }
}
