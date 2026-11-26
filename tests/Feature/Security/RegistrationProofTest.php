<?php

namespace Tests\Feature\Security;

use App\Services\Identity\RegistrationProof;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRestCustomerAuthSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * An account is created only for an e-mail or a phone somebody proved (A2-04).
 *
 * `registration-with-otp` wrote a customer row for any phone it was sent, stamped `is_phone_verified = 1` and handed back an access
 * token; `registration-with-social-media` did the same for any e-mail, stamped `email_verified_at` and called the account a social one.
 * Both are open endpoints, so an address or a number could be claimed by somebody who had never held it -- before its owner arrived.
 *
 * The proof was already in the flow and only missing from the server's memory of it: the call before each registration is the one that
 * proves the identity (`verify-otp` matched a code, `social-customer-login` asked the provider), and each already answered with a
 * throwaway 40-character token. That token is now the proof: one value, one way of proving it, ten minutes, one use.
 *
 * `gcc.identity.registration_proof.required` is off as it ships, and the first two tests here are that with it off both endpoints
 * behave exactly as they do today, token or no token. The issuing half is on in both states, because the response shape does not change
 * -- the same key, still 40 random characters -- so an app build can start sending the token back before the switch is ever thrown.
 */
class RegistrationProofTest extends TestCase
{
    use BuildsRestCustomerAuthSchema;
    use RestoresLanguageFiles;

    private const VERIFY_OTP_URI = '/api/v1/auth/verify-otp';
    private const SOCIAL_LOGIN_URI = '/api/v1/auth/social-customer-login';
    private const OTP_REGISTRATION_URI = '/api/v1/auth/registration-with-otp';
    private const SOCIAL_REGISTRATION_URI = '/api/v1/auth/registration-with-social-media';

