<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v1\auth\CustomerAPIAuthController;
use App\Http\Controllers\RestAPI\v1\auth\SocialAuthController;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRestCustomerAuthSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * "Is it you?": a social login that finds an account already holding the provider's email asks the person to confirm it is theirs. The answer
 * is only taken from the person the provider has just vouched for: social-customer-login hands that person a temporary token, and
 * existing-account-check does nothing without it. Anyone who only knows an email address gets no token for the account and cannot take its
 * email away.
 *
 * translate() writes a phrase it does not know into the tracked language files; they are put back after every test.
 */
class ExistingAccountCheckTest extends TestCase
{
    use BuildsRestCustomerAuthSchema;
    use RestoresLanguageFiles;

    private const SOCIAL_LOGIN_URI = '/api/v1/auth/social-customer-login';

    private const CONFIRMATION_URI = '/api/v1/auth/existing-account-check';

    private const VICTIM_EMAIL = 'victim@example.test';

    private const VICTIM_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildRestCustomerAuthSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, array{int}> */
    public static function answers(): array
    {
        return ['yes, it is me' => [1], 'no, it is not me' => [0]];
    }

    private function makeCustomer(array $attributes = []): User
    {
        $attributes += [
            'id' => self::VICTIM_ID,
            'name' => 'Layla Victim',
            'f_name' => 'Layla',
            'l_name' => 'Victim',
            'email' => self::VICTIM_EMAIL,
            'phone' => '0500000005',
            'password' => bcrypt('victim-password'),
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_email_verified' => 0,
            'login_medium' => null,
            'social_id' => null,
            'email_verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('users')->insert($attributes);

        return User::query()->findOrFail($attributes['id']);
    }

    /** The store asks every customer to verify the phone, which is what makes an account with an unverified phone need the confirmation. */
    private function requirePhoneVerification(): void
    {
        DB::table('login_setups')->insert(['key' => 'phone_verification', 'value' => json_encode(1), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function answerSocialLoginWith(Response $providerResponse): void
    {
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([$providerResponse]))]));
    }

    /** Google vouches for $email: its userinfo answer for the access token carries that address. The caller says it is $claimedEmail. */
    private function signInWithGoogleAs(string $email = self::VICTIM_EMAIL, ?string $claimedEmail = null): TestResponse
    {
        $claimedEmail ??= $email;
        $this->answerSocialLoginWith(new Response(200, [], json_encode(['sub' => 'google-uid-1', 'email' => $email, 'email_verified' => true, 'name' => 'Layla Victim'])));

        return $this->postJson(self::SOCIAL_LOGIN_URI, [
            'token' => 'google-access-token',
            'unique_id' => 'google-uid-1',
            'email' => $claimedEmail,
            'medium' => 'google',
        ]);
    }

    /** The temporary token a customer who signed in with Google is given to answer "is it you?". */
    private function confirmationTokenFor(string $email = self::VICTIM_EMAIL): string
    {
        $token = $this->signInWithGoogleAs(email: $email)->json('temporary_token');
        $this->assertIsString($token);

        return $token;
    }

    private function answer(int $userResponse, ?string $temporaryToken, string $email = self::VICTIM_EMAIL, string $medium = 'google'): TestResponse
    {
        return $this->postJson(self::CONFIRMATION_URI, array_filter([
            'email' => $email,
            'user_response' => $userResponse,
            'medium' => $medium,
            'temporary_token' => $temporaryToken,
        ], static fn ($value) => $value !== null));
    }

