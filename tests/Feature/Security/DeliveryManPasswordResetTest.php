<?php

namespace Tests\Feature\Security;

use App\Events\DeliverymanPasswordResetEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A delivery man's password can only be reset by the person a code was sent to, once they have typed that code.
 *
 * The delivery man's app asks for a code (forgot-password), has it checked (verify-otp) and then sends the phone and the new password
 * (reset-password), which never asked for the code or for anything that said it had been checked: the phone of any delivery man and a new
 * password were enough to take the account.
 */
class DeliveryManPasswordResetTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const OLD_PASSWORD = 'Old1!password';

    private const NEW_PASSWORD = 'Fresh1!password';

    private const OWNER_PHONE = '555000111';

    private const OWNER_EMAIL = 'courier@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('country_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->string('user_type')->default('customer');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
        Event::fake([DeliverymanPasswordResetEvent::class]);

        DB::table('business_settings')->insert([
            ['type' => 'auth_attempts_per_minute', 'value' => '600'],
            ['type' => 'mail_config', 'value' => json_encode(['status' => 1])],
        ]);
        $this->useResetMethod('phone');
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function useResetMethod(string $method): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'deliveryman_forgot_password_method'], ['value' => $method]);
        Cache::flush();
    }

    private function deliveryMan(string $phone, string $email, array $overrides = []): int
    {
        return DB::table('delivery_men')->insertGetId(array_merge([
            'f_name' => 'Omar', 'l_name' => 'Courier', 'phone' => $phone, 'email' => $email, 'password' => bcrypt(self::OLD_PASSWORD),
            'auth_token' => Str::random(50), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function passwordIs(int $deliveryManId, string $plain): bool
    {
        return Hash::check($plain, (string) DB::table('delivery_men')->where('id', $deliveryManId)->value('password'));
    }

    private function assertKeepsPassword(int ...$deliveryManIds): void
    {
        foreach ($deliveryManIds as $deliveryManId) {
            $this->assertTrue($this->passwordIs($deliveryManId, self::OLD_PASSWORD), "delivery man {$deliveryManId} must keep the password they had");
        }
    }

    private function assertTookPassword(int $deliveryManId): void
    {
        $this->assertTrue($this->passwordIs($deliveryManId, self::NEW_PASSWORD), "delivery man {$deliveryManId} must have the new password");
        $this->assertFalse($this->passwordIs($deliveryManId, self::OLD_PASSWORD), "delivery man {$deliveryManId} must no longer have the old password");
    }

    private function forgotPassword(string|int $identity): TestResponse
    {
        return $this->postJson('/api/v2/delivery-man/auth/forgot-password', ['identity' => $identity]);
    }

    private function verifyOtp(string|int $identity, string|int $otp): TestResponse
    {
        return $this->postJson('/api/v2/delivery-man/auth/verify-otp', ['identity' => $identity, 'otp' => $otp]);
    }

    /** What the delivery man's app sends to choose the new password: the phone verify-otp answered with, and no code. */
    private function resetPassword(string|int $phone): TestResponse
    {
        return $this->postJson('/api/v2/delivery-man/auth/reset-password', ['phone' => $phone, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD]);
    }

    private function login(string $phone, string $password): TestResponse
    {
        return $this->postJson('/api/v2/delivery-man/auth/login', ['phone' => $phone, 'password' => $password]);
    }

    private function codeSentTo(string $identity): string
    {
        $codes = DB::table('password_resets')->where('user_type', 'delivery_man')->where('identity', $identity)->pluck('token');
        $this->assertCount(1, $codes, "one code must have been issued to {$identity}");

        return (string) $codes->first();
    }

    private function issueCode(string $identity, string $code, string $userType = 'delivery_man', bool $verified = false): void
    {
        DB::table('password_resets')->insert([
            'identity' => $identity, 'token' => $code, 'user_type' => $userType, 'expires_at' => $verified ? now()->addMinutes(10) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // The code has to have been typed
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_phone_alone_resets_nothing(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);

        $this->assertKeepsPassword($courier);
    }

    public function test_a_code_that_was_sent_but_never_verified_resets_nothing(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);

        $this->forgotPassword(self::OWNER_PHONE)->assertOk();

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);
        $this->assertKeepsPassword($courier);
    }

    public function test_a_code_that_was_verified_wrongly_resets_nothing(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $code = $this->codeSentTo(self::OWNER_PHONE);

        $this->verifyOtp(self::OWNER_PHONE, $code === '1111' ? '2222' : '1111')->assertStatus(403);

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);
        $this->assertKeepsPassword($courier);
    }

    public function test_a_verified_code_resets_the_password_of_that_delivery_man_only(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $other = $this->deliveryMan('555000222', 'other@example.test');
        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $this->verifyOtp(self::OWNER_PHONE, $this->codeSentTo(self::OWNER_PHONE))->assertOk();

        $this->resetPassword('555000222')->assertStatus(403);
        $this->resetPassword('%')->assertStatus(403);
        $this->resetPassword(0)->assertStatus(403);
        $this->assertKeepsPassword($courier, $other);

        $this->resetPassword(self::OWNER_PHONE)->assertOk();
        $this->assertTookPassword($courier);
        $this->assertKeepsPassword($other);
    }

    public function test_a_verified_code_is_spent_by_the_reset(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $this->verifyOtp(self::OWNER_PHONE, $this->codeSentTo(self::OWNER_PHONE))->assertOk();
        $this->resetPassword(self::OWNER_PHONE)->assertOk();

        $this->postJson('/api/v2/delivery-man/auth/reset-password', [
            'phone' => self::OWNER_PHONE, 'password' => 'Another1!password', 'confirm_password' => 'Another1!password',
        ])->assertStatus(403);

        $this->assertTookPassword($courier);
        $this->assertSame(0, DB::table('password_resets')->count());
    }

    public function test_a_verified_code_is_good_for_the_time_it_takes_to_type_a_password_and_not_for_ever(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $this->verifyOtp(self::OWNER_PHONE, $this->codeSentTo(self::OWNER_PHONE))->assertOk();

        $this->travel(31)->minutes();

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);
        $this->assertKeepsPassword($courier);
    }

    public function test_a_verified_code_is_still_good_a_few_minutes_later(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $this->verifyOtp(self::OWNER_PHONE, $this->codeSentTo(self::OWNER_PHONE))->assertOk();

        $this->travel(10)->minutes();

        $this->resetPassword(self::OWNER_PHONE)->assertOk();
        $this->assertTookPassword($courier);
    }

    public function test_a_code_issued_to_another_kind_of_user_with_the_same_phone_is_no_proof(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '1234', userType: 'seller', verified: true);
        $this->issueCode(self::OWNER_PHONE, '1234', userType: 'customer', verified: true);

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);

        $this->assertKeepsPassword($courier);
    }

    public function test_a_reset_is_refused_when_two_delivery_men_share_the_phone(): void
    {
        $first = $this->deliveryMan(self::OWNER_PHONE, 'first@example.test');
        $second = $this->deliveryMan(self::OWNER_PHONE, 'second@example.test');
        $this->issueCode(self::OWNER_PHONE, '1234', verified: true);

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);

        $this->assertKeepsPassword($first, $second);
    }

    public function test_a_numeric_zero_is_not_a_wildcard(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $textPhone = $this->deliveryMan('call-the-office', 'office@example.test');
        $this->issueCode(self::OWNER_EMAIL, '1234', verified: true);
        $this->issueCode('call-the-office', '5678', verified: true);

        $this->forgotPassword(0)->assertStatus(403);
        $this->verifyOtp(0, 0)->assertStatus(403);
        $this->resetPassword(0)->assertStatus(403);

        $this->assertKeepsPassword($courier, $textPhone);
        $this->assertSame(2, DB::table('password_resets')->count());
    }

    public function test_verify_otp_matches_the_identity_and_the_code_exactly(): void
    {
        $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->issueCode(self::OWNER_PHONE, '1234');

        $this->verifyOtp('%', '1234')->assertStatus(403);
        $this->verifyOtp('55500', '1234')->assertStatus(403);
        $this->verifyOtp(self::OWNER_PHONE, '1235')->assertStatus(403);
        $this->verifyOtp(self::OWNER_PHONE, '1234')->assertOk()->assertJson(['phone' => self::OWNER_PHONE, 'email' => self::OWNER_EMAIL]);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // The way the app resets still works, end to end
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_phone_forgot_password_then_verify_otp_then_reset_changes_the_password(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $other = $this->deliveryMan('555000222', 'other@example.test');

        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $code = $this->codeSentTo(self::OWNER_PHONE);

        $this->verifyOtp(self::OWNER_PHONE, $code)->assertOk()->assertJson(['phone' => self::OWNER_PHONE]);
        $this->resetPassword(self::OWNER_PHONE)->assertOk()->assertJson(['message' => translate('Password_changed_successfully')]);

        $this->assertTookPassword($courier);
        $this->assertKeepsPassword($other);
        $this->login(self::OWNER_PHONE, self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->login(self::OWNER_PHONE, self::OLD_PASSWORD)->assertStatus(401);
    }

    public function test_email_forgot_password_then_verify_otp_then_reset_changes_the_password(): void
    {
        $this->useResetMethod('email');
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $other = $this->deliveryMan('555000222', 'other@example.test');

        $this->forgotPassword(self::OWNER_EMAIL)->assertOk();
        Event::assertDispatched(DeliverymanPasswordResetEvent::class, fn (DeliverymanPasswordResetEvent $event): bool => $event->email === self::OWNER_EMAIL);
        $code = $this->codeSentTo(self::OWNER_EMAIL);

        $answer = $this->verifyOtp(self::OWNER_EMAIL, $code)->assertOk()->assertJson(['phone' => self::OWNER_PHONE, 'email' => self::OWNER_EMAIL]);
        $this->resetPassword($answer->json('phone'))->assertOk();

        $this->assertTookPassword($courier);
        $this->assertKeepsPassword($other);
        $this->login(self::OWNER_PHONE, self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_asking_for_a_new_code_starts_the_check_again(): void
    {
        $courier = $this->deliveryMan(self::OWNER_PHONE, self::OWNER_EMAIL);
        $this->forgotPassword(self::OWNER_PHONE)->assertOk();
        $this->verifyOtp(self::OWNER_PHONE, $this->codeSentTo(self::OWNER_PHONE))->assertOk();

        $this->forgotPassword(self::OWNER_PHONE)->assertOk();

        $this->resetPassword(self::OWNER_PHONE)->assertStatus(403);
        $this->assertKeepsPassword($courier);
    }
}
