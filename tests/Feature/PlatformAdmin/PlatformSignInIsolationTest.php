<?php

namespace Tests\Feature\PlatformAdmin;

use App\Models\Seller;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LoadsPassportKeys;
use Tests\Support\RestoresLanguageFiles;

/**
 * Each place that signs somebody in looks only in its own table. The platform sign-in does not accept the credentials of a seller, a seller's staff member, a
 * customer or a delivery man, or of an admin who holds no grant, and the sign-ins of the sellers, the customers and the delivery men do not accept an admin's.
 * An e-mail that exists in several of the tables authenticates, at each place, only with the password of that place's own row, and a refusal anywhere writes
 * nothing anywhere else. (The success of the customer's and the delivery man's own sign-in needs the OAuth client tables, which the test database does not
 * have, so it is not exercised here; their refusals are.)
 */
class PlatformSignInIsolationTest extends PlatformAdminTestCase
{
    use LoadsPassportKeys;
    use RestoresLanguageFiles;

    private const SHARED_EMAIL = 'same.person@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->loadPassportKeys();
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            foreach (['withdrawn', 'commission_given', 'total_earning', 'pending_withdraw', 'delivery_charge_earned', 'collected_cash'] as $column) {
                $table->decimal($column, 24, 2)->default(0);
            }
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(true);
            $table->boolean('is_email_verified')->default(true);
            $table->unsignedInteger('login_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('country_code')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        $this->removePassportKeys();

        parent::tearDown();
    }

    public function test_the_platform_sign_in_accepts_nobody_but_an_admin_who_holds_a_grant_not_a_seller_a_staff_member_a_customer_or_a_delivery_man_with_their_right_password(): void
    {
        $seller = Seller::forceCreate(['f_name' => 'Owner', 'email' => 'owner@example.test', 'status' => 'approved', 'password' => Hash::make('seller-password-1')]);
        $role = SellerRole::create(['seller_id' => $seller->id, 'name' => 'Everything', 'permissions' => ['orders.view'], 'status' => 'active']);
        SellerStaff::create(['seller_id' => $seller->id, 'seller_role_id' => $role->id, 'name' => 'Clerk', 'email' => 'clerk@example.test', 'password' => Hash::make('staff-password-1'), 'status' => 'active']);
        DB::table('users')->insert(['f_name' => 'Customer', 'email' => 'customer@example.test', 'phone' => '+96550000001', 'password' => Hash::make('customer-password-1')]);
        DB::table('delivery_men')->insert(['f_name' => 'Courier', 'email' => 'courier@example.test', 'phone' => '+96550000002', 'password' => Hash::make('courier-password-1')]);
        $noGrant = $this->makeAdmin();
        $others = $this->otherTables();

        foreach ([
            'a seller' => ['owner@example.test', 'seller-password-1'],
            'a seller\'s staff member' => ['clerk@example.test', 'staff-password-1'],
            'a customer' => ['customer@example.test', 'customer-password-1'],
            'a delivery man' => ['courier@example.test', 'courier-password-1'],
            'an admin who holds no grant' => [$noGrant['email'], $noGrant['password']],
        ] as $who => [$email, $password]) {
            $this->platformLogin($email, $password)->assertStatus(401)->assertExactJson($this->uniformRefusal());

            $this->assertSame(0, DB::table('platform_admin_tokens')->count(), $who);
            $this->assertSame(0, DB::table('platform_admin_audit_logs')->count(), "{$who}: nothing is audited for somebody who has no grant");
            $this->assertSame($others, $this->otherTables(), "{$who}: no table of anyone else was touched");
        }
    }

