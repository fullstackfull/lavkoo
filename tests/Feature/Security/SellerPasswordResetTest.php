<?php

namespace Tests\Feature\Security;

use App\Events\PasswordResetEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\ZenderSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A seller's password can only be reset by the person the proof was issued to, and the reset changes that one seller and nobody else.
 *
 * The seller app's reset endpoints (v2 and v3) matched the identity with LIKE '%identity%' everywhere: a proof was found for any identity
 * that merely contained the typed text, and the password UPDATE then hit every seller whose phone contained it. `%` is a legal identity,
 * so anyone holding any valid proof (their own, from forgot-password) could overwrite every seller's password and API token. The
 * Firebase flow was worse: firebase-auth-token-store accepts any identity and token from anyone, reset-password accepted what it stored
 * as proof, and firebase-auth-verify, the only step that asks Google whether the code was right, left nothing behind that the reset
 * could check. Without any account, a victim's public shop phone and a made-up token were enough.
 */
class SellerPasswordResetTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const OLD_PASSWORD = 'Old1!password';

    private const NEW_PASSWORD = 'Fresh1!password';

    private const OWNER_PHONE = '+966500000001';

    /** The end-to-end test repeats this to the length a real Firebase session can reach, which would not fit a varchar(255) column. */
    private const SESSION = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildSellerAuthTables();

        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();
        Event::fake([PasswordResetEvent::class]);

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

    /** @return array<string, array{string}> */
    public static function apiVersions(): array
    {
        return ['v2' => ['v2'], 'v3' => ['v3']];
    }

    private function buildSellerAuthTables(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->string('auth_token')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('withdrawn', 24, 2)->default(0);
            $table->decimal('commission_given', 24, 2)->default(0);
            $table->decimal('total_earning', 24, 2)->default(0);
            $table->decimal('pending_withdraw', 24, 2)->default(0);
            $table->decimal('delivery_charge_earned', 24, 2)->default(0);
            $table->decimal('collected_cash', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->string('user_type')->default('customer');
            $table->timestamps();
        });
        Schema::create('phone_or_email_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone_or_email')->nullable();
            $table->text('token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->tinyInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamps();
        });
        Schema::create('zender_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('base_url')->nullable();
            $table->text('secret')->nullable();
            $table->string('type')->default('sms');
            $table->text('message')->nullable();
            $table->integer('expire')->default(300);
            $table->integer('priority')->default(2);
            foreach (['account', 'mode', 'device', 'gateway'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('sim')->nullable();
            $table->timestamps();
        });
    }

    private function useResetMethod(string $method): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'vendor_forgot_password_method'], ['value' => $method]);
        Cache::flush();
    }

    private function seller(string $phone, string $email, array $overrides = []): int
    {
        return DB::table('sellers')->insertGetId(array_merge([
            'f_name' => 'Sara', 'l_name' => 'Hassan', 'phone' => $phone, 'email' => $email,
            'password' => bcrypt(self::OLD_PASSWORD), 'status' => 'approved', 'auth_token' => Str::random(50),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function passwordIs(int $sellerId, string $plain): bool
    {
        return Hash::check($plain, (string) DB::table('sellers')->where('id', $sellerId)->value('password'));
    }

    private function assertKeepsPassword(int ...$sellerIds): void
    {
        foreach ($sellerIds as $sellerId) {
            $this->assertTrue($this->passwordIs($sellerId, self::OLD_PASSWORD), "seller {$sellerId} must keep the password they had");
        }
    }

    /** @return array<int, ?string> */
    private function tokensKeptFor(string $phone): array
    {
        return DB::table('phone_or_email_verifications')->where('phone_or_email', $phone)->pluck('token')->all();
    }

    private function assertTookPassword(int $sellerId): void
    {
        $this->assertTrue($this->passwordIs($sellerId, self::NEW_PASSWORD), "seller {$sellerId} must have the new password");
        $this->assertFalse($this->passwordIs($sellerId, self::OLD_PASSWORD), "seller {$sellerId} must no longer have the old password");
    }

    private function issueCode(string $identity, string $code): void
    {
        DB::table('password_resets')->insert(['identity' => $identity, 'token' => $code, 'user_type' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function endpoint(string $version, string $path): string
    {
        return "/api/{$version}/seller/auth/{$path}";
    }

    private function forgotPassword(string $version, string $identity): TestResponse
    {
        return $this->postJson($this->endpoint($version, 'forgot-password'), ['identity' => $identity]);
    }

    private function verifyOtp(string $version, string|int $identity, string|int $code): TestResponse
    {
        return $this->postJson($this->endpoint($version, 'verify-otp'), ['identity' => $identity, 'otp' => $code]);
    }

    private function resetPassword(string $version, string|int $identity, string|int $code): TestResponse
    {
        return $this->putJson($this->endpoint($version, 'reset-password'), [
            'identity' => $identity, 'otp' => $code, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD,
        ]);
    }

    private function login(string $version, string $email, string $password): TestResponse
    {
        return $this->postJson($this->endpoint($version, 'login'), ['email' => $email, 'password' => $password]);
    }

    private function textMessagesThroughZender(): void
    {
        ZenderSetting::create([
            'enabled' => true, 'base_url' => 'https://zender.test', 'secret' => 'example-gateway-secret', 'type' => 'sms',
            'message' => 'Your code is {{otp}}', 'mode' => 'credits', 'gateway' => '1',
        ]);
        Http::fake(['zender.test/*' => Http::response(['status' => 200], 200)]);
    }

    private function codeTextedTo(string $phone): string
    {
        $messages = Http::recorded(fn (HttpRequest $request): bool => $request->url() === 'https://zender.test/api/send/sms' && $request['phone'] === $phone);
        $this->assertCount(1, $messages, "one text message must have gone to {$phone}");
        $this->assertSame(1, preg_match('/\d{6}/', $messages->first()[0]['message'], $code));

        return $code[0];
    }

    private function resetTokenMailedTo(string $email): string
    {
        $token = null;
        Event::assertDispatched(PasswordResetEvent::class, function (PasswordResetEvent $event) use ($email, &$token): bool {
            if ($event->email !== $email) {
                return false;
            }
            parse_str((string) parse_url($event->data['passwordResetURL'], PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }

    /**
     * Google's side of Firebase phone sign-in: a session is issued to one phone number and accepts one code, and the answer names the phone
     * the session belongs to.
     *
     * @param array<string, array{phone: ?string, code: string}> $sessions
     */
    private function firebaseKnows(array $sessions): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'firebase_otp_verification'], ['value' => json_encode(['status' => 1, 'web_api_key' => 'example-web-api-key'])]);
        Cache::flush();
        Http::fake(['identitytoolkit.googleapis.com/*' => function (HttpRequest $request) use ($sessions) {
            $session = $sessions[$request['sessionInfo']] ?? null;
            if ($session === null || $session['code'] !== $request['code']) {
                return Http::response(['error' => ['message' => 'INVALID_CODE']], 400);
            }

            return Http::response(array_filter(['idToken' => 'example-id-token', 'isNewUser' => false, 'phoneNumber' => $session['phone']], static fn ($value): bool => $value !== null), 200);
        }]);
    }

    private function storeFirebaseSession(string $identity, string $session): TestResponse
    {
        return $this->postJson($this->endpoint('v3', 'firebase-auth-token-store'), ['identity' => $identity, 'token' => $session]);
    }

    private function verifyFirebaseCode(string $phone, string $session, string $code): TestResponse
    {
        return $this->postJson($this->endpoint('v3', 'firebase-auth-verify'), ['sessionInfo' => $session, 'phoneNumber' => $phone, 'code' => $code]);
    }

    /** Exactly what the seller app sends to reset: a POST that carries `_method: put`, with the Firebase session in `otp`. */
    private function resetPasswordTheWayTheAppDoes(string $phone, string $session): TestResponse
    {
        return $this->postJson($this->endpoint('v3', 'reset-password'), [
            '_method' => 'put', 'identity' => $phone, 'otp' => $session, 'password' => self::NEW_PASSWORD, 'confirm_password' => self::NEW_PASSWORD,
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // The identity is matched exactly: no pattern, no fragment, no second seller
    // ---------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('apiVersions')]
    public function test_a_wildcard_identity_redeems_nothing(string $version): void
    {
        $attacker = $this->seller('+966599999999', 'attacker@example.test', ['status' => 'pending']);
        $victim = $this->seller(self::OWNER_PHONE, 'victim@example.test');
        $bystander = $this->seller('+966500000002', 'bystander@example.test');
        $this->issueCode('+966599999999', '246810');
        $sessions = DB::table('sellers')->pluck('auth_token', 'id')->all();

        foreach (['%', '_', '%%%%%%', '+9665%', '+96659999999_'] as $pattern) {
            $response = $this->resetPassword($version, $pattern, '246810');

            $this->assertKeepsPassword($attacker, $victim, $bystander);
            $this->assertSame($sessions, DB::table('sellers')->pluck('auth_token', 'id')->all(), 'nobody was signed out');
            $response->assertStatus(400);
        }
    }

    #[DataProvider('apiVersions')]
    public function test_a_fragment_of_the_identity_redeems_nothing_and_the_whole_identity_does(string $version): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->issueCode(self::OWNER_PHONE, '135790');

        foreach (['500000001', '+96650000', '0000001', '966500000001'] as $fragment) {
            $this->resetPassword($version, $fragment, '135790')->assertStatus(400);
        }
        $this->assertKeepsPassword($owner);

        $this->resetPassword($version, self::OWNER_PHONE, '135790')->assertOk();
        $this->assertTookPassword($owner);
    }

    #[DataProvider('apiVersions')]
    public function test_a_reset_changes_only_the_seller_the_code_was_sent_to(string $version): void
    {
        $longerNumber = $this->seller(self::OWNER_PHONE . '1', 'longer@example.test');
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->issueCode(self::OWNER_PHONE, '112233');

        $this->resetPassword($version, self::OWNER_PHONE, '112233')->assertOk();

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($longerNumber);
    }

    #[DataProvider('apiVersions')]
    public function test_a_code_issued_to_one_seller_resets_no_other_seller(string $version): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $other = $this->seller('+966500000002', 'other@example.test');
        $this->issueCode(self::OWNER_PHONE, '778899');

        $this->resetPassword($version, '+966500000002', '778899')->assertStatus(400);

        $this->assertKeepsPassword($owner, $other);
    }

    #[DataProvider('apiVersions')]
    public function test_a_reset_is_refused_when_two_sellers_share_the_identity(string $version): void
    {
        $first = $this->seller(self::OWNER_PHONE, 'first@example.test');
        $second = $this->seller(self::OWNER_PHONE, 'second@example.test');
        $this->issueCode(self::OWNER_PHONE, '556677');

        $this->resetPassword($version, self::OWNER_PHONE, '556677')->assertStatus(400);

        $this->assertKeepsPassword($first, $second);
    }

    #[DataProvider('apiVersions')]
    public function test_a_numeric_zero_is_not_a_wildcard(string $version): void
    {
        $this->useResetMethod('email');
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        DB::table('password_resets')->insert(['identity' => 'owner@example.test', 'token' => 'EmailedTokenValue', 'user_type' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $this->resetPassword($version, 0, 0)->assertStatus(400);
        $this->verifyOtp($version, 0, 0)->assertNotFound();

        $this->assertKeepsPassword($owner);
        $this->assertSame(1, DB::table('password_resets')->count());
    }

    #[DataProvider('apiVersions')]
    public function test_verify_otp_matches_the_identity_exactly(string $version): void
    {
        $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->issueCode(self::OWNER_PHONE, '314159');

        $this->verifyOtp($version, '%', '314159')->assertNotFound();
        $this->verifyOtp($version, '500000001', '314159')->assertNotFound();
        $this->verifyOtp($version, self::OWNER_PHONE, '000000')->assertNotFound();
        $this->verifyOtp($version, self::OWNER_PHONE, '314159')->assertOk()->assertJson(['message' => 'otp verified.']);
    }

    #[DataProvider('apiVersions')]
    public function test_forgot_password_with_a_wildcard_neither_finds_a_seller_nor_discards_pending_codes(string $version): void
    {
        $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->issueCode(self::OWNER_PHONE, '445566');

        $this->forgotPassword($version, '%%%%%%')->assertNotFound();

        $this->assertSame(['445566'], DB::table('password_resets')->pluck('token')->all(), 'the code issued to the owner is still the only one');
    }

    #[DataProvider('apiVersions')]
    public function test_forgot_password_sends_the_code_to_the_seller_with_exactly_that_number(string $version): void
    {
        $this->seller(self::OWNER_PHONE . '1', 'longer@example.test');
        $this->seller(self::OWNER_PHONE, 'owner@example.test');

        $this->forgotPassword($version, self::OWNER_PHONE);

        $this->assertSame([self::OWNER_PHONE], DB::table('password_resets')->pluck('identity')->all());
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Both ways of being told the code still work, end to end
    // ---------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('apiVersions')]
    public function test_email_mode_forgot_password_then_reset_changes_the_password(string $version): void
    {
        $this->useResetMethod('email');
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $bystander = $this->seller('+966500000002', 'bystander@example.test');

        $this->forgotPassword($version, 'owner@example.test')->assertOk();
        $token = $this->resetTokenMailedTo('owner@example.test');

        $this->verifyOtp($version, 'owner@example.test', $token)->assertOk();
        $this->resetPassword($version, 'owner@example.test', $token)->assertOk()->assertJson(['message' => 'Password changed successfully.']);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->login($version, 'owner@example.test', self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->login($version, 'owner@example.test', self::OLD_PASSWORD)->assertStatus(401);
        $this->resetPassword($version, 'owner@example.test', $token)->assertStatus(400);
    }

    public function test_v3_phone_mode_forgot_password_then_reset_changes_the_password(): void
    {
        $this->textMessagesThroughZender();
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $bystander = $this->seller('+966500000002', 'bystander@example.test');
        $ownerSession = DB::table('sellers')->where('id', $owner)->value('auth_token');
        $bystanderSession = DB::table('sellers')->where('id', $bystander)->value('auth_token');

        $this->forgotPassword('v3', self::OWNER_PHONE)->assertOk()->assertJson(['message' => 'otp sent successfully.']);
        $code = $this->codeTextedTo(self::OWNER_PHONE);

        $this->verifyOtp('v3', self::OWNER_PHONE, $code)->assertOk();
        $this->resetPassword('v3', self::OWNER_PHONE, $code)->assertOk()->assertJson(['message' => 'Password changed successfully.']);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->assertNotSame($ownerSession, DB::table('sellers')->where('id', $owner)->value('auth_token'), 'the reset ends the sessions of the seller who reset');
        $this->assertSame($bystanderSession, DB::table('sellers')->where('id', $bystander)->value('auth_token'), 'and nobody else\'s');
        $this->login('v3', 'owner@example.test', self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->login('v3', 'owner@example.test', self::OLD_PASSWORD)->assertStatus(401);
        $this->resetPassword('v3', self::OWNER_PHONE, $code)->assertStatus(400);
    }

    public function test_v2_phone_mode_forgot_password_then_reset_changes_the_password(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $bystander = $this->seller('+966500000002', 'bystander@example.test');

        $this->forgotPassword('v2', self::OWNER_PHONE)->assertOk()->assertJson(['message' => 'otp sent successfully.']);
        $code = (string) DB::table('password_resets')->where('identity', self::OWNER_PHONE)->value('token');

        $this->verifyOtp('v2', self::OWNER_PHONE, $code)->assertOk();
        $this->resetPassword('v2', self::OWNER_PHONE, $code)->assertOk()->assertJson(['message' => 'Password changed successfully.']);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->login('v2', 'owner@example.test', self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->login('v2', 'owner@example.test', self::OLD_PASSWORD)->assertStatus(401);
        $this->resetPassword('v2', self::OWNER_PHONE, $code)->assertStatus(400);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // Nobody can make up a proof: firebase-auth-token-store takes anything from anyone, so what it stores proves nothing
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_token_that_anyone_stored_resets_nothing(): void
    {
        $victim = $this->seller(self::OWNER_PHONE, 'victim@example.test');

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->resetPassword('v3', self::OWNER_PHONE, 'attacker-chosen')->assertStatus(400);
        $this->assertKeepsPassword($victim);
    }

    public function test_a_stored_token_cannot_be_redeemed_with_a_wildcard_for_every_seller(): void
    {
        $victim = $this->seller(self::OWNER_PHONE, 'victim@example.test');
        $bystander = $this->seller('+966500000002', 'bystander@example.test');

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();
        $this->storeFirebaseSession('x', 'attacker-chosen-too');

        $this->resetPassword('v3', '%', 'attacker-chosen')->assertStatus(400);
        $this->resetPassword('v3', '%', 'attacker-chosen-too')->assertStatus(400);
        $this->assertKeepsPassword($victim, $bystander);
    }

    public function test_the_token_store_keeps_nothing_for_a_number_that_is_not_a_seller(): void
    {
        $this->seller(self::OWNER_PHONE, 'owner@example.test');

        $this->storeFirebaseSession('+966511111111', 'attacker-chosen')->assertNotFound();

        $this->assertSame(0, DB::table('phone_or_email_verifications')->count());
    }

    public function test_the_token_store_keeps_a_note_that_a_session_was_asked_for_and_not_what_the_caller_sent(): void
    {
        $this->seller(self::OWNER_PHONE, 'owner@example.test');

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->assertSame([null], $this->tokensKeptFor(self::OWNER_PHONE));
    }

    public function test_the_token_store_leaves_a_code_the_server_sent_to_a_customer_with_that_phone_as_it_is(): void
    {
        $this->seller(self::OWNER_PHONE, 'owner@example.test');
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => '654321', 'created_at' => now(), 'updated_at' => now()]);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->assertSame(['654321'], $this->tokensKeptFor(self::OWNER_PHONE));
    }

    public function test_a_reset_removes_the_note_of_the_session_and_keeps_a_code_the_server_sent_to_a_customer(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->assertTookPassword($owner);
        $this->assertSame([], $this->tokensKeptFor(self::OWNER_PHONE));

        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => '654321', 'created_at' => now(), 'updated_at' => now()]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertOk();

        $this->assertSame(['654321'], $this->tokensKeptFor(self::OWNER_PHONE));
    }

    public function test_the_deploy_cleanup_empties_the_codes_in_flight_and_the_proofs_of_sellers_only(): void
    {
        DB::table('phone_or_email_verifications')->insert(['phone_or_email' => self::OWNER_PHONE, 'token' => 'planted-token', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['seller', 'customer', 'delivery_man', 'admin'] as $userType) {
            DB::table('password_resets')->insert(['identity' => 'identity-of-' . $userType, 'token' => '482915', 'user_type' => $userType, 'created_at' => now(), 'updated_at' => now()]);
        }

        (require database_path('migrations/2026_11_26_100100_purge_seller_reset_proofs.php'))->up();

        $this->assertSame(0, DB::table('phone_or_email_verifications')->count());
        $this->assertEqualsCanonicalizing(['customer', 'delivery_man', 'admin'], DB::table('password_resets')->pluck('user_type')->all());
    }

    public function test_the_firebase_flow_the_app_runs_still_resets_the_password(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $bystander = $this->seller('+966500000002', 'bystander@example.test');
        $session = str_repeat(self::SESSION, 12);
        $this->firebaseKnows([$session => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);

        $this->postJson($this->endpoint('v3', 'check-vendor-exist-info'), ['phone' => self::OWNER_PHONE])->assertOk();
        $this->storeFirebaseSession(self::OWNER_PHONE, $session)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, $session, '482915')->assertOk()->assertJson(['message' => 'otp verified.']);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, $session)->assertOk()->assertJson(['message' => 'Password changed successfully.']);

        $this->assertTookPassword($owner);
        $this->assertKeepsPassword($bystander);
        $this->login('v3', 'owner@example.test', self::NEW_PASSWORD)->assertOk()->assertJsonStructure(['token']);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, $session)->assertStatus(400);
    }

    public function test_a_session_is_no_proof_until_google_has_accepted_the_code(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertStatus(400);

        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '000000')->assertStatus(403);
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertStatus(400);
        $this->assertKeepsPassword($owner);

        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->assertTookPassword($owner);
    }

    public function test_a_google_answer_that_does_not_name_the_phone_proves_nothing(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => null, 'code' => '482915']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertNotFound();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertStatus(400);
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
            'a foreign number whose digits read as the owner\'s' => ['+500000001'],
        ];
    }

    #[DataProvider('phonesThatAreNotTheOwners')]
    public function test_a_session_google_issued_to_another_phone_proves_nothing_about_this_one(string $attackersPhone): void
    {
        $victim = $this->seller(self::OWNER_PHONE, 'victim@example.test');
        $this->firebaseKnows(['attacker-own-session' => ['phone' => $attackersPhone, 'code' => '246802']]);

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-own-session')->assertOk();
        $this->verifyFirebaseCode(self::OWNER_PHONE, 'attacker-own-session', '246802')->assertNotFound();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, 'attacker-own-session')->assertStatus(400);
        $this->assertKeepsPassword($victim);
    }

    /** @return array<string, array{string, string}> what the seller registered with, and what Google calls the same Saudi number */
    public static function sameSaudiNumberWrittenDifferently(): array
    {
        return [
            'registered with the trunk zero' => ['+9660500000001', '+966500000001'],
            'registered in national format' => ['0500000001', '+966500000001'],
            'registered without the plus' => ['966500000001', '+966500000001'],
        ];
    }

    #[DataProvider('sameSaudiNumberWrittenDifferently')]
    public function test_google_writing_the_same_saudi_number_differently_still_verifies_the_seller(string $registeredPhone, string $googlesPhone): void
    {
        $owner = $this->seller($registeredPhone, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => $googlesPhone, 'code' => '482915']]);

        $this->storeFirebaseSession($registeredPhone, self::SESSION)->assertOk();
        $this->verifyFirebaseCode($registeredPhone, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes($registeredPhone, self::SESSION)->assertOk();

        $this->assertTookPassword($owner);
    }

    /** @return array<string, array{string, string}> what the seller registered with, and what Google calls the same Gulf number */
    public static function sameGulfNumberWrittenDifferently(): array
    {
        return [
            'the Emirates with the trunk zero kept after the country code' => ['+9710501234567', '+971501234567'],
            'Kuwait with the trunk zero kept after the country code' => ['+965050012345', '+96550012345'],
        ];
    }

    #[DataProvider('sameGulfNumberWrittenDifferently')]
    public function test_google_writing_the_same_gulf_number_differently_still_verifies_the_seller(string $registeredPhone, string $googlesPhone): void
    {
        $owner = $this->seller($registeredPhone, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => $googlesPhone, 'code' => '482915']]);

        $this->storeFirebaseSession($registeredPhone, self::SESSION)->assertOk();
        $this->verifyFirebaseCode($registeredPhone, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes($registeredPhone, self::SESSION)->assertOk();

        $this->assertTookPassword($owner);
    }

    public function test_a_verified_session_belongs_to_its_phone_only(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $other = $this->seller('+966500000002', 'other@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->resetPasswordTheWayTheAppDoes('+966500000002', self::SESSION)->assertStatus(400);
        $this->resetPasswordTheWayTheAppDoes('%', self::SESSION)->assertStatus(400);

        $this->assertKeepsPassword($owner, $other);
    }

    public function test_storing_another_token_cannot_ride_on_a_verified_session(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->storeFirebaseSession(self::OWNER_PHONE, 'attacker-chosen')->assertOk();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, 'attacker-chosen')->assertStatus(400);
        $this->assertKeepsPassword($owner);
    }

    public function test_a_stranger_overwriting_the_stored_session_does_not_stop_the_seller_verifying(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->storeFirebaseSession(self::OWNER_PHONE, 'stranger-chosen');

        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();
        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertOk();

        $this->assertTookPassword($owner);
    }

    public function test_a_token_stored_through_the_customer_endpoint_cannot_ride_on_a_verified_session(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->postJson('/api/v1/auth/firebase-auth-token-store', ['identity' => self::OWNER_PHONE, 'token' => 'attacker-chosen'])->assertOk();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, 'attacker-chosen')->assertStatus(400);
        $this->assertKeepsPassword($owner);
    }

    public function test_a_verified_session_stops_working_after_it_has_been_unused_for_a_while(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->travel(31)->minutes();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertStatus(400);
        $this->assertKeepsPassword($owner);
    }

    public function test_a_verified_session_is_still_good_for_the_time_it_takes_to_type_a_password(): void
    {
        $owner = $this->seller(self::OWNER_PHONE, 'owner@example.test');
        $this->firebaseKnows([self::SESSION => ['phone' => self::OWNER_PHONE, 'code' => '482915']]);
        $this->storeFirebaseSession(self::OWNER_PHONE, self::SESSION);
        $this->verifyFirebaseCode(self::OWNER_PHONE, self::SESSION, '482915')->assertOk();

        $this->travel(10)->minutes();

        $this->resetPasswordTheWayTheAppDoes(self::OWNER_PHONE, self::SESSION)->assertOk();
        $this->assertTookPassword($owner);
    }
}
