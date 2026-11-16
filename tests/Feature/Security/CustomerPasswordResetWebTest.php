<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The website's twin of the app's reset: forgot-password sends the code, verify-recover-password takes it, and reset-password takes the
 * new password with the code it was given. What the page carried from one step to the next was looked up in phone_or_email_verifications,
 * the table anybody can write through the app's firebase-auth-token-store, and the customer was found by an identity that '0' turns into
 * "the first customer of the shop". A row anyone could write was enough to choose the password of any customer, and the Firebase
 * session the page verified with Google was never checked to belong to the customer's phone.
 */
class CustomerPasswordResetWebTest extends CustomerPasswordResetTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        View::share('web_config', ['firebase_otp_verification' => null]);
    }

    private function requestCode(string $phone): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->post('/customer/auth/forgot-password', ['identity' => $phone]);
    }

    private function resendCode(string $phone): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->post('/customer/auth/resend-otp-reset-password', ['identity' => base64_encode($phone)]);
    }

    private function enterCode(string $identity, string|int $code): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->post('/customer/auth/verify-recover-password', [
            'identity' => base64_encode($identity), 'type' => base64_encode('phone_verification'), 'token' => $code,
        ]);
    }

    private function openTheResetPage(string $identity, string $token): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->get('/customer/auth/reset-password?' . http_build_query(['identity' => base64_encode($identity), 'token' => $token]));
    }

    private function submitNewPassword(string $identity, string $token): TestResponse
    {
        return $this->from('/customer/auth/recover-password')->post('/customer/auth/reset-password', [
            'identity' => base64_encode($identity), 'reset_token' => $token, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD,
        ]);
    }

    /** The check the website's sign-in makes: the customer guard, with the phone and the password. */
    private function canSignInOnTheWebsite(string $phone, string $password): bool
    {
        $signedIn = Auth::guard('customer')->once(['phone' => $phone, 'password' => $password]);
        Auth::forgetGuards();

        return $signedIn;
    }

    private function storeFirebaseSession(string|int $identity, string $session): TestResponse
    {
        return $this->postJson('/api/v1/auth/firebase-auth-token-store', ['identity' => $identity, 'token' => $session]);
    }

    /** What the page does after the right code was typed: it sends the browser on to the page where the new password is typed. */
    private function assertSentOnToTheResetPage(TestResponse $response): array
    {
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/customer/auth/reset-password', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function assertNotSentOnToTheResetPage(TestResponse $response): void
    {
        $this->assertStringNotContainsString('/customer/auth/reset-password', (string) $response->headers->get('Location'));
    }

    private function assertPasswordWasChanged(TestResponse $response): void
    {
        $response->assertRedirect('/');
    }

    private function assertPasswordWasNotChanged(TestResponse $response): void
    {
        $response->assertRedirect('/customer/auth/recover-password');
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Nobody can make up a proof
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_token_that_anyone_stored_resets_nothing_on_the_website(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, 'attacker-chosen'));
        $this->assertKeepsPassword($victim);
        $this->openTheResetPage(self::OWNER_PHONE, 'attacker-chosen')->assertRedirect('/customer/auth/recover-password');
    }

    public function test_the_identity_zero_does_not_choose_the_first_customer_of_the_shop(): void
    {
        $first = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $second = $this->customer('+966500000002', 'second@example.test');

        $this->storeFirebaseSession(0, 'attacker-chosen')->assertOk();

        $this->assertPasswordWasNotChanged($this->submitNewPassword('0', 'attacker-chosen'));
        $this->assertKeepsPassword($first, $second);
    }

    public function test_the_identity_zero_sends_no_code_to_the_first_customer_of_the_shop(): void
    {
        $this->textMessagesThroughZender();
        $first = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->requestCode('0')->assertRedirect('/customer/auth/recover-password');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('password_resets')->count());
        $this->assertKeepsPassword($first);
    }

    public function test_a_row_of_the_shared_verification_table_cannot_be_verified_or_redeemed(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => '123456', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, '123456'));
        $this->openTheResetPage(self::OWNER_PHONE, '123456')->assertRedirect('/customer/auth/recover-password');
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, '123456'));
        $this->assertKeepsPassword($victim);
    }

    public function test_a_stranger_overwriting_the_code_the_page_sent_does_not_make_the_code_the_stranger_chose_a_proof(): void
    {
        $this->textMessagesThroughZender();
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->requestCode(self::OWNER_PHONE);
        DB::table('phone_or_email_verifications')->where('phone_or_email', self::OWNER_PHONE)->update(['token' => '654321']);

        $this->assertNotSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, '654321'));
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, '654321'));
        $this->assertKeepsPassword($victim);
    }

    public function test_a_session_google_issued_to_another_phone_proves_nothing_about_this_one_on_the_website(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->firebaseKnows(['attacker-own-session' => ['phone' => '+15005550006', 'code' => '246802']]);
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => 'attacker-own-session', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNotSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, '246802'));
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, 'attacker-own-session'));
        $this->assertKeepsPassword($victim);
    }

    public function test_the_code_page_does_not_send_a_wrong_code_on_to_the_reset_page(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->requestCode(self::OWNER_PHONE);

        $this->assertNotSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, '000000'));
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, '000000'));
        $this->assertKeepsPassword($owner);
    }

    public function test_a_wildcard_identity_verifies_and_redeems_nothing(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->requestCode(self::OWNER_PHONE);
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        foreach (['%', '_', '+9665%', '500000001'] as $pattern) {
            $this->assertNotSentOnToTheResetPage($this->enterCode($pattern, $code));
            $this->openTheResetPage($pattern, $code)->assertRedirect('/customer/auth/recover-password');
            $this->assertPasswordWasNotChanged($this->submitNewPassword($pattern, $code));
        }
        $this->assertKeepsPassword($owner);
    }

    public function test_the_legacy_code_page_does_not_accept_a_code_for_nobody_in_particular(): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '111111');

        $response = $this->from('/customer/auth/otp-verification')->post('/customer/auth/otp-verification', ['otp' => '111111']);

        $this->assertNotSentOnToTheResetPage($response);
    }

    public function test_the_legacy_code_page_takes_the_code_of_the_customer_who_asked_for_it(): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '111111');

        $response = $this->withSession(['forgot_password_identity' => self::OWNER_PHONE])->from('/customer/auth/otp-verification')->post('/customer/auth/otp-verification', ['otp' => '111111']);

        $this->assertSentOnToTheResetPage($response);
    }

    public function test_a_reset_by_one_customer_spends_nobody_elses_code(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $other = $this->customer('+966500000002', 'other@example.test');
        foreach ([self::OWNER_PHONE, '+966500000002'] as $phone) {
            $this->issueCode($phone, '112233');
            DB::table('phone_or_email_verifications')->insert(['phone_or_email' => $phone, 'token' => '112233', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertPasswordWasChanged($this->submitNewPassword(self::OWNER_PHONE, '112233'));

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($other);
        $this->assertSame(['+966500000002'], DB::table('password_resets')->pluck('identity')->all());
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // The pages still work, end to end
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_texted_code_flow_of_the_website_still_resets_the_password(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_email_verified' => 0]);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');
        $this->signInTheApp($owner);
        $this->signInTheApp($bystander);

        $this->requestCode(self::OWNER_PHONE)->assertRedirect(route('customer.auth.login.verify-account', [
            'identity' => base64_encode(self::OWNER_PHONE), 'type' => base64_encode('phone_verification'), 'action' => base64_encode('password-reset'),
        ]));
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        $query = $this->assertSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, $code));
        $this->openTheResetPage(base64_decode($query['identity']), $query['token'])->assertOk()->assertSee($query['token'], false);
        $this->assertPasswordWasChanged($this->submitNewPassword(base64_decode($query['identity']), $query['token']));

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->assertSame(1, DB::table('users')->where('id', $owner)->value('is_email_verified'));
        $this->assertSame(0, $this->tokensOf($owner), 'the reset ends the sessions of the customer who reset');
        $this->assertSame(1, $this->tokensOf($bystander), 'and nobody else\'s');
        $this->assertTrue($this->canSignInOnTheWebsite(self::OWNER_PHONE, self::NEW_PASSWORD));
        $this->assertFalse($this->canSignInOnTheWebsite(self::OWNER_PHONE, self::OLD_PASSWORD));
        $this->loginWithTheApp(self::OWNER_PHONE, self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->loginWithTheApp(self::OWNER_PHONE, self::OLD_PASSWORD)->assertStatus(403);
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, $code));
    }

    public function test_the_code_sent_again_by_the_website_resets_the_password(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->requestCode(self::OWNER_PHONE);
        $this->travel(2)->seconds();
        $this->resendCode(self::OWNER_PHONE);
        $messages = Http::recorded(fn ($request): bool => $request['phone'] === self::OWNER_PHONE);
        $this->assertCount(2, $messages);
        preg_match('/\d{6}/', $messages->last()[0]['message'], $code);

        $query = $this->assertSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, $code[0]));
        $this->assertPasswordWasChanged($this->submitNewPassword(base64_decode($query['identity']), $query['token']));

        $this->assertTookPassword($owner);
    }

    public function test_the_link_the_app_mailed_opens_the_reset_page_of_the_website(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');

        $this->postJson('/api/v1/auth/forgot-password', ['email_or_phone' => self::OWNER_EMAIL, 'type' => 'email'])->assertOk();
        $token = $this->resetTokenMailedTo(self::OWNER_EMAIL);
        $identity = base64_decode($this->resetIdentityMailedTo(self::OWNER_EMAIL));

        $this->openTheResetPage($identity, $token)->assertOk();
        $this->assertPasswordWasChanged($this->submitNewPassword($identity, $token));

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->assertTrue($this->canSignInOnTheWebsite(self::OWNER_PHONE, self::NEW_PASSWORD));
        $this->loginWithTheApp(self::OWNER_EMAIL, self::NEW_PASSWORD, 'email')->assertOk()->assertJsonStructure(['token']);
        $this->assertPasswordWasNotChanged($this->submitNewPassword($identity, $token));
    }

    public function test_the_firebase_flow_of_the_website_still_resets_the_password(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');
        $session = str_repeat(self::SESSION, 12);
        $this->firebaseKnows([$session => ['phone' => self::OWNER_PHONE, 'code' => '482915']], [self::OWNER_PHONE => $session]);

        $this->requestCode(self::OWNER_PHONE)->assertRedirect();
        $query = $this->assertSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, '482915'));
        $this->openTheResetPage(base64_decode($query['identity']), $query['token'])->assertOk();
        $this->assertPasswordWasChanged($this->submitNewPassword(base64_decode($query['identity']), $query['token']));

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->assertTrue($this->canSignInOnTheWebsite(self::OWNER_PHONE, self::NEW_PASSWORD));
        $this->loginWithTheApp(self::OWNER_PHONE, self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, $query['token']));
    }

    public function test_a_wrong_code_does_not_pass_the_firebase_page(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']], [self::OWNER_PHONE => self::SESSION]);
        $this->requestCode(self::OWNER_PHONE);

        $this->assertNotSentOnToTheResetPage($this->enterCode(self::OWNER_PHONE, '000000'));
        $this->assertPasswordWasNotChanged($this->submitNewPassword(self::OWNER_PHONE, self::SESSION));
        $this->assertKeepsPassword($owner);
    }

    /** @return array<string, array{string, string}> what the customer registered with, and what Google calls the same Saudi number */
    public static function sameSaudiNumberWrittenDifferently(): array
    {
        return [
            'registered with the trunk zero' => ['+9660500000001', '+966500000001'],
            'registered the way Google writes it' => ['+966500000001', '+966500000001'],
            'another Gulf number registered with the trunk zero' => ['+9710501234567', '+971501234567'],
        ];
    }

    #[DataProvider('sameSaudiNumberWrittenDifferently')]
    public function test_google_writing_the_same_saudi_number_differently_still_verifies_the_customer_on_the_website(string $registeredPhone, string $googlesPhone): void
    {
        $owner = $this->customer($registeredPhone, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => $googlesPhone, 'code' => '482915']], [$registeredPhone => self::SESSION]);

        $this->requestCode($registeredPhone);
        $query = $this->assertSentOnToTheResetPage($this->enterCode($registeredPhone, '482915'));
        $this->assertPasswordWasChanged($this->submitNewPassword(base64_decode($query['identity']), $query['token']));

        $this->assertTookPassword($owner);
    }
}