    private function assertNoTokenWasIssued(TestResponse $response): void
    {
        $this->assertArrayNotHasKey('token', (array) $response->json());
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    private function assertAccountUntouched(int $id = self::VICTIM_ID, string $email = self::VICTIM_EMAIL): void
    {
        $account = DB::table('users')->where('id', $id)->first();

        $this->assertSame($email, $account->email);
        $this->assertNull($account->email_verified_at);
        $this->assertNull($account->login_medium);
    }

    private function accessTokenSubject(string $accessToken): string
    {
        [, $payload] = explode('.', $accessToken);

        return (string) json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['sub'];
    }

    public function test_a_caller_with_only_an_email_gets_no_token_for_the_account(): void
    {
        $this->makeCustomer();

        $response = $this->answer(userResponse: 1, temporaryToken: null);

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    public function test_a_caller_with_only_an_email_cannot_take_the_email_off_the_account(): void
    {
        $this->makeCustomer();

        $response = $this->answer(userResponse: 0, temporaryToken: null);

        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    #[DataProvider('answers')]
    public function test_a_made_up_temporary_token_changes_nothing(int $userResponse): void
    {
        $this->makeCustomer();

        $response = $this->answer(userResponse: $userResponse, temporaryToken: Str::random(40));

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    public function test_an_email_nobody_holds_gets_the_same_refusal_as_one_somebody_holds(): void
    {
        $this->makeCustomer();

        $held = $this->answer(userResponse: 1, temporaryToken: Str::random(40));
        $unheld = $this->answer(userResponse: 1, temporaryToken: Str::random(40), email: 'nobody@example.test');

        $this->assertNoTokenWasIssued($unheld);
        $this->assertSame($held->getStatusCode(), $unheld->getStatusCode());
        $this->assertSame($held->json(), $unheld->json());
    }

    #[DataProvider('answers')]
    public function test_the_token_of_one_customer_does_not_answer_for_another_account(int $userResponse): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $this->makeCustomer(['id' => 6, 'email' => 'bystander@example.test', 'phone' => '0500000006', 'is_phone_verified' => 0]);
        $temporaryToken = $this->confirmationTokenFor();

        $response = $this->answer(userResponse: $userResponse, temporaryToken: $temporaryToken, email: 'bystander@example.test');

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched(id: 6, email: 'bystander@example.test');
        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    #[DataProvider('answers')]
    public function test_the_token_of_a_google_login_does_not_answer_for_another_provider(int $userResponse): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $temporaryToken = $this->confirmationTokenFor();

        $response = $this->answer(userResponse: $userResponse, temporaryToken: $temporaryToken, medium: 'facebook');

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    #[DataProvider('answers')]
    public function test_the_token_does_not_answer_for_an_account_that_has_since_changed_its_email(int $userResponse): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $temporaryToken = $this->confirmationTokenFor();
        DB::table('users')->where('id', self::VICTIM_ID)->update(['email' => 'changed@example.test']);

        $response = $this->answer(userResponse: $userResponse, temporaryToken: $temporaryToken);

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched(email: 'changed@example.test');
        $response->assertForbidden();
    }

    public function test_the_token_cannot_be_used_after_ten_minutes(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $temporaryToken = $this->confirmationTokenFor();

        $this->travel(11)->minutes();
        $response = $this->answer(userResponse: 1, temporaryToken: $temporaryToken);

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    public function test_the_token_still_answers_after_nine_minutes(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $temporaryToken = $this->confirmationTokenFor();

        $this->travel(9)->minutes();
        $response = $this->answer(userResponse: 1, temporaryToken: $temporaryToken);

        $response->assertOk()->assertJsonPath('status', true);
    }

    public function test_the_token_answers_once(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $temporaryToken = $this->confirmationTokenFor();
        $this->answer(userResponse: 1, temporaryToken: $temporaryToken)->assertOk();

        $replay = $this->answer(userResponse: 0, temporaryToken: $temporaryToken);

        $replay->assertForbidden();
        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
        $this->assertSame(self::VICTIM_EMAIL, DB::table('users')->where('id', self::VICTIM_ID)->value('email'));
    }

    public function test_the_provider_email_can_be_confirmed_as_the_owner(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);

        $signIn = $this->signInWithGoogleAs(email: self::VICTIM_EMAIL);
        $signIn->assertOk()->assertJsonPath('status', false)->assertJsonPath('user.email', self::VICTIM_EMAIL);
        $this->assertArrayNotHasKey('token', $signIn->json());

        $answer = $this->answer(userResponse: 1, temporaryToken: $signIn->json('temporary_token'));

        $answer->assertOk()->assertJsonPath('status', true);
        $this->assertSame((string) self::VICTIM_ID, $this->accessTokenSubject($answer->json('token')));
        $account = DB::table('users')->where('id', self::VICTIM_ID)->first();
        $this->assertSame('google', $account->login_medium);
        $this->assertNotNull($account->email_verified_at);
    }

    public function test_the_provider_email_can_be_confirmed_as_not_the_owners_account(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0, 'email_verified_at' => now()]);
        $temporaryToken = $this->confirmationTokenFor();

        $answer = $this->answer(userResponse: 0, temporaryToken: $temporaryToken);

        $answer->assertOk()->assertJsonPath('status', false);
        $this->assertIsString($answer->json('temp_token'));
        $this->assertNoTokenWasIssued($answer);
        $account = DB::table('users')->where('id', self::VICTIM_ID)->first();
        $this->assertNull($account->email);
        $this->assertNull($account->email_verified_at);
    }

    public function test_the_app_may_send_the_email_in_another_letter_case_than_the_account_holds_it(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0, 'email' => 'Victim@Example.test']);
        $temporaryToken = $this->confirmationTokenFor(email: 'Victim@Example.test');

