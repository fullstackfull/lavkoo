<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Linking a social login to an existing customer is only allowed for the email the provider has just returned to this browser's session.
 * Without that session, the confirmation link must not log anyone in, show who owns an email, or change the account.
 */
class SocialLoginConfirmationTest extends TestCase
{
    private const VICTIM_EMAIL = 'victim@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCustomerLoginTables();
        DB::table('users')->insert([
            'id' => 5,
            'f_name' => 'Layla',
            'l_name' => 'Victim',
            'email' => self::VICTIM_EMAIL,
            'phone' => '0500000005',
            'password' => bcrypt('irrelevant'),
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_email_verified' => 0,
            'login_medium' => null,
            'social_id' => null,
        ]);
    }

    private function buildCustomerLoginTables(): void
    {
        foreach (['users', 'business_settings', 'login_setups', 'phone_or_email_verifications', 'product_compares', 'wishlists', 'products', 'sellers', 'brands', 'restock_products', 'restock_product_customers', 'storages'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function ($table) {
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
        Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('login_setups', function ($table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('phone_or_email_verifications', function ($table) {
            $table->id();
            $table->string('phone_or_email')->nullable();
            $table->string('token')->nullable();
            $table->timestamps();
        });
        Schema::create('product_compares', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('wishlists', function ($table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
        });
        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('brands', function ($table) {
            $table->id();
            $table->boolean('status')->default(true);
        });
        Schema::create('restock_products', function ($table) {
            $table->id();
        });
        Schema::create('restock_product_customers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::create('storages', function ($table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    private function identityFor(string $email): string
    {
        return base64_encode($email);
    }

    private function pendingSocialLogin(string $email): array
    {
        return [
            'social_login_new_customer' => [
                'name' => 'Layla Victim',
                'f_name' => 'Layla',
                'l_name' => 'Victim',
                'email' => $email,
                'phone' => '',
                'login_medium' => 'google',
                'social_id' => 'google-uid-1',
                'is_email_verified' => 1,
            ],
        ];
    }

    private function confirmationUrl(string $email, array $query = []): string
    {
        return route('customer.auth.social-login-confirmation', array_merge(['identity' => $this->identityFor($email)], $query));
    }

    public function test_a_visitor_with_no_social_login_cannot_log_in_by_approving_an_email(): void
    {
        $response = $this->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $response->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    public function test_an_approval_with_no_social_login_leaves_the_account_unchanged(): void
    {
        $this->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $account = User::query()->findOrFail(5);
        $this->assertNull($account->login_medium);
        $this->assertNull($account->social_id);
        $this->assertEquals(0, $account->is_email_verified);
    }

    public function test_a_social_login_for_another_email_cannot_approve_this_account(): void
    {
        $response = $this->withSession($this->pendingSocialLogin('someone.else@example.test'))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $response->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
        $this->assertNull(User::query()->findOrFail(5)->login_medium);
    }

    public function test_the_confirmation_page_does_not_show_who_owns_an_email_without_a_social_login(): void
    {
        $response = $this->get($this->confirmationUrl(self::VICTIM_EMAIL));

        $response->assertRedirect(route('customer.auth.login'));
        $response->assertDontSee('Layla');
    }

    public function test_the_provider_email_returned_to_this_session_can_be_approved(): void
    {
        $response = $this->withSession($this->pendingSocialLogin(self::VICTIM_EMAIL))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $response->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->findOrFail(5), 'customer');
        $account = User::query()->findOrFail(5);
        $this->assertSame('google', $account->login_medium);
        $this->assertSame('google-uid-1', $account->social_id);
    }

    public function test_the_approval_matches_the_provider_email_without_regard_to_letter_case(): void
    {
        $this->withSession($this->pendingSocialLogin('Victim@Example.test'))
            ->get($this->confirmationUrl(self::VICTIM_EMAIL, ['status' => 'approve']));

        $this->assertAuthenticatedAs(User::query()->findOrFail(5), 'customer');
    }

    public function test_an_update_with_no_social_login_does_not_remove_the_email_of_an_account(): void
    {
        $this->post(route('customer.auth.social-login-confirmation.update'), [
            'identity' => $this->identityFor(self::VICTIM_EMAIL),
            'phone' => '0500000099',
            'name' => 'Attacker',
        ]);

        $account = User::query()->findOrFail(5);
        $this->assertSame(self::VICTIM_EMAIL, $account->email);
        $this->assertSame(1, (int) $account->is_phone_verified);
    }

    public function test_an_update_with_no_social_login_does_not_say_whether_a_phone_is_registered(): void
    {
        $this->post(route('customer.auth.social-login-confirmation.update'), [
            'identity' => $this->identityFor('nobody@example.test'),
            'phone' => '0500000005',
            'name' => 'Attacker',
        ]);

        $notifications = json_encode(session('toastr::messages') ?? []);
        $this->assertStringContainsString(translate('Invalid_Request'), $notifications);
        $this->assertStringNotContainsString(translate('Phone_Number_Already_Exist'), $notifications);
    }

    public function test_the_provider_email_returned_to_this_session_can_finish_the_update(): void
    {
        DB::table('users')->where('id', 5)->update(['phone' => null]);

        $this->withSession($this->pendingSocialLogin(self::VICTIM_EMAIL))
            ->post(route('customer.auth.social-login-confirmation.update'), [
                'identity' => $this->identityFor(self::VICTIM_EMAIL),
                'phone' => '0500000077',
                'name' => 'Layla New',
            ]);

        $this->assertDatabaseHas('users', ['email' => self::VICTIM_EMAIL, 'phone' => '0500000077', 'login_medium' => 'google']);
    }
}
