<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * M2-04.0 of the master plan: one `users` row signs in through any storefront with the same credentials, and no user is created for a storefront. The customer
 * signs in through the real routes of the customer API (the one the apps use) and of the website, once from each of the six storefronts and once from none, and
 * every answer is the same account: a token (for the website, a session) of the same user id, the one row unchanged, nothing created. Another customer is
 * registered first, so that "the same user id" is not the first or the only row by luck, and nothing of that customer changes. A wrong password is refused alike
 * everywhere, and so is an identity nobody registered, which creates no user. Only the API counts the failed attempts, on the one row: the website returns before
 * its counter, which no test here pins. Only the API is tried with a registration, which creates one row; the rows registered through the six storefronts and
 * through none are the same but for what a registration makes unique. The sign-up of the website is not exercised.
 *
 * The world is built from what the two login controllers read and write: the verification settings (off), the columns of a customer's login attempts and
 * verification, the personal access tokens the apps sign in with (the real tables of the token library and its client), and, for the website, the tables its
 * session data is read from. The users table itself is the inferred one of the API tests, so what no migration may do to it is guarded at the source: a scan of
 * the migrations of the application and of its modules for one that names the users table and a storefront (a column that the word storefront does not name is
 * not caught). The API login reads no storefront (the header changes nothing it does), the website login is served from the storefront its request chooses.
 */
class StorefrontCustomerSignInTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const STOREFRONTS = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];

    private const PASSWORD = 'Secret-123';

    private const API_LOGIN = '/api/v1/auth/login';

    private const API_REGISTER = '/api/v1/auth/register';

    private const WEB_LOGIN = '/customer/auth/login';

    /** What a registration makes unique: the identity, the credentials, the codes it draws and the moments. */
    private const UNIQUE_TO_A_REGISTRATION = ['id', 'email', 'phone', 'password', 'referral_code', 'temporary_token', 'created_at', 'updated_at'];

    private User $bystander;

    /** @var array<string, mixed> */
    private array $bystanderRow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->buildTheSignInTables();
        $this->setSetting(type: 'auth_attempts_per_minute', value: '600');
    }

    private function buildTheSignInTables(): void
    {
        Schema::table(table: 'users', callback: function (Blueprint $table) {
            $table->tinyInteger('login_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(0);
            $table->timestamp('temp_block_time')->nullable();
            $table->integer('referred_by')->nullable();
            $table->boolean('is_phone_verified')->default(0);
            $table->boolean('is_email_verified')->default(0);
            $table->string('temporary_token')->nullable();
        });
        Schema::create(table: 'login_setups', callback: function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
        DB::table('login_setups')->insert([['key' => 'phone_verification', 'value' => '0'], ['key' => 'email_verification', 'value' => '0']]);

        foreach (glob(base_path('vendor/laravel/passport/database/migrations/*.php')) as $migration) {
            (require $migration)->up();
        }
        app(ClientRepository::class)->createPersonalAccessClient(userId: null, name: 'Customer app', redirect: 'http://localhost');
    }

    /** The website reads the wishlist, the compare list and the restock requests of the customer into the session when it signs in. */
    private function openTheWebsiteLogin(): void
    {
        foreach (['2024_10_01_131352_create_restock_products_table', '2024_10_01_132315_create_restock_product_customers_table'] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
        foreach (['product_compares' => 'user_id', 'wishlists' => 'customer_id'] as $name => $owner) {
            Schema::create(table: $name, callback: function (Blueprint $table) use ($owner) {
                $table->id();
                $table->unsignedBigInteger($owner);
                $table->unsignedBigInteger('product_id');
                $table->timestamps();
            });
        }
        DB::table('login_setups')->insert(['key' => 'login_options', 'value' => json_encode(['manual_login' => 1, 'otp_login' => 0, 'social_login' => 0])]);
    }

    /** @return array<string, array{?string}> the six storefronts, and a request that names none */
    public static function channels(): array
    {
        $channels = [];
        foreach (self::STOREFRONTS as $storefront) {
            $channels[$storefront] = [$storefront];
        }

        return $channels + ['no storefront header' => [null]];
    }

    /** A customer who is not the one who signs in, registered first: the one who signs in is then neither the first nor the only row. */
    private function registerTheBystander(): void
    {
        $this->bystander = $this->makeCustomer(['email' => 'bystander@example.test', 'phone' => '0500000001', 'password' => Hash::make(value: self::PASSWORD)]);
        $this->bystanderRow = $this->storedRowOf($this->bystander);
    }

    private function registeredCustomer(): User
    {
        $this->registerTheBystander();

        return $this->makeCustomer(['password' => Hash::make(value: self::PASSWORD)]);
    }

    private function assertTheBystanderIsUntouched(): void
    {
        $this->assertSame($this->bystanderRow, $this->storedRowOf($this->bystander), 'the row of the other customer is not changed by what happens to the one who signs in');
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $this->bystander->id)->count(), 'no token is issued for the other customer');
    }

    private function apiSignIn(?string $storefront, string $identity, string $password = self::PASSWORD, string $type = 'email'): TestResponse
    {
        return $this->postJson(
            uri: self::API_LOGIN,
            data: ['email_or_phone' => $identity, 'password' => $password, 'type' => $type],
            headers: $this->storefrontHeader($storefront),
        );
    }

    private function websiteSignIn(?string $storefront, string $identity, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson(
            uri: self::WEB_LOGIN . ($storefront === null ? '' : "?storefront={$storefront}"),
            data: ['user_identity' => $identity, 'password' => $password, 'login_type' => 'manual-login'],
            headers: ['X-Requested-With' => 'XMLHttpRequest'],
        );
    }

    /** The user the token was issued for, as the token itself says it. */
    private function userIdOfToken(string $token): int
    {
        return (int) app(Parser::class)->parse($token)->claims()->get('sub');
    }

    /** @return array<string, mixed> the stored row of the customer, less the moment it was last written */
    private function storedRowOf(User $customer): array
    {
        return Arr::except(array: (array) DB::table('users')->where('id', $customer->id)->first(), keys: ['updated_at']);
    }

    private function failedAttemptsOf(User $customer): int
    {
        return (int) DB::table('users')->where('id', $customer->id)->value('login_hit_count');
    }

    #[DataProvider('channels')]
    public function test_the_one_account_signs_in_through_the_storefront_with_the_same_credentials(?string $storefront): void
    {
        $customer = $this->registeredCustomer();
        $stored = $this->storedRowOf($customer);

        $this->assertSame($storefront ?? 'SA', StorefrontResolver::fromConfig()->resolve(headerChoice: $storefront), 'the header names a storefront this platform serves');

        $response = $this->apiSignIn(storefront: $storefront, identity: $customer->email)->assertOk()->assertJsonPath('status', true);

        $token = (string) $response->json('token');
        $issued = DB::table('oauth_access_tokens')->first();
        $this->assertSame($customer->id, $this->userIdOfToken($token));
        $this->assertSame($customer->id, (int) $issued->user_id);
        $this->assertFalse((bool) $issued->revoked);
        $this->assertSame(2, DB::table('users')->count(), 'no user is created for a storefront: the other customer and this one are all there is');
        $this->assertSame($stored, $this->storedRowOf($customer), 'the one row is not changed by the storefront the customer came from');
        $this->assertTheBystanderIsUntouched();

        $this->getJson(uri: '/api/v1/customer/info', headers: ['Authorization' => "Bearer {$token}"] + $this->storefrontHeader($storefront))
            ->assertOk()
            ->assertJsonPath('id', $customer->id);
    }

    public function test_every_sign_in_of_every_storefront_and_of_none_ends_in_the_one_row(): void
    {
        $customer = $this->registeredCustomer();

        $tokens = [];
        foreach (['email' => $customer->email, 'phone' => $customer->phone] as $type => $identity) {
            foreach ([...self::STOREFRONTS, null] as $storefront) {
                $tokens[] = (string) $this->apiSignIn(storefront: $storefront, identity: $identity, type: $type)->assertOk()->json('token');

                $this->assertSame(2, DB::table('users')->count(), "after signing in by {$type} through " . ($storefront ?? 'no storefront'));
            }
        }

        $this->assertCount(14, array_unique($tokens), 'seven answers for each of the two identities, each a token of its own');
        $this->assertSame([$customer->id], array_values(array_unique(array_map(callback: $this->userIdOfToken(...), array: $tokens))));
        $this->assertSame([$customer->id], DB::table('oauth_access_tokens')->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $this->assertSame(14, DB::table('oauth_access_tokens')->count());
        $this->assertTheBystanderIsUntouched();
    }

    #[DataProvider('channels')]
    public function test_a_wrong_password_is_refused_the_same_way_through_the_storefront(?string $storefront): void
    {
        $customer = $this->registeredCustomer();

        $this->apiSignIn(storefront: $storefront, identity: $customer->email, password: 'not-the-password')
            ->assertStatus(403)
            ->assertExactJson(['errors' => [['code' => 'invalid_credentials', 'message' => translate('credentials_doesnt_match')]]]);

        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(1, $this->failedAttemptsOf($customer));
        $this->assertTheBystanderIsUntouched();
    }

    public function test_the_wrong_passwords_of_the_storefronts_add_up_on_the_one_account_and_a_sign_in_from_any_of_them_clears_them(): void
    {
        $customer = $this->registeredCustomer();

        foreach (['SA', 'KW', null] as $attempts => $storefront) {
            $this->apiSignIn(storefront: $storefront, identity: $customer->email, password: 'not-the-password')->assertStatus(403);

            $this->assertSame($attempts + 1, $this->failedAttemptsOf($customer), 'one counter on the one row, whichever storefront the attempt came from');
        }

        $this->apiSignIn(storefront: 'AE', identity: $customer->email)->assertOk();

        $this->assertSame(0, $this->failedAttemptsOf($customer));
        $this->assertSame(2, DB::table('users')->count());
        $this->assertTheBystanderIsUntouched();
    }

    #[DataProvider('channels')]
    public function test_an_identity_nobody_registered_is_refused_through_the_storefront_and_no_user_is_created(?string $storefront): void
    {
        $this->registerTheBystander();

        $this->apiSignIn(storefront: $storefront, identity: 'nobody@example.test')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'auth-001');

        $this->assertSame(1, DB::table('users')->count(), 'no user is created for an identity nobody registered: only the other customer is there');
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertTheBystanderIsUntouched();
    }

    #[DataProvider('channels')]
    public function test_a_registration_through_the_storefront_creates_exactly_one_user_and_writes_no_storefront_into_it(?string $storefront): void
    {
        $response = $this->postJson(
            uri: self::API_REGISTER,
            data: ['f_name' => 'Noor', 'l_name' => 'Alsabah', 'email' => 'noor@example.test', 'phone' => '55501234', 'password' => self::PASSWORD],
            headers: $this->storefrontHeader($storefront),
        )->assertOk();

        $this->assertSame(1, DB::table('users')->count());
        $user = DB::table('users')->first();
        $this->assertSame(['Noor Alsabah', 'Noor', 'Alsabah', 'noor@example.test', '55501234'], [$user->name, $user->f_name, $user->l_name, $user->email, $user->phone]);
        $this->assertTrue(Hash::check(value: self::PASSWORD, hashedValue: $user->password));
        $this->assertSame((int) $user->id, $this->userIdOfToken((string) $response->json('token')));
        $this->assertNotContains($storefront ?? 'SA', array_values((array) $user), 'no column of the new row holds the storefront the registration came from');
    }

    public function test_the_registrations_of_every_storefront_and_of_none_write_the_same_row(): void
    {
        $channels = [...self::STOREFRONTS, null];

        foreach ($channels as $position => $storefront) {
            $this->postJson(
                uri: self::API_REGISTER,
                data: ['f_name' => 'Noor', 'l_name' => 'Alsabah', 'email' => "noor{$position}@example.test", 'phone' => "5550123{$position}", 'password' => self::PASSWORD],
                headers: $this->storefrontHeader($storefront),
            )->assertOk();
        }

        $rows = DB::table('users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $this->assertCount(count($channels), $rows, 'one row for each registration, and none for a storefront');

        foreach ($rows as $position => $row) {
            $channel = $channels[$position] ?? 'no storefront';

            $this->assertSame(["noor{$position}@example.test", "5550123{$position}"], [$row['email'], $row['phone']], "the identity registered through {$channel}");
            $this->assertTrue(Hash::check(value: self::PASSWORD, hashedValue: $row['password']), "the password registered through {$channel}");
            $this->assertMatchesRegularExpression(pattern: '/^[A-Z0-9]{20}$/', string: $row['referral_code'], message: "the referral code drawn through {$channel} holds nothing of the storefront");
            $this->assertMatchesRegularExpression(pattern: '/^[A-Za-z0-9]{40}$/', string: $row['temporary_token'], message: "the temporary token drawn through {$channel} holds nothing of the storefront");
            $this->assertSame(
                Arr::except(array: $rows[0], keys: self::UNIQUE_TO_A_REGISTRATION),
                Arr::except(array: $row, keys: self::UNIQUE_TO_A_REGISTRATION),
                "the row registered through {$channel} is the row registered through {$channels[0]}",
            );
        }
    }

    /** @return array<string, array{string}> a migration that puts a storefront on the users table, written each of the ways a migration can be */
    public static function migrationsThatPutAStorefrontOnTheUsersTable(): array
    {
        return [
            'the table named first' => [<<<'PHP'
                Schema::table('users', fn (Blueprint $table) => $table->string('storefront_country')->nullable());
                PHP],
            'the table named by its argument' => [<<<'PHP'
                Schema::table(table: 'users', callback: fn (Blueprint $table) => $table->string('storefront_country')->nullable());
                PHP],
            'the table named between double quotes' => [<<<'PHP'
                Schema::create("users", fn (Blueprint $table) => $table->string("storefront_country")->nullable());
                PHP],
            'another connection' => [<<<'PHP'
                Schema::connection(null)->table('users', fn (Blueprint $table) => $table->string('storefront_country')->nullable());
                PHP],
            'plain SQL' => [<<<'PHP'
                DB::statement('ALTER TABLE users ADD storefront_country VARCHAR(2) NULL');
                PHP],
            'plain SQL with the table quoted' => [<<<'PHP'
                DB::statement('ALTER TABLE `users` ADD `storefront_country` VARCHAR(2) NULL');
                PHP],
        ];
    }

    /** @return array<string, array{string}> a migration the scan must leave alone */
    public static function migrationsThatLeaveTheUsersTableAlone(): array
    {
        return [
            'a column of the users table that is no storefront' => [<<<'PHP'
                Schema::table('users', fn (Blueprint $table) => $table->string('nickname')->nullable());
                PHP],
            'a storefront on another table' => [<<<'PHP'
                Schema::table('products', fn (Blueprint $table) => $table->string('storefront_country')->nullable());
                PHP],
        ];
    }

    private static function putsAStorefrontOnTheUsersTable(string $migration): bool
    {
        return preg_match(pattern: '/[\'"]users[\'"]|ALTER\s+TABLE\s+`?users\b/i', subject: $migration) === 1
            && stripos(haystack: $migration, needle: 'storefront') !== false;
    }

    #[DataProvider('migrationsThatPutAStorefrontOnTheUsersTable')]
    public function test_the_scan_of_the_migrations_recognises_a_storefront_put_on_the_users_table(string $migration): void
    {
        $this->assertTrue(self::putsAStorefrontOnTheUsersTable($migration));
    }

    #[DataProvider('migrationsThatLeaveTheUsersTableAlone')]
    public function test_the_scan_of_the_migrations_leaves_alone_what_puts_no_storefront_on_the_users_table(string $migration): void
    {
        $this->assertFalse(self::putsAStorefrontOnTheUsersTable($migration));
    }

    public function test_no_migration_gives_the_users_table_a_storefront(): void
    {
        $application = glob(database_path('migrations/*.php'));
        $modules = glob(base_path('Modules/*/database/migrations/*.php'));
        $this->assertNotEmpty($application, 'the migrations of the application are found');
        $this->assertNotEmpty($modules, 'the migrations of the modules are found');

        $offenders = array_filter(
            array: [...$application, ...$modules],
            callback: static fn (string $path): bool => self::putsAStorefrontOnTheUsersTable((string) file_get_contents($path)),
        );

        $this->assertSame([], array_values($offenders), 'a customer is one row for every storefront: a migration that puts a storefront on the users table is the change that ends M2-04');
    }

    #[DataProvider('channels')]
    public function test_the_website_signs_the_one_account_in_through_the_storefront(?string $storefront): void
    {
        $this->openTheWebsiteLogin();
        $customer = $this->registeredCustomer();
        $stored = $this->storedRowOf($customer);

        $this->websiteSignIn(storefront: $storefront, identity: $customer->email)->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame($storefront ?? 'SA', app(StorefrontContext::class)->country, 'the website served the sign-in from the storefront that was chosen');
        $this->assertSame($customer->id, (int) auth('customer')->id(), 'the session of the website belongs to the one account');
        $this->assertSame(2, DB::table('users')->count(), 'no user is created for a storefront: the other customer and this one are all there is');
        $this->assertSame($stored, $this->storedRowOf($customer));
        $this->assertTheBystanderIsUntouched();
    }

    #[DataProvider('channels')]
    public function test_the_website_refuses_a_wrong_password_the_same_way_through_the_storefront(?string $storefront): void
    {
        $this->openTheWebsiteLogin();
        $customer = $this->registeredCustomer();

        $this->websiteSignIn(storefront: $storefront, identity: $customer->email, password: 'not-the-password')
            ->assertOk()
            ->assertExactJson(['status' => 'error', 'message' => translate('credentials_doesnt_match'), 'redirect_url' => '']);

        $this->assertSame($storefront ?? 'SA', app(StorefrontContext::class)->country, 'the website served the refusal from the storefront that was chosen');
        $this->assertGuest('customer');
        $this->assertSame(2, DB::table('users')->count());
        $this->assertTheBystanderIsUntouched();
    }

    #[DataProvider('channels')]
    public function test_the_website_refuses_an_identity_nobody_registered_through_the_storefront(?string $storefront): void
    {
        $this->openTheWebsiteLogin();
        $this->registerTheBystander();

        $this->websiteSignIn(storefront: $storefront, identity: 'nobody@example.test')
            ->assertOk()
            ->assertExactJson(['status' => 'error', 'message' => translate('credentials_doesnt_match'), 'redirect_url' => '']);

        $this->assertSame($storefront ?? 'SA', app(StorefrontContext::class)->country, 'the website served the refusal from the storefront that was chosen');
        $this->assertGuest('customer');
        $this->assertSame(1, DB::table('users')->count(), 'no user is created for an identity nobody registered: only the other customer is there');
        $this->assertTheBystanderIsUntouched();
    }
}