    public function test_the_sign_ins_of_sellers_customers_and_delivery_men_accept_no_admins_credentials_and_touch_nothing_of_the_platform(): void
    {
        $admin = $this->platformAdmin();
        DB::table('admins')->where('id', $admin['id'])->update(['phone' => '+96550000003']);
        $before = $this->ownTables();

        foreach ([
            'the seller API v3' => fn (): TestResponse => $this->postJson('/api/v3/seller/auth/login', ['email' => $admin['email'], 'password' => $admin['password']]),
            'the seller API v2' => fn (): TestResponse => $this->postJson('/api/v2/seller/auth/login', ['email' => $admin['email'], 'password' => $admin['password']]),
            'the customer API' => fn (): TestResponse => $this->postJson('/api/v1/auth/login', ['email_or_phone' => $admin['email'], 'password' => $admin['password'], 'type' => 'email']),
            'the delivery man API' => fn (): TestResponse => $this->postJson('/api/v2/delivery-man/auth/login', ['phone' => '+96550000003', 'password' => $admin['password']]),
        ] as $place => $signIn) {
            Cache::flush();
            $response = $signIn();

            $this->assertContains($response->getStatusCode(), [401, 403, 422], "{$place} refuses an admin, and it is not a throttle or a failure");
            $this->assertArrayNotHasKey('token', (array) $response->json(), "{$place} gave a token to an admin");
            $this->assertSame($before, $this->ownTables(), "{$place} touched the platform or the admin");
        }
    }

    public function test_an_e_mail_that_is_an_admin_a_seller_and_a_customer_authenticates_at_each_place_only_against_the_row_of_that_place(): void
    {
        $admin = $this->makeAdmin(['email' => self::SHARED_EMAIL]);
        $this->grantTo($admin);
        Seller::forceCreate(['f_name' => 'Same person', 'email' => self::SHARED_EMAIL, 'status' => 'approved', 'password' => Hash::make('seller-password-1')]);
        DB::table('users')->insert(['f_name' => 'Same person', 'email' => self::SHARED_EMAIL, 'phone' => '+96550000001', 'password' => Hash::make('customer-password-1')]);

        $this->platformLogin(self::SHARED_EMAIL, 'seller-password-1')->assertStatus(401);
        $this->platformLogin(self::SHARED_EMAIL, 'customer-password-1')->assertStatus(401);
        $this->assertSame(0, DB::table('platform_admin_tokens')->count());
        $platform = $this->platformLogin(self::SHARED_EMAIL, $admin['password']);
        $platform->assertOk();
        $this->assertStringStartsWith('lkpa_', $platform->json('data.token'));

        $this->postJson('/api/v3/seller/auth/login', ['email' => self::SHARED_EMAIL, 'password' => $admin['password']])->assertStatus(401);
        $this->postJson('/api/v3/seller/auth/login', ['email' => self::SHARED_EMAIL, 'password' => 'customer-password-1'])->assertStatus(401);
        $seller = $this->postJson('/api/v3/seller/auth/login', ['email' => self::SHARED_EMAIL, 'password' => 'seller-password-1']);
        $seller->assertOk();
        $this->assertStringStartsNotWith('lkpa_', $seller->json('token'));

        foreach ([$admin['password'], 'seller-password-1'] as $notTheCustomers) {
            Cache::flush();
            $response = $this->postJson('/api/v1/auth/login', ['email_or_phone' => self::SHARED_EMAIL, 'password' => $notTheCustomers, 'type' => 'email']);

            $this->assertSame(403, $response->getStatusCode(), 'the customer sign-in found the customer and refused a password that is not his');
            $this->assertArrayNotHasKey('token', (array) $response->json());
        }
        $this->assertSame(1, DB::table('platform_admin_tokens')->count(), 'the only platform session is the one the admin opened');
    }

    private function platformLogin(string $email, string $password): TestResponse
    {
        return $this->postJson(self::PREFIX . '/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'Pixel']);
    }

    /** The rows of the tables of the sellers, their staff, the customers and the delivery men, as text. */
    private function otherTables(): string
    {
        $rows = [];
        foreach (['sellers', 'seller_staff', 'seller_roles', 'users', 'delivery_men'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get();
        }

        return json_encode($rows);
    }
}
