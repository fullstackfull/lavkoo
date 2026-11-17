<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The tables the customer's REST sign-in endpoints read and write, with Passport's real token tables on top: an INFERRED copy of what the
 * installation dump defines (the dump is not in the repository), with only the columns those endpoints touch. Same limits as
 * BuildsFulfillmentSchema: this proves the endpoints' logic, not the live schema.
 *
 * Refuses to run unless APP_ENV is 'testing' and, on a server database, the database name says it is a test database.
 */
trait BuildsRestCustomerAuthSchema
{
    private const PASSPORT_MIGRATIONS = [
        '2016_06_01_000001_create_oauth_auth_codes_table.php',
        '2016_06_01_000002_create_oauth_access_tokens_table.php',
        '2016_06_01_000003_create_oauth_refresh_tokens_table.php',
        '2016_06_01_000004_create_oauth_clients_table.php',
        '2016_06_01_000005_create_oauth_personal_access_clients_table.php',
    ];

    /** @var array{private: string, public: string}|null generated once per run: making a key pair is the slowest step of a test */
    private static ?array $restPassportKeys = null;

    protected function buildRestCustomerAuthSchema(): void
    {
        $this->startFromAnEmptyRestCustomerAuthDatabase();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('is_email_verified')->default(false);
            $table->string('login_medium')->nullable();
            $table->string('social_id')->nullable();
            $table->string('temporary_token')->nullable();
            $table->string('referral_code')->nullable();
            $table->string('app_language')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('login_setups', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        foreach (self::PASSPORT_MIGRATIONS as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }

        DB::table('oauth_clients')->insert([
            'id' => 1, 'user_id' => null, 'name' => 'Personal Access Client', 'secret' => 'throwaway-secret', 'provider' => 'users',
            'redirect' => 'http://localhost', 'personal_access_client' => 1, 'password_client' => 0, 'revoked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('oauth_personal_access_clients')->insert(['client_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->useThrowawayRestPassportKeys();
        Cache::flush();
    }

    /** A throwaway RSA key pair: Passport signs the personal access token with it and refuses to exist without one. */
    private function useThrowawayRestPassportKeys(): void
    {
        if (self::$restPassportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$restPassportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        config(['passport.private_key' => self::$restPassportKeys['private'], 'passport.public_key' => self::$restPassportKeys['public']]);
    }

    private function startFromAnEmptyRestCustomerAuthDatabase(): void
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
