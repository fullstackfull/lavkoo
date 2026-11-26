<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

/**
 * A provider callback is only this browser's if it carries the `state` this browser's session is waiting for (A2-04).
 *
 * The callback reads the profile with `stateless()`, which is Socialite's way of being told not to compare the state at all, so a
 * callback another site forged -- or one captured and replayed -- was handled exactly like one the store had started. The redirect
 * writes a state into the session already; nothing read it back.
 *
 * `gcc.identity.oauth_callback_state.checked` is off as it ships, and the first test here is that with it off the callback behaves
 * exactly as it does today: no state anywhere and the customer is signed in. With it on, a state that is absent, stale or not this
 * browser's is refused BEFORE the provider is asked anything, which is what `shouldNotReceive('user')` pins; and the state is spent on
 * use, so the same callback cannot be sent twice.
 */
class OAuthCallbackStateTest extends CustomerAuthTestCase
{
    private const CUSTOMER_EMAIL = 'layla.customer@example.test';
    private const SESSION_STATE = 'the-state-the-redirect-wrote';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCustomer(['email' => self::CUSTOMER_EMAIL, 'login_medium' => 'google', 'social_id' => 'google-uid-1']);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function checkTheCallbackState(): void
    {
        config(['gcc.identity.oauth_callback_state.checked' => true]);
    }

    /** Google answers with the customer's profile. The mock also records whether it was asked at all. */
    private function fakeGoogleReturning(string $email): void
    {
        $profile = ['id' => 'google-uid-1', 'name' => 'Layla Customer', 'email' => $email];
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn((new SocialiteUser())->setRaw($profile)->map($profile));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    /** Google must not be asked anything: a callback that is refused is refused before the authorization code is spent. */
    private function expectGoogleIsNeverAsked(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldNotReceive('user');
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    /** @param array<string, string> $query what the provider sends back in the callback URL */
    private function returnFromGoogle(array $query = [], array $session = []): TestResponse
    {
        $query += ['service' => 'google', 'code' => 'authorization-code'];
        $_SERVER['REQUEST_URI'] = '/customer/auth/login/google/callback?' . http_build_query($query);

        return $this->withSession($session)->get(route('customer.auth.service-callback', $query));
    }

    private function customerCount(): int
    {
        return DB::table('users')->count();
    }

    public function test_with_the_switch_off_a_callback_with_no_state_at_all_signs_the_customer_in_as_today(): void
    {
        $this->fakeGoogleReturning(email: self::CUSTOMER_EMAIL);

        $callback = $this->returnFromGoogle();

        $callback->assertRedirect();
        $this->assertAuthenticated('customer');
        $this->assertSame(self::CUSTOMER_EMAIL, auth('customer')->user()->email);
    }

    public function test_the_state_the_redirect_wrote_lets_the_callback_through(): void
    {
        $this->checkTheCallbackState();
        $this->fakeGoogleReturning(email: self::CUSTOMER_EMAIL);

        $this->returnFromGoogle(query: ['state' => self::SESSION_STATE], session: ['state' => self::SESSION_STATE]);

        $this->assertAuthenticated('customer');
    }

    public function test_a_callback_whose_state_is_not_the_one_in_the_session_is_refused_and_the_provider_is_never_asked(): void
    {
        $this->checkTheCallbackState();
        $this->expectGoogleIsNeverAsked();

        $callback = $this->returnFromGoogle(query: ['state' => 'a-state-another-site-made-up'], session: ['state' => self::SESSION_STATE]);

        $callback->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    public function test_a_callback_that_carries_no_state_is_refused_once_the_state_is_checked(): void
    {
        $this->checkTheCallbackState();
        $this->expectGoogleIsNeverAsked();

        $callback = $this->returnFromGoogle(session: ['state' => self::SESSION_STATE]);

        $callback->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    public function test_a_callback_that_no_redirect_of_this_browser_started_is_refused(): void
    {
        $this->checkTheCallbackState();
        $this->expectGoogleIsNeverAsked();

        $callback = $this->returnFromGoogle(query: ['state' => self::SESSION_STATE]);

        $callback->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    public function test_an_empty_state_on_both_sides_is_not_a_match(): void
    {
        $this->checkTheCallbackState();
        $this->expectGoogleIsNeverAsked();

        $callback = $this->returnFromGoogle(query: ['state' => ''], session: ['state' => '']);

        $callback->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    public function test_a_refused_callback_creates_no_account_and_leaves_no_pending_social_login(): void
    {
        $this->checkTheCallbackState();
        $this->expectGoogleIsNeverAsked();
        $customersBefore = $this->customerCount();

        $this->returnFromGoogle(query: ['state' => 'forged', 'code' => 'authorization-code'], session: ['state' => self::SESSION_STATE]);

        $this->assertSame($customersBefore, $this->customerCount());
        $this->assertNull(session('social_login_new_customer'));
    }

    public function test_the_state_is_spent_so_the_same_callback_cannot_be_sent_twice(): void
    {
        $this->checkTheCallbackState();
        $this->fakeGoogleReturning(email: self::CUSTOMER_EMAIL);

        $this->withSession(['state' => self::SESSION_STATE]);
        $_SERVER['REQUEST_URI'] = '/customer/auth/login/google/callback?code=authorization-code&state=' . self::SESSION_STATE;
        $url = route('customer.auth.service-callback', ['service' => 'google', 'code' => 'authorization-code', 'state' => self::SESSION_STATE]);

        $this->get($url);
        $this->assertAuthenticated('customer');

        auth('customer')->logout();
        $this->get($url)->assertRedirect(route('customer.auth.login'));
        $this->assertGuest('customer');
    }

    /** The other half of the mechanism: the redirect must stay stateful, because `stateless()` there writes no state to compare. */
    public function test_the_redirect_to_the_provider_is_not_stateless(): void
    {
        $driver = Mockery::mock();
        $driver->shouldNotReceive('stateless');
        $driver->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.test/authorize'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get(route('customer.auth.service-login', ['service' => 'google']))
            ->assertRedirect('https://accounts.google.test/authorize');
    }

    public function test_an_account_is_not_taken_over_by_a_forged_callback(): void
    {
        $this->checkTheCallbackState();
        $this->expectGoogleIsNeverAsked();
        $before = User::query()->where('email', self::CUSTOMER_EMAIL)->firstOrFail()->getAttributes();

        $this->returnFromGoogle(query: ['state' => 'forged'], session: ['state' => self::SESSION_STATE]);

        $this->assertSame($before, User::query()->where('email', self::CUSTOMER_EMAIL)->firstOrFail()->getAttributes());
    }
}
