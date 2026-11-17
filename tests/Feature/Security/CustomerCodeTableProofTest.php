<?php

namespace Tests\Feature\Security;

use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;
use App\Models\PhoneOrEmailVerification;
use App\Utils\RequestText;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a customer types as a code is compared with the code that was sent, and with nothing else.
 *
 * firebase-auth-token-store leaves a row without a token for anybody (the note that a session was asked for). A sign-in that read the
 * table with `where token = ?` and took the request's token as it came matched that row when the request carried no token at all
 * (`IS NULL`), so two requests signed anybody in as the customer with a known phone or e-mail. Under MySQL the number 0 matches every
 * non-numeric token (a Firebase session) the same way. A token that is not text or a number matches nothing, and a number is compared
 * as the text it was typed as.
 */
class CustomerCodeTableProofTest extends CustomerPasswordResetTestCase
{
    private function storeASession(string $identity): TestResponse
    {
        return $this->postJson('/api/v1/auth/firebase-auth-token-store', ['identity' => $identity]);
    }

    /** @return array<string, array{0: array<string, mixed>}> what the request carries in place of the code */
    public static function noCode(): array
    {
        return [
            'no token at all' => [[]],
            'a null token' => [['token' => null]],
            'an empty token' => [['token' => '']],
            'a list' => [['token' => []]],
            'a list holding null' => [['token' => [null]]],
            'false' => [['token' => false]],
        ];
    }

    /** @param array<string, mixed> $token */
    #[DataProvider('noCode')]
    public function test_a_note_without_a_token_does_not_sign_the_website_in_as_the_customer_with_that_phone(array $token): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_phone_verified' => 0]);
        $this->storeASession(self::OWNER_PHONE)->assertOk();

        $this->post('/customer/auth/verify', ['identity' => base64_encode(self::OWNER_PHONE), 'type' => base64_encode('phone_verification')] + $token);

