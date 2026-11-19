<?php

namespace Tests\Support;

use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The database the market account tests start from: a customer with the one wallet balance and the one point balance the platform keeps
 * today, and the two ledgers exactly as the platform's own migrations leave them — with no market, no currency and no selling company.
 * The feature's own tables and columns come from its REAL migration, which the tests run themselves, so what they prove is what
 * `php artisan migrate` would do.
 *
 * Changing the tables is refused on a non-SQLite connection unless the database name says it is a test database and APP_ENV is testing,
 * the same guard the address, fulfillment and MyFatoorah schema helpers use. A run pointed at a real database cannot drop its customers.
 */
trait BuildsMarketAccountSchema
{
    protected const MARKET_ACCOUNT_MIGRATION = '2026_12_05_100000_create_customer_market_balance_accounts.php';

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

    /** The six storefronts on, the market accounts key on, and the feature's tables in place: the state a switched-over market runs in. */
    protected function withMarketAccountsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.market_accounts.enabled' => true]);

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

    /** A movement of a market's ledger, as a writer that has been switched over will write one. */
    protected function recordMovement(string $ledger, int $customerId, ?string $market, float $credit = 0, float $debit = 0): void
    {
        DB::table($ledger)->insert([
            'user_id' => $customerId,
            'transaction_id' => (string) Str::uuid(),
            'credit' => $credit,
            'debit' => $debit,
            'balance' => $credit - $debit,
            'transaction_type' => 'test',
            'storefront_country' => $market,
            'currency_code' => $market === null ? null : StorefrontResolver::fromConfig()->currencyOf($market),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
