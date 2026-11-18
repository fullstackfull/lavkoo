<?php

namespace Tests\Feature\Security;

use App\Events\EmailVerificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The page where a customer types the code that was just sent to their phone or email is only open to the browser the code was sent
 * for: by the sign-up, the password sign-in or the social sign-in of that very account. Knowing a phone number or an email address is
 * not enough to see whose account it is.
 */
class CustomerVerificationPageTest extends CustomerAuthTestCase
{
    private const VICTIM_PHONE = '0500000005';
    private const VICTIM_EMAIL = 'layla.victim@example.test';
    private const OWN_PHONE = '0555000111';
    private const OWN_EMAIL = 'noura@example.test';

    private ?string $requestUri = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestUri = $_SERVER['REQUEST_URI'] ?? null;
        $this->configureLogin(['phone_verification' => 1, 'email_verification' => 0, 'login_options' => ['otp_login' => 1, 'manual_login' => 1]]);
    }

    protected function tearDown(): void
    {
        if ($this->requestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUri;
        }

        parent::tearDown();
    }

    private function pageUrl(string $identity, string $type = 'phone_verification'): string
    {
        return route('customer.auth.check-verification', ['identity' => base64_encode($identity), 'type' => base64_encode($type)]);
    }

    private function signsUp(array $overrides = [], bool $ajax = true): TestResponse
    {
        return $this->post('/customer/auth/sign-up', $overrides + [
            'f_name' => 'Noura',
            'l_name' => 'Newcomer',
            'email' => self::OWN_EMAIL,
            'phone' => self::OWN_PHONE,
            'password' => 'secret-pass-1',
            'con_password' => 'secret-pass-1',
        ], $ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : []);
    }

    private function entersTheCode(string $pageUrl, string $code = self::OTP): TestResponse
    {
        parse_str((string) parse_url($pageUrl, PHP_URL_QUERY), $query);

        return $this->post(route('customer.auth.verify'), ['identity' => $query['identity'], 'type' => $query['type'], 'token' => $code]);
    }

    private function anUnverifiedCustomer(array $attributes = []): User
    {
        return $this->makeCustomer($attributes + ['phone' => self::OWN_PHONE, 'email' => self::OWN_EMAIL, 'is_phone_verified' => 0, 'is_email_verified' => 0]);
    }

    private function theProviderReturns(string $email, string $name, string $id = 'google-uid-1'): void
    {
        $socialUser = (new SocialUser())->map(['id' => $id, 'name' => $name, 'email' => $email])->setRaw(['name' => $name, 'email' => $email]);
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
        $_SERVER['REQUEST_URI'] = '/customer/auth/login/google/callback?code=provider-code';
    }

    public function test_a_visitor_who_only_knows_a_phone_number_is_not_greeted_with_the_name_of_its_customer(): void
    {
        $this->configureLogin([]);
        $this->makeCustomer(['phone' => self::VICTIM_PHONE]);

        $response = $this->get($this->pageUrl(identity: self::VICTIM_PHONE));

        $response->assertDontSee('Layla');
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_visitor_cannot_read_the_internal_id_of_an_unverified_customer(): void
    {
        $customer = $this->makeCustomer(['id' => 7351, 'phone' => self::VICTIM_PHONE, 'is_phone_verified' => 0]);

        $response = $this->get($this->pageUrl(identity: self::VICTIM_PHONE));

        $response->assertDontSee('value="' . $customer->id . '"', false);
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_visitor_who_only_knows_an_email_address_is_not_greeted_with_the_name_of_its_customer(): void
    {
        $this->configureLogin(['phone_verification' => 0, 'email_verification' => 1]);
        $this->makeCustomer(['phone' => self::VICTIM_PHONE, 'email' => self::VICTIM_EMAIL]);

        $response = $this->get($this->pageUrl(identity: self::VICTIM_EMAIL, type: 'email_verification'));

        $response->assertDontSee('Layla');
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_an_empty_identity_does_not_show_the_first_customer(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE]);

        $response = $this->get(route('customer.auth.check-verification', ['identity' => '', 'type' => base64_encode('phone_verification')]));

        $response->assertDontSee('Layla');
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_an_identity_that_is_not_a_string_is_refused_without_a_server_error(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE]);

        $response = $this->get(route('customer.auth.check-verification', ['identity' => ['x'], 'type' => base64_encode('phone_verification')]));

        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_an_unknown_identity_is_refused_like_a_known_one(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE]);

        $known = $this->get($this->pageUrl(identity: self::VICTIM_PHONE));
        $unknown = $this->get($this->pageUrl(identity: '0599999999'));

        $known->assertRedirect(route('customer.auth.login'));
        $unknown->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_code_sent_for_one_account_does_not_open_the_page_of_another(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE]);
        $this->signsUp()->assertOk();

        $response = $this->get($this->pageUrl(identity: self::VICTIM_PHONE));

        $response->assertDontSee('Layla');
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_sign_up_that_uses_the_email_of_a_customer_as_its_phone_does_not_open_the_page_of_that_customer(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE, 'email' => 'layla5551@mail.test']);
        $this->signsUp(['f_name' => 'Mallory', 'email' => 'mallory@example.test', 'phone' => 'layla5551@mail.test'])->assertOk();

        $response = $this->get($this->pageUrl(identity: 'layla5551@mail.test'));

        $response->assertDontSee('Layla');
    }

    public function test_asking_for_a_code_to_be_sent_again_does_not_open_the_page(): void
    {
        $this->makeCustomer(['phone' => self::VICTIM_PHONE, 'is_phone_verified' => 0]);

        $this->post(route('customer.auth.resend_otp'), ['identity' => base64_encode(self::VICTIM_PHONE), 'type' => base64_encode('phone_verification')]);
        $response = $this->get($this->pageUrl(identity: self::VICTIM_PHONE));

        $response->assertDontSee('Layla');
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_wrong_password_does_not_open_the_page(): void
    {
        $this->anUnverifiedCustomer(['password' => bcrypt('right-password')]);

        $this->post('/customer/auth/login', ['login_type' => 'manual-login', 'user_identity' => self::OWN_PHONE, 'password' => 'wrong-password']);
        $response = $this->get($this->pageUrl(identity: self::OWN_PHONE));

        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_the_page_is_closed_again_once_the_account_is_verified(): void
    {
        $pageUrl = $this->signsUp()->json('redirect_url');
        $this->entersTheCode(pageUrl: $pageUrl);
        auth('customer')->logout();

        $response = $this->get($pageUrl);

        $response->assertDontSee('Noura');
        $response->assertRedirect(route('customer.auth.login'));
    }

    public function test_a_new_customer_verifies_the_phone_after_signing_up(): void
    {
        $response = $this->signsUp();

        $response->assertOk();
        $pageUrl = $response->json('redirect_url');
        $this->assertSame($this->pageUrl(identity: self::OWN_PHONE), $pageUrl);
        $page = $this->get($pageUrl);
        $page->assertOk();
        $page->assertSee(route('customer.auth.verify'), false);
        $page->assertSee('value="' . base64_encode(self::OWN_PHONE) . '"', false);

        $this->entersTheCode(pageUrl: $pageUrl)->assertRedirect(route('home'));

        $account = User::query()->where('phone', self::OWN_PHONE)->firstOrFail();
        $this->assertAuthenticatedAs($account, 'customer');
        $this->assertSame(1, (int) $account->is_phone_verified);
    }

    public function test_the_page_does_not_carry_the_internal_id_of_the_account_even_for_its_owner(): void
    {
        $customer = $this->anUnverifiedCustomer(['id' => 7351, 'password' => bcrypt('right-password')]);
        $response = $this->post('/customer/auth/login', ['login_type' => 'manual-login', 'user_identity' => self::OWN_PHONE, 'password' => 'right-password'], ['X-Requested-With' => 'XMLHttpRequest']);

        $page = $this->get($response->json('redirect_url'));

        $page->assertOk();
        $page->assertDontSee('value="' . $customer->id . '"', false);
        $page->assertDontSee('name="id"', false);
    }

    public function test_a_new_customer_verifies_the_phone_after_signing_up_without_script(): void
    {
        $response = $this->signsUp(ajax: false);

        $response->assertRedirect($this->pageUrl(identity: self::OWN_PHONE));
        $this->get($this->pageUrl(identity: self::OWN_PHONE))->assertOk()->assertSee(route('customer.auth.verify'), false);

        $this->entersTheCode(pageUrl: $this->pageUrl(identity: self::OWN_PHONE))->assertRedirect(route('home'));
        $this->assertAuthenticatedAs(User::query()->where('phone', self::OWN_PHONE)->firstOrFail(), 'customer');
    }

    public static function signUpModes(): array
    {
        return ['with script' => [true], 'without script' => [false]];
    }

    #[DataProvider('signUpModes')]
    public function test_a_new_customer_verifies_the_email_after_signing_up(bool $ajax): void
    {
        Event::fake([EmailVerificationEvent::class]);
        DB::table('business_settings')->insert(['type' => 'mail_config', 'value' => json_encode(['status' => 1]), 'created_at' => now(), 'updated_at' => now()]);
        $this->configureLogin(['phone_verification' => 0, 'email_verification' => 1]);
        $pageUrl = $this->pageUrl(identity: self::OWN_EMAIL, type: 'email_verification');

        $response = $this->signsUp(ajax: $ajax);

        $this->assertSame($pageUrl, $ajax ? $response->json('redirect_url') : $response->headers->get('Location'));
        Event::assertDispatched(EmailVerificationEvent::class);
        $this->get($pageUrl)->assertOk()->assertSee(route('customer.auth.verify'), false);
        $this->entersTheCode(pageUrl: $pageUrl)->assertRedirect(route('home'));
        $account = User::query()->where('email', self::OWN_EMAIL)->firstOrFail();
        $this->assertAuthenticatedAs($account, 'customer');
        $this->assertSame(1, (int) $account->is_email_verified);
    }

    public function test_a_customer_who_signs_in_before_verifying_the_phone_is_sent_to_the_page(): void
    {
        $customer = $this->anUnverifiedCustomer(['password' => bcrypt('right-password')]);

        $response = $this->post('/customer/auth/login', ['login_type' => 'manual-login', 'user_identity' => self::OWN_PHONE, 'password' => 'right-password'], ['X-Requested-With' => 'XMLHttpRequest']);

        $pageUrl = $response->json('redirect_url');
        $this->assertSame($this->pageUrl(identity: self::OWN_PHONE), $pageUrl);
        $this->get($pageUrl)->assertOk()->assertSee(route('customer.auth.verify'), false);
        $this->entersTheCode(pageUrl: $pageUrl)->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_phone_verified' => 1]);
    }

    public function test_a_customer_who_signs_in_with_google_before_verifying_the_phone_is_sent_to_the_page(): void
    {
        $customer = $this->anUnverifiedCustomer(['login_medium' => 'google']);
        $this->theProviderReturns(email: self::OWN_EMAIL, name: 'Noura Newcomer');

        $response = $this->get('/customer/auth/login/google/callback?code=provider-code');

        $response->assertRedirect($this->pageUrl(identity: self::OWN_PHONE));
        $this->get($this->pageUrl(identity: self::OWN_PHONE))->assertOk()->assertSee(route('customer.auth.verify'), false);
        $this->entersTheCode(pageUrl: $this->pageUrl(identity: self::OWN_PHONE))->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_a_new_google_customer_gives_a_phone_and_verifies_it(): void
    {
        $this->theProviderReturns(email: self::OWN_EMAIL, name: 'Noura Newcomer');

        $callback = $this->get('/customer/auth/login/google/callback?code=provider-code');
        $this->get($callback->headers->get('Location'))->assertOk();
        $update = $this->post('/customer/auth/login/social/confirmation/update', [
            'identity' => base64_encode(self::OWN_EMAIL),
            'name' => 'Noura Newcomer',
            'phone' => self::OWN_PHONE,
        ]);
        $verifyUrl = $update->headers->get('Location');
        $this->get($verifyUrl)->assertOk();
        parse_str((string) parse_url($verifyUrl, PHP_URL_QUERY), $query);

        $this->post('/customer/auth/login/social/verify-account', ['identity' => $query['identity'], 'type' => '', 'token' => self::OTP])->assertRedirect(route('home'));

        $account = User::query()->where('email', self::OWN_EMAIL)->firstOrFail();
        $this->assertAuthenticatedAs($account, 'customer');
        $this->assertSame(self::OWN_PHONE, $account->phone);
        $this->assertSame(1, (int) $account->is_phone_verified);
    }

    public function test_the_code_can_be_sent_again_from_the_page(): void
    {
        $pageUrl = $this->signsUp()->json('redirect_url');
        DB::table('phone_or_email_verifications')->update(['created_at' => now()->subMinutes(5), 'token' => '999999']);

        $this->post(route('customer.auth.resend_otp'), ['identity' => base64_encode(self::OWN_PHONE), 'type' => base64_encode('phone_verification')])->assertRedirect();

        $this->assertDatabaseHas('phone_or_email_verifications', ['phone_or_email' => self::OWN_PHONE, 'token' => self::OTP]);
        $this->get($pageUrl)->assertOk();
        $this->entersTheCode(pageUrl: $pageUrl)->assertRedirect(route('home'));
        $this->assertAuthenticatedAs(User::query()->where('phone', self::OWN_PHONE)->firstOrFail(), 'customer');
    }
}