        $this->assertGuest('customer');
    }

    /** @param array<string, mixed> $token */
    #[DataProvider('noCode')]
    public function test_a_note_without_a_token_does_not_sign_the_website_in_as_the_customer_with_that_email(array $token): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_email_verified' => 0]);
        $this->storeASession(self::OWNER_EMAIL)->assertOk();

        $this->post('/customer/auth/verify', ['identity' => base64_encode(self::OWNER_EMAIL), 'type' => base64_encode('email_verification')] + $token);

        $this->assertGuest('customer');
    }

    /** @param array<string, mixed> $token */
    #[DataProvider('noCode')]
    public function test_a_note_without_a_token_does_not_verify_the_email_of_a_customer_through_the_script_endpoint(array $token): void
    {
        $customer = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_email_verified' => 0, 'is_phone_verified' => 0]);
        $this->storeASession(self::OWNER_EMAIL)->assertOk();

        $this->postJson('/customer/auth/ajax-verify', ['id' => $customer] + $token);

        $this->assertSame(0, (int)DB::table('users')->where('id', $customer)->value('is_email_verified'));
        $this->assertSame(0, (int)DB::table('users')->where('id', $customer)->value('is_phone_verified'));
    }

    public function test_the_code_the_server_sent_still_signs_the_website_in_and_verifies_the_phone(): void
    {
        $customer = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL, ['is_phone_verified' => 0]);
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => '482915', 'created_at' => now(), 'updated_at' => now()]);

        $this->post('/customer/auth/verify', ['identity' => base64_encode(self::OWNER_PHONE), 'type' => base64_encode('phone_verification'), 'token' => '482915']);

        $this->assertAuthenticatedAs(\App\Models\User::find($customer), 'customer');
        $this->assertSame(1, (int)DB::table('users')->where('id', $customer)->value('is_phone_verified'));
    }

    public function test_a_number_is_compared_with_the_stored_token_as_the_text_it_was_typed_as(): void
    {
        DB::enableQueryLog();

        app(PhoneOrEmailVerificationRepositoryInterface::class)->getFirstWhere(params: ['phone_or_email' => self::OWNER_PHONE, 'token' => 0]);

        $bindings = collect(DB::getQueryLog())->pluck('bindings')->flatten()->all();
        $this->assertContains('0', $bindings, 'the token is bound as text, so MySQL does not turn it into the number 0');
        $this->assertNotContains(0, $bindings);
    }

    public function test_the_number_0_does_not_open_a_session_row_of_the_phone_login_under_any_engine(): void
    {
        $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => self::SESSION, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['verify-otp' => ['phone' => self::OWNER_PHONE], 'verify-phone' => ['phone' => self::OWNER_PHONE]] as $endpoint => $identity) {
            $this->postJson('/api/v1/auth/' . $endpoint, $identity + ['token' => 0, 'temporary_token' => 'x'])->assertStatus(403);
        }

        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_the_holder_of_a_token_is_found_by_the_text_of_a_number_and_by_nothing_that_is_not_one(): void
    {
        DB::table('phone_or_email_verifications')->insert([
            ['phone_or_email' => 'note', 'token' => null, 'created_at' => now(), 'updated_at' => now()],
            ['phone_or_email' => 'code', 'token' => '123456', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([null, '', [], [null], false, true, 0, '0'] as $nothing) {
            $this->assertFalse(PhoneOrEmailVerification::where('phone_or_email', 'note')->holdingToken($nothing)->exists(), json_encode($nothing));
        }
        $this->assertTrue(PhoneOrEmailVerification::where('phone_or_email', 'code')->holdingToken('123456')->exists());
        $this->assertTrue(PhoneOrEmailVerification::where('phone_or_email', 'code')->holdingToken(123456)->exists());
        $this->assertFalse(PhoneOrEmailVerification::where('phone_or_email', 'code')->holdingToken('123457')->exists());
        $this->assertFalse(PhoneOrEmailVerification::where('phone_or_email', 'code')->holdingToken(0)->exists());
    }

    public function test_a_request_value_is_text_when_it_is_one_value_and_nothing_when_it_is_not(): void
    {
        $this->assertSame('abc', RequestText::of('abc'));
        $this->assertSame('0', RequestText::of(0));
        $this->assertSame('123456', RequestText::of(123456));
        foreach ([null, [], ['x'], true, false, new \stdClass()] as $notOneValue) {
            $this->assertSame('', RequestText::of($notOneValue));
        }
    }

    public function test_a_list_where_the_app_sends_text_is_refused_and_not_answered_with_a_server_error(): void
    {
        $owner = $this->customer(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '482915');

        $answers = [
            $this->putJson('/api/v1/auth/reset-password', ['identity' => [self::OWNER_PHONE], 'otp' => '482915', 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]),
            $this->putJson('/api/v1/auth/reset-password', ['identity' => self::OWNER_PHONE, 'otp' => ['482915'], 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]),
            $this->postJson('/api/v1/auth/verify-token', ['email_or_phone' => [self::OWNER_PHONE], 'reset_token' => '482915']),
            $this->postJson('/api/v1/auth/verify-token', ['email_or_phone' => self::OWNER_PHONE, 'reset_token' => ['482915']]),
            $this->postJson('/api/v1/auth/forgot-password', ['email_or_phone' => [self::OWNER_PHONE], 'type' => 'phone']),
            $this->postJson('/api/v1/auth/firebase-auth-token-store', ['identity' => [self::OWNER_PHONE]]),
        ];

        foreach ($answers as $position => $answer) {
            $this->assertLessThan(500, $answer->status(), 'request ' . $position . ' answered ' . $answer->status());
        }
        $this->assertKeepsPassword($owner);
    }

    public function test_a_list_where_the_delivery_mans_app_sends_text_is_refused_and_not_answered_with_a_server_error(): void
    {
        $answers = [
            $this->postJson('/api/v2/delivery-man/auth/forgot-password', ['identity' => ['x']]),
            $this->postJson('/api/v2/delivery-man/auth/verify-otp', ['identity' => ['x'], 'otp' => ['1234']]),
            $this->postJson('/api/v2/delivery-man/auth/reset-password', ['phone' => ['x'], 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]),
        ];

        foreach ($answers as $position => $answer) {
            $this->assertLessThan(500, $answer->status(), 'request ' . $position . ' answered ' . $answer->status());
        }
    }

    public function test_the_deploy_cleanup_empties_the_codes_in_flight_and_the_proofs_of_customers_and_delivery_men_only(): void
    {
        DB::table('phone_or_email_verifications')->insert([
            ['phone_or_email' => self::OWNER_PHONE, 'token' => 'planted-token', 'created_at' => now(), 'updated_at' => now()],
            ['phone_or_email' => self::OWNER_EMAIL, 'token' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (['customer', 'delivery_man', 'seller', 'admin', 'vendor'] as $userType) {
            $this->issueCode('identity-of-' . $userType, '482915', $userType);
        }

        (require database_path('migrations/2026_11_26_100000_purge_customer_and_delivery_man_reset_proofs.php'))->up();

        $this->assertSame(0, DB::table('phone_or_email_verifications')->count());
        $this->assertEqualsCanonicalizing(['seller', 'admin', 'vendor'], DB::table('password_resets')->pluck('user_type')->all());
    }
}