        $answer = $this->answer(userResponse: 1, temporaryToken: $temporaryToken, email: 'victim@example.test');

        $answer->assertOk()->assertJsonPath('status', true);
    }

    public function test_a_suspended_customer_is_not_given_a_token_even_with_the_token_of_the_login(): void
    {
        $this->makeCustomer(['is_active' => 0]);
        $temporaryToken = $this->confirmationTokenFor();

        $response = $this->answer(userResponse: 1, temporaryToken: $temporaryToken);

        $this->assertNoTokenWasIssued($response);
        $this->assertAccountUntouched();
        $response->assertForbidden();
    }

    public function test_a_caller_the_provider_does_not_vouch_for_is_given_no_token_to_answer_with(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);

        $response = $this->signInWithGoogleAs(email: 'attacker@example.test', claimedEmail: self::VICTIM_EMAIL);

        $response->assertForbidden();
        $this->assertArrayNotHasKey('temporary_token', $response->json());
    }

    public function test_a_google_token_the_provider_refuses_is_given_no_token_to_answer_with(): void
    {
        $this->requirePhoneVerification();
        $this->makeCustomer(['is_phone_verified' => 0]);
        $this->answerSocialLoginWith(new Response(401, [], json_encode(['error' => 'invalid_token'])));

        $response = $this->postJson(self::SOCIAL_LOGIN_URI, [
            'token' => 'stolen-or-made-up', 'unique_id' => 'google-uid-1', 'email' => self::VICTIM_EMAIL, 'medium' => 'google',
        ]);

        $response->assertUnauthorized();
        $this->assertArrayNotHasKey('temporary_token', $response->json());
    }

    public function test_a_customer_who_can_sign_in_directly_is_given_a_token_and_nothing_to_answer(): void
    {
        $this->makeCustomer();

        $signIn = $this->signInWithGoogleAs(email: self::VICTIM_EMAIL);

        $signIn->assertOk()->assertJsonPath('status', true);
        $this->assertSame((string) self::VICTIM_ID, $this->accessTokenSubject($signIn->json('token')));
        $this->assertArrayNotHasKey('temporary_token', $signIn->json());
    }

    public function test_the_endpoint_is_served_by_the_social_auth_controller_alone(): void
    {
        $route = app('router')->getRoutes()->match(Request::create(self::CONFIRMATION_URI, 'POST'));

        $this->assertSame(SocialAuthController::class . '@existingAccountCheck', $route->getActionName());
        $this->assertFalse(
            method_exists(CustomerAPIAuthController::class, 'existingAccountCheck'),
            'A second handler for the same endpoint is a way around the checks of the first the day the routes are reordered.',
        );
    }
}
