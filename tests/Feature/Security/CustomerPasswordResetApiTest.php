<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v1\auth\ForgotPasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A customer's password can only be reset by the person the proof was issued to, and the reset changes that one customer and nobody else.
 *
 * The app's reset endpoint matched the identity with LIKE '%identity%' everywhere: a proof was found for any identity that merely contained
 * the typed text, and the password UPDATE then hit every customer whose phone or email contained it. `%` is a legal identity, and
 * firebase-auth-token-store, which takes any identity and any token from anyone, wrote the table that reset-password accepted as proof:
 * without any account, a victim's phone number (or `%`, for every customer) and a made-up token were enough. firebase-auth-verify, the
 * only step that asks Google whether the code was right, wrote the proof for the phone the caller typed and not for the phone Google had
 * verified, so a session of the caller's own phone was proof for the victim's.
 */
class CustomerPasswordResetApiTest extends CustomerPasswordResetTestCase
{
    private function forgotPassword(string|int $identity, string $type = 'phone'): TestResponse
    {
        return $this->postJson('/api/v1/auth/forgot-password', ['email_or_phone' => $identity, 'type' => $type]);
    }

    private function verifyToken(string|int $identity, string|int $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/verify-token', ['email_or_phone' => $identity, 'reset_token' => $code]);
    }

    private function resetPassword(string|int $identity, string|int $code): TestResponse
    {
        return $this->putJson('/api/v1/auth/reset-password', [
            'identity' => $identity, 'otp' => $code, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD,
        ]);
    }