    private const NEW_PHONE = '0500000011';
    private const ANOTHER_PHONE = '0500000022';
    private const NEW_EMAIL = 'newcomer@example.test';
    private const ANOTHER_EMAIL = 'someone.else@example.test';
    private const CODE = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildRestCustomerAuthSchema();
        $this->buildTheOneTimeCodeTable();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The one table the REST auth schema does not build and `verify-otp` reads. */
    private function buildTheOneTimeCodeTable(): void
    {
        Schema::dropIfExists('phone_or_email_verifications');
        Schema::create('phone_or_email_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone_or_email')->nullable();
            $table->text('token')->nullable();
            $table->unsignedTinyInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamps();
        });
    }

    private function requireTheProof(): void
    {
        config(['gcc.identity.registration_proof.required' => true]);
    }

    private function sendTheCodeTo(string $phone): void
    {
        DB::table('phone_or_email_verifications')->insert([
            'phone_or_email' => $phone,
            'token' => self::CODE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The token `verify-otp` answers with once the code has been matched for a phone no account holds. */
    private function proveThePhoneWithACode(string $phone = self::NEW_PHONE): string
    {
        $this->sendTheCodeTo(phone: $phone);
        $answer = $this->postJson(self::VERIFY_OTP_URI, ['phone' => $phone, 'token' => self::CODE]);
        $answer->assertOk()->assertJsonPath('status', false);

        return (string) $answer->json('temporary_token');
    }

    /** The token `social-customer-login` answers with once Google has vouched for an e-mail no account holds. */
    private function proveTheEmailWithGoogle(string $email = self::NEW_EMAIL): string
    {
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['sub' => 'google-uid-9', 'email' => $email, 'email_verified' => true, 'name' => 'New Comer'])),
        ]))]));

        $answer = $this->postJson(self::SOCIAL_LOGIN_URI, [
            'token' => 'google-access-token',
            'unique_id' => 'google-uid-9',
            'email' => $email,
            'medium' => 'google',
        ]);
        $answer->assertOk()->assertJsonPath('status', false)->assertJsonPath('new_user', 0);

        return (string) $answer->json('temp_token');
    }

    private function registerWithTheCode(array $payload = []): TestResponse
    {
        return $this->postJson(self::OTP_REGISTRATION_URI, $payload + ['name' => 'New Comer', 'phone' => self::NEW_PHONE]);
    }

    private function registerWithTheProvider(array $payload = []): TestResponse
    {
        return $this->postJson(self::SOCIAL_REGISTRATION_URI, $payload + [
            'name' => 'New Comer',
            'email' => self::NEW_EMAIL,
            'phone' => self::NEW_PHONE,
        ]);
    }

    private function customerCountFor(string $column, string $value): int
    {
        return DB::table('users')->where($column, $value)->count();
    }

    public function test_with_the_switch_off_an_unproved_phone_is_registered_exactly_as_today(): void
    {
        $this->registerWithTheCode()->assertOk()->assertJsonStructure(['token']);

        $this->assertSame(1, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
        $this->assertSame(1, (int) DB::table('users')->where('phone', self::NEW_PHONE)->value('is_phone_verified'));
    }

    public function test_with_the_switch_off_an_unproved_email_is_registered_exactly_as_today(): void
    {
        $this->registerWithTheProvider()->assertOk()->assertJsonStructure(['token']);

        $this->assertSame(1, $this->customerCountFor(column: 'email', value: self::NEW_EMAIL));
    }

    public function test_a_registration_with_no_proof_at_all_is_refused_and_writes_nothing(): void
    {
        $this->requireTheProof();

        $this->registerWithTheCode()
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'temporary_token');

        $this->assertSame(0, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
    }

    public function test_a_made_up_token_proves_nothing(): void
    {
        $this->requireTheProof();

        $this->registerWithTheCode(['temporary_token' => str_repeat('a', 40)])->assertStatus(403);

        $this->assertSame(0, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
    }

    public function test_the_token_verify_otp_answered_with_registers_that_phone(): void
    {
        $this->requireTheProof();
        $token = $this->proveThePhoneWithACode();

        $this->registerWithTheCode(['temporary_token' => $token])->assertOk()->assertJsonStructure(['token']);

        $this->assertSame(1, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
    }

    public function test_a_proof_of_one_phone_does_not_register_another(): void
    {
        $this->requireTheProof();
        $token = $this->proveThePhoneWithACode(phone: self::ANOTHER_PHONE);

        $this->registerWithTheCode(['temporary_token' => $token])->assertStatus(403);

        $this->assertSame(0, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
    }

    public function test_a_proof_is_spent_by_the_registration_it_serves(): void
    {
        $this->requireTheProof();
        $token = $this->proveThePhoneWithACode();

        $this->registerWithTheCode(['temporary_token' => $token])->assertOk();
        $this->postJson(self::OTP_REGISTRATION_URI, ['name' => 'Second Comer', 'phone' => self::NEW_PHONE, 'temporary_token' => $token])
            ->assertStatus(403);

        $this->assertSame(1, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
    }

    public function test_a_proof_of_a_phone_is_not_a_proof_of_an_email(): void
    {
        $this->requireTheProof();
        $token = $this->proveThePhoneWithACode();

        $this->registerWithTheProvider(['temp_token' => $token])->assertStatus(403);

        $this->assertSame(0, $this->customerCountFor(column: 'email', value: self::NEW_EMAIL));
    }

    public function test_the_token_the_provider_login_answered_with_registers_that_email(): void
    {
        $this->requireTheProof();
        $token = $this->proveTheEmailWithGoogle();

        $this->registerWithTheProvider(['temp_token' => $token])->assertOk()->assertJsonStructure(['token']);

        $this->assertSame(1, $this->customerCountFor(column: 'email', value: self::NEW_EMAIL));
    }

    public function test_a_provider_proof_of_one_address_does_not_register_another(): void
    {
        $this->requireTheProof();
        $token = $this->proveTheEmailWithGoogle(email: self::ANOTHER_EMAIL);

        $this->registerWithTheProvider(['temp_token' => $token])->assertStatus(403);

        $this->assertSame(0, $this->customerCountFor(column: 'email', value: self::NEW_EMAIL));
    }

    public function test_an_address_is_proved_whatever_its_case(): void
    {
        $this->requireTheProof();
        $token = $this->proveTheEmailWithGoogle(email: 'Newcomer@Example.test');

        $this->registerWithTheProvider(['temp_token' => $token, 'email' => 'newcomer@example.TEST'])->assertOk();

        $this->assertSame(1, $this->customerCountFor(column: 'email', value: 'newcomer@example.TEST'));
    }

    public function test_a_proof_older_than_its_lifetime_is_refused(): void
    {
        $this->requireTheProof();
        $token = $this->proveThePhoneWithACode();

        $this->travel(11)->minutes();
        $this->registerWithTheCode(['temporary_token' => $token])->assertStatus(403);

        $this->assertSame(0, $this->customerCountFor(column: 'phone', value: self::NEW_PHONE));
    }

    /** The issuing half changes no answer the app reads: the same keys, still forty random characters. */
    public function test_both_proving_endpoints_answer_with_a_forty_character_token_under_the_key_they_always_used(): void
    {
        $this->assertSame(40, strlen($this->proveThePhoneWithACode()));
        $this->assertSame(40, strlen($this->proveTheEmailWithGoogle()));
    }

    public function test_the_proof_names_the_way_it_was_proved_so_the_two_cannot_be_crossed(): void
    {
        $proof = app(RegistrationProof::class);
        $token = $proof->issue(proved: self::NEW_PHONE, how: RegistrationProof::ONE_TIME_CODE);

        $this->assertFalse($proof->redeem(token: $token, proved: self::NEW_PHONE, how: RegistrationProof::PROVIDER));
    }

    public function test_a_wrong_guess_spends_the_token_it_guessed_with(): void
    {
        $proof = app(RegistrationProof::class);
        $token = $proof->issue(proved: self::NEW_EMAIL, how: RegistrationProof::PROVIDER);

        $this->assertFalse($proof->redeem(token: $token, proved: self::ANOTHER_EMAIL, how: RegistrationProof::PROVIDER));
        $this->assertFalse($proof->redeem(token: $token, proved: self::NEW_EMAIL, how: RegistrationProof::PROVIDER));
    }
}
