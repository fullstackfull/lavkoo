<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The page that asks a customer for a name and an email after the one-time code of the phone login is only open to the phone that code
 * was just entered for. Posting a phone number, or the phone of a different customer, must neither log anyone in nor change an account.
 */
class CustomerUpdateInfoTest extends CustomerAuthTestCase
{
    private const VICTIM_PHONE = '0500000005';
    private const VICTIM_EMAIL = 'layla.victim@example.test';
    private const OWN_PHONE = '0555000111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureLogin(['login_options' => ['otp_login' => 1, 'manual_login' => 1]]);
    }

    private function identity(string $phone): string
    {
        return base64_encode($phone);
    }

    private function asksForACode(string $phone): TestResponse
    {
        return $this->post('/customer/auth/login', ['login_type' => 'otp-login', 'phone' => $phone]);
    }

    private function entersTheCode(string $phone, string $code = self::OTP): TestResponse
    {
        return $this->post('/customer/auth/login/verify-account/submit', [
            'identity' => $this->identity($phone),
            'type' => base64_encode('phone_verification'),
            'token' => $code,
        ]);
    }

    private function sendsTheProfile(string $identity, array $fields = []): TestResponse
    {
        return $this->post('/customer/auth/login/update-info', ['identity' => $identity] + $fields);
    }

    private function updateInfoPage(string $phone): string
    {
        return route('customer.auth.login.update-info', ['identity' => $this->identity($phone)]);
    }

    private function aCustomerWithoutAName(string $phone, ?string $email = null): User
    {
        return $this->makeCustomer(['name' => null, 'f_name' => null, 'l_name' => null, 'email' => $email, 'phone' => $phone, 'is_phone_verified' => 0]);
    }

    public function test_a_visitor_who_only_knows_a_phone_number_cannot_log_in_as_its_customer(): void
    {
        $victim = $this->makeCustomer(['phone' => self::VICTIM_PHONE]);

        $response = $this->sendsTheProfile(identity: $this->identity(self::VICTIM_PHONE), fields: ['name' => 'Mallory Attacker', 'email' => 'mallory@example.test']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Layla Victim', 'email' => self::VICTIM_EMAIL]);
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_visitor_cannot_fill_in_the_profile_of_a_customer_who_has_no_name_yet(): void
    {
        $customer = $this->aCustomerWithoutAName(phone: self::VICTIM_PHONE);

        $this->sendsTheProfile(identity: $this->identity(self::VICTIM_PHONE), fields: ['name' => 'Mallory Attacker', 'email' => 'mallory@example.test']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => null, 'email' => null]);
    }

    public function test_a_visitor_is_not_told_whether_an_email_address_is_taken(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE, 'email' => self::VICTIM_EMAIL]);

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Mallory', 'email' => self::VICTIM_EMAIL]);

        $toasts = json_encode(session('toastr::messages') ?? []);
        $this->assertStringNotContainsString(translate('Email_already_exists'), $toasts);
        $this->assertStringContainsString(translate('invalid_request'), $toasts);
    }

    public function test_asking_for_a_code_without_entering_it_does_not_open_the_profile_page(): void
    {
        $victim = $this->makeCustomer(['phone' => self::VICTIM_PHONE]);
        $this->asksForACode(phone: self::VICTIM_PHONE);

        $this->sendsTheProfile(identity: $this->identity(self::VICTIM_PHONE), fields: ['name' => 'Mallory Attacker', 'email' => 'mallory@example.test']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Layla Victim']);
    }

    public function test_a_wrong_code_does_not_open_the_profile_page(): void
    {
        $customer = $this->aCustomerWithoutAName(phone: self::OWN_PHONE);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE, code: '000000');

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Mallory Attacker']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => null]);
    }

    public function test_starting_another_sign_in_forgets_the_verified_phone(): void
    {
        $customer = $this->aCustomerWithoutAName(phone: self::OWN_PHONE);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE);
        $this->asksForACode(phone: '0555000999');

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => null]);
    }

    public function test_a_phone_verified_in_this_session_does_not_open_another_customers_account(): void
    {
        $victim = $this->makeCustomer(['phone' => self::VICTIM_PHONE]);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE);

        $this->sendsTheProfile(identity: $this->identity(self::VICTIM_PHONE), fields: ['name' => 'Mallory Attacker', 'email' => 'mallory@example.test']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'phone' => self::VICTIM_PHONE, 'name' => 'Layla Victim', 'email' => self::VICTIM_EMAIL]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_verified_phone_of_a_customer_without_a_name_does_not_open_another_customers_account(): void
    {
        $victim = $this->makeCustomer(['phone' => self::VICTIM_PHONE]);
        $this->aCustomerWithoutAName(phone: self::OWN_PHONE);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE);

        $this->sendsTheProfile(identity: $this->identity(self::VICTIM_PHONE), fields: ['name' => 'Mallory Attacker', 'email' => 'mallory@example.test']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Layla Victim', 'email' => self::VICTIM_EMAIL]);
    }

    public function test_verifying_the_phone_of_a_customer_with_a_name_does_not_leave_the_profile_page_open(): void
    {
        $customer = $this->makeCustomer(['phone' => self::VICTIM_PHONE]);
        $this->asksForACode(phone: self::VICTIM_PHONE);
        $this->entersTheCode(phone: self::VICTIM_PHONE);
        auth('customer')->logout();

        $this->sendsTheProfile(identity: $this->identity(self::VICTIM_PHONE), fields: ['name' => 'Mallory Attacker']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Layla Victim']);
    }

    public function test_the_profile_page_is_not_shown_without_a_verified_phone(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE]);

        $registered = $this->get($this->updateInfoPage(phone: self::VICTIM_PHONE));
        $unregistered = $this->get($this->updateInfoPage(phone: self::OWN_PHONE));

        $registered->assertRedirect(route('customer.auth.login'));
        $unregistered->assertRedirect(route('customer.auth.login'));
    }

    public function test_an_identity_that_is_not_a_string_is_refused_without_a_server_error(): void
    {
        $page = $this->get(route('customer.auth.login.update-info', ['identity' => ['x']]));
        $submit = $this->post('/customer/auth/login/update-info', ['identity' => ['x'], 'name' => 'Mallory']);

        $page->assertRedirect(route('customer.auth.login'));
        $submit->assertRedirect(route('customer.auth.login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_new_customer_registers_with_the_phone_whose_code_was_entered(): void
    {
        $this->asksForACode(phone: self::OWN_PHONE)->assertRedirect(route('customer.auth.login.verify-account', ['identity' => $this->identity(self::OWN_PHONE), 'type' => base64_encode('phone_verification')]));
        $this->entersTheCode(phone: self::OWN_PHONE)->assertRedirect($this->updateInfoPage(phone: self::OWN_PHONE));
        $this->get($this->updateInfoPage(phone: self::OWN_PHONE))->assertOk()->assertSee('name="name"', false);

        $response = $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer', 'email' => 'noura@example.test']);

        $response->assertRedirect(route('home'));
        $account = User::query()->where('phone', self::OWN_PHONE)->firstOrFail();
        $this->assertAuthenticatedAs($account, 'customer');
        $this->assertSame('Noura Newcomer', $account->name);
        $this->assertSame('Noura', $account->f_name);
        $this->assertSame('Newcomer', $account->l_name);
        $this->assertSame('noura@example.test', $account->email);
        $this->assertSame(1, (int) $account->is_phone_verified);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_customer_without_a_name_completes_the_profile_after_verifying_the_phone(): void
    {
        $customer = $this->aCustomerWithoutAName(phone: self::OWN_PHONE);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE)->assertRedirect($this->updateInfoPage(phone: self::OWN_PHONE));
        $this->get($this->updateInfoPage(phone: self::OWN_PHONE))->assertOk();

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer', 'email' => 'noura@example.test'])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Noura Newcomer', 'email' => 'noura@example.test', 'phone' => self::OWN_PHONE, 'is_phone_verified' => 1]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_customer_without_a_name_may_leave_the_email_empty(): void
    {
        $customer = $this->aCustomerWithoutAName(phone: self::OWN_PHONE);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE);

        $response = $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer']);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Noura Newcomer', 'email' => null]);
    }

    public function test_a_customer_with_a_name_is_logged_in_by_the_code_alone(): void
    {
        $customer = $this->makeCustomer(['phone' => self::VICTIM_PHONE]);
        $this->asksForACode(phone: self::VICTIM_PHONE);

        $this->entersTheCode(phone: self::VICTIM_PHONE)->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Layla Victim', 'email' => self::VICTIM_EMAIL]);
    }

    public function test_an_email_taken_by_another_customer_can_be_replaced_in_the_same_attempt(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE, 'email' => self::VICTIM_EMAIL]);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE);

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer', 'email' => self::VICTIM_EMAIL]);
        $this->assertGuest('customer');
        $this->assertDatabaseCount('users', 1);

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer', 'email' => 'noura@example.test'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs(User::query()->where('phone', self::OWN_PHONE)->firstOrFail(), 'customer');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_the_verified_phone_can_be_used_for_one_profile_only(): void
    {
        $customer = $this->aCustomerWithoutAName(phone: self::OWN_PHONE);
        $this->asksForACode(phone: self::OWN_PHONE);
        $this->entersTheCode(phone: self::OWN_PHONE);
        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Noura Newcomer']);
        auth('customer')->logout();

        $this->sendsTheProfile(identity: $this->identity(self::OWN_PHONE), fields: ['name' => 'Mallory Attacker']);

        $this->assertGuest('customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Noura Newcomer']);
    }
}