    /** Exactly what the customer app sends to reset: a POST that carries `_method: put`, with the code the customer typed in `otp`. */
    private function resetPasswordTheWayTheAppDoes(string $identity, string $code): TestResponse
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            '_method' => 'put', 'identity' => $identity, 'otp' => $code, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD,
        ]);
    }

    private function storeFirebaseSession(string|int $identity, string $session): TestResponse
    {
        return $this->postJson('/api/v1/auth/firebase-auth-token-store', ['identity' => $identity, 'token' => $session]);
    }

    private function verifyFirebaseCode(string $phone, string $session, string $code, int $isResetToken = 1): TestResponse
    {
        return $this->postJson('/api/v1/auth/firebase-auth-verify', ['sessionInfo' => $session, 'phoneNumber' => $phone, 'code' => $code, 'is_reset_token' => $isResetToken]);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // The identity is matched exactly: no pattern, no fragment, no second customer
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_wildcard_identity_redeems_nothing(): void
    {
        $attacker = $this->customer('+966599999999', 'attacker@example.test');
        $victim = $this->customer(self::OWNER_PHONE, 'victim@example.test');
        $bystander = $this->customer('+966500000002', 'bystander@example.test');
        $this->issueCode('+966599999999', '246810');

        foreach (['%', '_', '%%%%%%', '+9665%', '+96659999999_'] as $pattern) {
            $this->resetPassword($pattern, '246810')->assertStatus(400);

            $this->assertKeepsPassword($attacker, $victim, $bystander);
        }
    }

    public function test_a_fragment_of_the_identity_redeems_nothing_and_the_whole_identity_does(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '135790');

        foreach (['500000001', '+96650000', '0000001', '966500000001'] as $fragment) {
            $this->resetPassword($fragment, '135790')->assertStatus(400);
        }
        $this->assertKeepsPassword($owner);

        $this->resetPassword(self::OWNER_PHONE, '135790')->assertOk();
        $this->assertTookPassword($owner);
    }

    public function test_a_reset_changes_only_the_customer_the_code_was_sent_to(): void
    {
        $longerNumber = $this->customer(self::OWNER_PHONE . '1', 'longer@example.test');
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '112233');

        $this->resetPassword(self::OWNER_PHONE, '112233')->assertOk();

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($longerNumber);
    }

    public function test_a_code_issued_to_one_customer_resets_no_other_customer(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $other = $this->customer('+966500000002', 'other@example.test');
        $this->issueCode(self::OWNER_PHONE, '778899');

        $this->resetPassword('+966500000002', '778899')->assertStatus(400);

        $this->assertKeepsPassword($owner, $other);
    }

    public function test_a_reset_is_refused_when_two_customers_share_the_identity(): void
    {
        $first = $this->customer(self::OWNER_PHONE, 'first@example.test');
        $second = $this->customer(self::OWNER_PHONE, 'second@example.test');
        $this->issueCode(self::OWNER_PHONE, '556677');

        $this->resetPassword(self::OWNER_PHONE, '556677')->assertStatus(400);
        $this->verifyToken(self::OWNER_PHONE, '556677')->assertStatus(403);

        $this->assertKeepsPassword($first, $second);
    }

    public function test_a_numeric_zero_is_not_a_wildcard(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $emailOnly = $this->customer('', 'email-only@example.test');
        $this->issueCode(self::OWNER_EMAIL, 'EmailedTokenValue');

        $this->resetPassword(0, 0)->assertStatus(400);
        $this->verifyToken(0, 0)->assertStatus(403);
        $this->forgotPassword(0)->assertStatus(401);

        $this->assertKeepsPassword($owner, $emailOnly);
        $this->assertSame(1, DB::table('password_resets')->count());
    }

    public function test_verify_token_matches_the_identity_exactly(): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '314159');

        $this->verifyToken('%', '314159')->assertStatus(403);
        $this->verifyToken('500000001', '314159')->assertStatus(403);
        $this->verifyToken(self::OWNER_PHONE, '000000')->assertStatus(403);
        $this->verifyToken(self::OWNER_PHONE, '314159')->assertOk()->assertJson(['message' => translate('otp_verified')]);
    }

    public function test_forgot_password_with_a_wildcard_neither_finds_a_customer_nor_discards_pending_codes(): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '445566');

        $this->forgotPassword('%%%%%%')->assertStatus(401);

        $this->assertSame(['445566'], DB::table('password_resets')->pluck('token')->all(), 'the code issued to the owner is still the only one');
    }

    public function test_forgot_password_sends_the_code_to_the_customer_with_exactly_that_number(): void
    {
        $this->textMessagesThroughZender();
        $this->customer(self::OWNER_PHONE . '1', 'longer@example.test');
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->forgotPassword(self::OWNER_PHONE)->assertOk();

        $this->assertSame([self::OWNER_PHONE], DB::table('password_resets')->pluck('identity')->all());
        $this->codeTextedTo(self::OWNER_PHONE);
    }

    public function test_forgot_password_sends_no_code_when_two_customers_share_the_phone(): void
    {
        $this->textMessagesThroughZender();
        $this->customer(self::OWNER_PHONE, 'first@example.test');
        $this->customer(self::OWNER_PHONE, 'second@example.test');

        $this->forgotPassword(self::OWNER_PHONE)->assertStatus(401);

        $this->assertSame(0, DB::table('password_resets')->count());
        Http::assertNothingSent();
    }

    public function test_the_request_that_the_routes_do_not_reach_finds_no_customer_with_a_wildcard(): void
    {
        $this->textMessagesThroughZender();
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $response = $this->app->make(ForgotPasswordController::class)->reset_password_request(Request::create('/', 'POST', ['identity' => '%']));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, DB::table('password_resets')->count());
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Both ways of being told the code still work, end to end
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_phone_forgot_password_then_verify_token_then_reset_changes_the_password(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');

        $this->forgotPassword(self::OWNER_PHONE)->assertOk()->assertJson(['type' => 'sent_to_phone']);
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        $this->verifyToken(self::OWNER_PHONE, $code)->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, $code)->assertOk()->assertJson(['message' => translate('password_changed_successfully')]);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->loginWithTheApp(self::OWNER_PHONE, self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->loginWithTheApp(self::OWNER_PHONE, self::OLD_PASSWORD)->assertStatus(403);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, $code)->assertStatus(400);
    }

    public function test_email_forgot_password_then_reset_with_the_mailed_token_changes_the_password(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');

        $this->forgotPassword(self::OWNER_EMAIL, 'email')->assertOk()->assertJson(['type' => 'sent_to_mail']);
        $token = $this->resetTokenMailedTo(self::OWNER_EMAIL);

        $this->resetPassword(self::OWNER_EMAIL, $token)->assertOk()->assertJson(['message' => translate('password_changed_successfully')]);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->loginWithTheApp(self::OWNER_EMAIL, self::NEW_PASSWORD, 'email')->assertOk()->assertJsonStructure(['token']);
        $this->resetPassword(self::OWNER_EMAIL, $token)->assertStatus(400);
    }

    public function test_a_reset_ends_the_sessions_of_the_customer_who_reset_and_nobody_elses(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');
        $this->signInTheApp($owner);
        $this->signInTheApp($owner);
        $this->signInTheApp($bystander);
        $this->issueCode(self::OWNER_PHONE, '112233');

        $this->resetPassword(self::OWNER_PHONE, '112233')->assertOk();

        $this->assertSame(0, $this->tokensOf($owner), 'the reset ends the sessions of the customer who reset');
        $this->assertSame(1, $this->tokensOf($bystander), 'and nobody else\'s');
    }

    public function test_a_reset_spends_every_code_issued_to_that_customer(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '111111');
        $this->issueCode(self::OWNER_EMAIL, 'LinkTokenLinkToken');

        $this->resetPassword(self::OWNER_PHONE, '111111')->assertOk();
        $this->assertTookPassword($owner);

        $this->resetPassword(self::OWNER_EMAIL, 'LinkTokenLinkToken')->assertStatus(400);
        $this->assertSame(0, DB::table('password_resets')->count());
    }

    public function test_a_password_that_does_not_match_its_confirmation_is_refused_before_anything_is_looked_up(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '112233');

        $this->putJson('/api/v1/auth/reset-password', [
            'identity' => self::OWNER_PHONE, 'otp' => '112233', 'password' => self::NEW_PASSWORD, 'confirm_password' => 'something-else-1',
        ])->assertStatus(403);

        $this->assertKeepsPassword($owner);
        $this->assertSame(1, DB::table('password_resets')->count());
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Customers, sellers and delivery men share the table, and a code is only ever the code of the kind it was issued for
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_code_issued_to_a_seller_with_the_same_phone_resets_no_customer(): void
    {
        $customer = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '778899', userType: 'seller');

        $this->resetPassword(self::OWNER_PHONE, '778899')->assertStatus(400);
        $this->verifyToken(self::OWNER_PHONE, '778899')->assertStatus(403);

        $this->assertKeepsPassword($customer);
    }

    public function test_a_customers_forgot_password_does_not_overwrite_the_code_of_a_seller_with_the_same_phone(): void
    {
        $this->textMessagesThroughZender();
        $customer = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '999999', userType: 'seller');

        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        $this->assertSame('999999', DB::table('password_resets')->where('user_type', 'seller')->value('token'), 'the seller\'s code is still the seller\'s');
        $this->resetPassword(self::OWNER_PHONE, $code)->assertOk();
        $this->assertTookPassword($customer);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Nobody can make up a proof: firebase-auth-token-store takes anything from anyone, so what it stores proves nothing
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_token_that_anyone_stored_resets_nothing(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->resetPassword(self::OWNER_PHONE, 'attacker-chosen')->assertStatus(400);
        $this->assertKeepsPassword($victim);
    }

    public function test_a_stored_token_cannot_be_redeemed_with_a_wildcard_for_every_customer(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');

        $this->storeFirebaseSession('%', 'attacker-chosen')->assertOk();
        $this->storeFirebaseSession(0, 'attacker-chosen-too')->assertOk();

        $this->resetPassword('%', 'attacker-chosen')->assertStatus(400);
        $this->resetPassword(0, 'attacker-chosen-too')->assertStatus(400);
        $this->assertKeepsPassword($victim, $bystander);
    }

    public function test_a_row_of_the_shared_verification_table_is_no_proof_of_a_reset(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => '123456', 'created_at' => now(), 'updated_at' => now()]);

        $this->resetPassword(self::OWNER_PHONE, '123456')->assertStatus(400);
        $this->verifyToken(self::OWNER_PHONE, '123456')->assertStatus(403);

        $this->assertKeepsPassword($victim);
    }

    public function test_a_stored_token_cannot_log_anyone_in(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();
        $this->storeFirebaseSession(self::OWNER_EMAIL, 'attacker-chosen')->assertOk();

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => self::OWNER_PHONE, 'token' => 'attacker-chosen'])->assertStatus(403)->assertJsonMissingPath('token');
        $this->postJson('/api/v1/auth/verify-phone', ['phone' => self::OWNER_PHONE, 'token' => 'attacker-chosen'])->assertStatus(403)->assertJsonMissingPath('token');
        $this->postJson('/api/v1/auth/verify-email', ['email' => self::OWNER_EMAIL, 'token' => 'attacker-chosen'])->assertStatus(403)->assertJsonMissingPath('token');

        $this->assertSame(0, $this->tokensOf($victim));
    }

    public function test_a_stranger_storing_a_token_does_not_overwrite_the_code_the_server_sent(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->postJson('/api/v1/auth/check-phone', ['phone' => self::OWNER_PHONE])->assertOk();
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'stranger-chosen')->assertOk();

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => self::OWNER_PHONE, 'token' => $code])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(1, $this->tokensOf($owner));
    }

    public function test_the_token_store_keeps_a_note_that_a_session_was_asked_for_and_not_what_the_caller_sent(): void
    {
        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk()->assertJson(['message' => translate('Token_is_successfully_Saved')]);
        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen-again')->assertOk();

        $this->assertSame(1, DB::table('phone_or_email_verifications')->where('phone_or_email', self::OWNER_PHONE)->count());
        $this->assertSame(0, DB::table('phone_or_email_verifications')->whereIn('token', ['attacker-chosen', 'attacker-chosen-again'])->count());
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // The Firebase flow: the proof is what Google verified, for the phone Google verified
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_firebase_flow_the_app_runs_still_resets_the_password(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $bystander = $this->customer('+966500000002', 'bystander@example.test');
        $session = str_repeat(self::SESSION, 12);
        $this->firebaseKnows([$session => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, $session)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, $session, '482915')->assertOk()->assertJsonStructure(['errors', 'temp_token']);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '482915')->assertOk()->assertJson(['message' => translate('password_changed_successfully')]);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->loginWithTheApp(self::OWNER_PHONE, self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '482915')->assertStatus(400);
    }

    public function test_a_code_is_no_proof_until_google_has_accepted_it(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '482915')->assertStatus(400);

        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '000000')->assertStatus(403);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '000000')->assertStatus(400);
        $this->assertKeepsPassword($owner);

        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '482915')->assertOk();
        $this->assertTookPassword($owner);
    }

    public function test_a_google_answer_that_does_not_name_the_phone_proves_nothing(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => null, 'code' => '482915']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '482915')->assertStatus(400);
        $this->assertKeepsPassword($owner);
    }

    /** @return array<string, array{string}> */
    public static function phonesThatAreNotTheOwners(): array
    {
        return [
            'a foreign number' => ['+15005550006'],
            'another Saudi number' => ['+966511111111'],
            'the same digits under another country code' => ['+971500000001'],
            'a number that only ends like the owner\'s' => ['+96600000001'],
        ];
    }

    #[DataProvider('phonesThatAreNotTheOwners')]
    public function test_a_session_google_issued_to_another_phone_proves_nothing_about_this_one(string $attackersPhone): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $attacker = $this->customer($attackersPhone, 'attacker@example.test');
        $this->firebaseKnows(['attacker-own-session' => ['phone' => $attackersPhone, 'code' => '246802']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-own-session')->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, 'attacker-own-session', '246802')->assertOk();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, '246802')->assertStatus(400);
        $this->assertKeepsPassword($victim);
        $this->assertSame(0, DB::table('password_resets')->where('identity', self::OWNER_PHONE)->count());
        $this->assertKeepsPassword($attacker);
    }

    /** @return array<string, array{string}> */
    public static function phonesThatAreNotTheOwnerOfANumberWithATrunkZero(): array
    {
        return [
            'another last digit' => ['+963912345679'],
            'the same digits under another country' => ['+961912345678'],
        ];
    }

    #[DataProvider('phonesThatAreNotTheOwnerOfANumberWithATrunkZero')]
    public function test_a_session_google_issued_to_another_phone_proves_nothing_about_a_number_registered_with_a_trunk_zero(string $attackersPhone): void
    {
        $victim = $this->customer(phone: '+9630912345678', email: self::OWNER_EMAIL);
        $this->firebaseKnows(sessions: ['attacker-own-session' => ['phone' => $attackersPhone, 'code' => '246802']]);

        $this->storeFirebaseSession(identity: '+9630912345678', session: 'attacker-own-session')->assertOk();
        $this->verifyFirebaseCode(phone: '+9630912345678', session: 'attacker-own-session', code: '246802')->assertOk();

        $this->resetPasswordTheWayTheAppDoes(identity: '+9630912345678', code: '246802')->assertStatus(400);
        $this->assertKeepsPassword($victim);
        $this->assertSame(0, DB::table('password_resets')->where('identity', '+9630912345678')->count());
    }

    public function test_a_session_google_issued_to_another_phone_does_not_log_in_as_this_one(): void
    {
        $victim = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $attacker = $this->customer('+15005550006', 'attacker@example.test');
        $this->firebaseKnows(['attacker-own-session' => ['phone' => '+15005550006', 'code' => '246802']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-own-session')->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, 'attacker-own-session', '246802', isResetToken: 0)->assertOk();

        $this->assertSame(0, $this->tokensOf($victim));
        $this->assertSame(1, $this->tokensOf($attacker), 'the attacker is signed in as themselves');
    }

    public function test_the_firebase_sign_in_the_app_runs_still_signs_the_customer_in(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915', isResetToken: 0)->assertOk()->assertJsonStructure(['errors', 'token']);

        $this->assertSame(1, $this->tokensOf($owner));
    }

    public function test_the_sign_in_by_a_code_the_server_texted_still_signs_the_customer_in(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->postJson('/api/v1/auth/check-phone', ['phone' => self::OWNER_PHONE])->assertOk();
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => self::OWNER_PHONE, 'token' => $code])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(1, $this->tokensOf($owner));
    }

    /** @return array<string, array{string, string}> what the customer registered with, and what Google calls the same number */
    public static function sameNumberWrittenDifferently(): array
    {
        return [
            'registered with the trunk zero' => ['+9660500000001', '+966500000001'],
            'registered in national format' => ['0500000001', '+966500000001'],
            'registered without the plus' => ['966500000001', '+966500000001'],
            'registered the way Google writes it' => ['+966500000001', '+966500000001'],
            'a Syrian number registered with the trunk zero' => ['+9630912345678', '+963912345678'],
            'an Egyptian number registered with the trunk zero' => ['+2001012345678', '+201012345678'],
        ];
    }

    #[DataProvider('sameNumberWrittenDifferently')]
    public function test_google_writing_the_same_number_differently_still_verifies_the_customer(string $registeredPhone, string $googlesPhone): void
    {
        $owner = $this->customer($registeredPhone, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => $googlesPhone, 'code' => '482915']]);

        $this->storeFirebaseSession($registeredPhone, self::SESSION)->assertOk();
        $this->verifyFirebaseCode($registeredPhone, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes($registeredPhone, '482915')->assertOk();

        $this->assertTookPassword($owner);
    }

    public function test_a_verified_phone_belongs_to_its_customer_only(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $other = $this->customer('+966500000002', 'other@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->resetPasswordTheWayTheAppDoes('+966500000002', '482915')->assertStatus(400);
        $this->resetPasswordTheWayTheAppDoes('%', '482915')->assertStatus(400);

        $this->assertKeepsPassword($owner, $other);
    }

    public function test_storing_another_token_cannot_ride_on_a_verified_phone(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, 'attacker-chosen')->assertStatus(400);
        $this->assertKeepsPassword($owner);
    }
}
