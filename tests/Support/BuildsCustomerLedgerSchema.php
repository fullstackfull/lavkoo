<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The database an account-deletion test needs: the two money ledgers built by their OWN migrations (so what the test measures is the
 * live shape of the tables, foreign keys and all), and an INFERRED copy of the store tables around them — the installation dump that
 * owns `users` and `orders` is not in the repository, so these hold the columns the deletion path touches and no more.
 *
 * `email` and `phone` carry a unique index here because the live store registers against both: closing an account rewrites them, and the
 * rewrite has to survive the index rather than merely happen to. What this schema CANNOT say is whether a column the scrub sets to null is
 * NOT NULL on the live server; that needs the live schema, which is not in this repository either.
 */
trait BuildsCustomerLedgerSchema
{
    /** The real migrations that make and shape the two ledgers, in the order the installer runs them. */
    public const LEDGER_MIGRATIONS = [
        '2022_05_12_104247_create_wallet_transactions_table.php',
        '2022_05_14_063309_create_loyalty_point_transactions_table.php',
        '2023_08_26_173523_add_payment_method_column_to_wallet_transactions_table.php',
        '2026_01_22_163451_update_loyalty_point_transactions_amount_columns.php',
        '2026_01_22_165803_update_wallet_transactions_decimal_precision.php',
    ];

    protected function buildCustomerLedgerSchema(): void
    {
        $this->resetCustomerLedgerDatabase();
        $this->createStoreTablesAroundTheLedgers();
        $this->runLedgerMigrations();
    }

    private function resetCustomerLedgerDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $database = (string) $connection->getDatabaseName();
            if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
                throw new RuntimeException("Refusing to drop every table of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
            }
            Schema::dropAllTables();
        }

        Cache::flush();
    }

    private function createStoreTablesAroundTheLedgers(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('image')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('house_no')->nullable();
            $table->string('apartment_no')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->string('login_medium')->nullable();
            $table->string('social_id')->nullable();
            $table->string('temporary_token')->nullable();
            $table->string('payment_card_last_four')->nullable();
            $table->string('payment_card_brand')->nullable();
            $table->string('payment_card_fawry_token')->nullable();
            $table->string('referral_code')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('is_email_verified')->default(false);
            $table->decimal('wallet_balance', 21, 12)->default(0);
            $table->decimal('loyalty_point', 21, 12)->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('order_status')->default('pending');
            $table->decimal('order_amount', 21, 12)->default(0);
            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    /** Runs the ledgers' own migrations, whether the file returns a migration (the newer ones) or declares a class (the 2022 and 2023 ones). */
    private function runLedgerMigrations(): void
    {
        foreach (self::LEDGER_MIGRATIONS as $file) {
            $this->migrationIn($file)->up();
        }
    }

    private function migrationIn(string $file): Migration
    {
        $class = Str::studly(implode('_', array_slice(explode('_', basename($file, '.php')), 4)));
        if (class_exists($class)) {
            return new $class();
        }

        $returned = require database_path('migrations/' . $file);
        if ($returned instanceof Migration) {
            return $returned;
        }
        if (class_exists($class)) {
            return new $class();
        }

        throw new RuntimeException("The migration {$file} neither returns a migration nor declares {$class}.");
    }

    /** The settings the wallet and the loyalty points are written under; both on, as the Saudi store runs them. */
    protected function turnTheWalletAndPointsOn(): void
    {
        foreach (['wallet_status' => 1, 'loyalty_point_status' => 1, 'loyalty_point_exchange_rate' => 1, 'loyalty_point_item_purchase_point' => 1] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** The password every customer this trait makes is given, so a test can try to sign in as one. */
    protected const CUSTOMER_PASSWORD = 'secret-password';

    /** Counted rather than drawn at random: a fixture that differs between runs cannot be reasoned about when it fails on one of them. */
    private int $customersMade = 0;

    protected function makeCustomer(array $overrides = []): User
    {
        $number = ++$this->customersMade;

        return User::unguarded(static fn (): User => User::create($overrides + [
            'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara' . $number . '@example.test',
            'phone' => '+96655000' . str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'password' => bcrypt(self::CUSTOMER_PASSWORD), 'is_active' => true,
        ]));
    }
}
