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
use App\Models\User;
use App\Models\ZenderSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the tests of a customer's password reset, on the app's REST endpoints and on the website: the few tables they read and write
 * (an INFERRED copy of what the installation dump defines, with Passport's real token tables on top), a live-mode OTP (the code is random
 * and is read from the text message that left through the SMS gateway) and no way out to a real provider.
 *
 * Refuses to run unless APP_ENV is 'testing' and, on a server database, the database name says it is a test database: the guard is the one
 * of BuildsFulfillmentSchema, which empties the database before the tables are built.
 *
 * translate() writes a phrase it does not know into the tracked language files; they are put back after every test.
 */
abstract class CustomerPasswordResetTestCase extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected const OLD_PASSWORD = 'Old1!password';

    protected const NEW_PASSWORD = 'Fresh1!password';

    protected const OWNER_PHONE = '+966500000001';

    protected const OWNER_EMAIL = 'owner@example.test';

    /** The test repeats this to the length a real Firebase session can reach, which would not fit a varchar(255) column. */
    protected const SESSION = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    private const PASSPORT_MIGRATIONS = [
        '2016_06_01_000001_create_oauth_auth_codes_table.php',
        '2016_06_01_000002_create_oauth_access_tokens_table.php',
        '2016_06_01_000003_create_oauth_refresh_tokens_table.php',
        '2016_06_01_000004_create_oauth_clients_table.php',
        '2016_06_01_000005_create_oauth_personal_access_clients_table.php',
    ];

    /** @var array{private: string, public: string}|null generated once per run: making a key pair is the slowest step of a test */
    private static ?array $passportKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildCustomerPasswordResetTables();

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
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function buildCustomerPasswordResetTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_phone_verified')->default(false);
            $table->boolean('is_email_verified')->default(false);
            $table->string('login_medium')->nullable();
            $table->string('social_id')->nullable();
            $table->string('temporary_token')->nullable();
            $table->string('referral_code')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->string('app_language')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->string('cm_firebase_token_web')->nullable();
            $table->integer('login_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->string('user_type')->default('customer');
            $table->tinyInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamp('expires_at')->nullable();
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
        Schema::create('login_setups', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
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

        foreach (self::PASSPORT_MIGRATIONS as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
        DB::table('oauth_clients')->insert([
            'id' => 1, 'user_id' => null, 'name' => 'Personal Access Client', 'secret' => 'throwaway-secret', 'provider' => 'users',
            'redirect' => 'http://localhost', 'personal_access_client' => 1, 'password_client' => 0, 'revoked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('oauth_personal_access_clients')->insert(['client_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->useThrowawayPassportKeys();
    }

    /** A throwaway RSA key pair: Passport signs the personal access token with it and refuses to exist without one. */
    private function useThrowawayPassportKeys(): void
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        config(['passport.private_key' => self::$passportKeys['private'], 'passport.public_key' => self::$passportKeys['public']]);
    }

    protected function customer(string $phone, string $email, array $overrides = []): int
    {
        return DB::table('users')->insertGetId(array_merge([
            'name' => 'Sara Hassan', 'f_name' => 'Sara', 'l_name' => 'Hassan', 'phone' => $phone, 'email' => $email,
            'password' => bcrypt(self::OLD_PASSWORD), 'is_active' => 1, 'is_phone_verified' => 1, 'is_email_verified' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    protected function passwordIs(int $customerId, string $plain): bool
    {
        return Hash::check($plain, (string) DB::table('users')->where('id', $customerId)->value('password'));
    }

    protected function assertKeepsPassword(int ...$customerIds): void
    {
        foreach ($customerIds as $customerId) {
            $this->assertTrue($this->passwordIs($customerId, self::OLD_PASSWORD), "customer {$customerId} must keep the password they had");
        }
    }

    protected function assertTookPassword(int $customerId): void
    {
        $this->assertTrue($this->passwordIs($customerId, self::NEW_PASSWORD), "customer {$customerId} must have the new password");
        $this->assertFalse($this->passwordIs($customerId, self::OLD_PASSWORD), "customer {$customerId} must no longer have the old password");
    }

    /** A code the server sent, as the app's forgot-password writes it. */
    protected function issueCode(string $identity, string $code, string $userType = 'customer'): void
    {
        DB::table('password_resets')->insert(['identity' => $identity, 'token' => $code, 'user_type' => $userType, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function textMessagesThroughZender(): void
    {
        ZenderSetting::create([
            'enabled' => true, 'base_url' => 'https://zender.test', 'secret' => 'example-gateway-secret', 'type' => 'sms',
            'message' => 'Your code is {{otp}}', 'mode' => 'credits', 'gateway' => '1',
        ]);
        Http::fake(['zender.test/*' => Http::response(['status' => 200], 200)]);
    }

    protected function codeTextedTo(string $phone): string
    {
        $messages = Http::recorded(fn (HttpRequest $request): bool => $request->url() === 'https://zender.test/api/send/sms' && $request['phone'] === $phone);
        $this->assertCount(1, $messages, "one text message must have gone to {$phone}");
        $this->assertSame(1, preg_match('/\d{6}/', $messages->first()[0]['message'], $code));

        return $code[0];
    }

    /** The token in the link the server mailed to $email. */
    protected function resetTokenMailedTo(string $email): string
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

    /** The identity the link the server mailed to $email carries, in the base64 the website reads. */
    protected function resetIdentityMailedTo(string $email): string
    {
        $identity = null;
        Event::assertDispatched(PasswordResetEvent::class, function (PasswordResetEvent $event) use ($email, &$identity): bool {
            if ($event->email !== $email) {
                return false;
            }
            parse_str((string) parse_url($event->data['passwordResetURL'], PHP_URL_QUERY), $query);
            $identity = $query['identity'];

            return true;
        });

        return $identity;
    }

    protected function useFirebase(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'firebase_otp_verification'], ['value' => json_encode(['status' => 1, 'web_api_key' => 'example-web-api-key'])]);
        DB::table('business_settings')->updateOrInsert(['type' => 'fcm_credentials'], ['value' => json_encode(['apiKey' => 'example-web-api-key'])]);
        Cache::flush();
    }

    /**
     * Google's side of Firebase phone sign-in: a session is issued to one phone number and accepts one code, and the answer names the phone
     * the session belongs to. $sendsTo is the session Google hands the server when it asks for a code to be texted to a phone.
     *
     * @param array<string, array{phone: ?string, code: string}> $sessions
     * @param array<string, string> $sendsTo
     */
    protected function firebaseKnows(array $sessions, array $sendsTo = []): void
    {
        $this->useFirebase();
        Http::fake(['identitytoolkit.googleapis.com/*' => function (HttpRequest $request) use ($sessions, $sendsTo) {
            if (str_contains($request->url(), 'sendVerificationCode')) {
                return isset($sendsTo[$request['phoneNumber']])
                    ? Http::response(['sessionInfo' => $sendsTo[$request['phoneNumber']]], 200)
                    : Http::response(['error' => ['message' => 'INVALID_PHONE_NUMBER']], 400);
            }

            $session = $sessions[$request['sessionInfo']] ?? null;
            if ($session === null || $session['code'] !== $request['code']) {
                return Http::response(['error' => ['message' => 'INVALID_CODE']], 400);
            }

            return Http::response(array_filter(['idToken' => 'example-id-token', 'isNewUser' => false, 'phoneNumber' => $session['phone']], static fn ($value): bool => $value !== null), 200);
        }]);
    }

    protected function loginWithTheApp(string $identity, string $password, string $type = 'phone'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email_or_phone' => $identity, 'password' => $password, 'type' => $type]);
    }

    protected function tokensOf(int $customerId): int
    {
        return DB::table('oauth_access_tokens')->where('user_id', $customerId)->count();
    }

    protected function signInTheApp(int $customerId): void
    {
        User::query()->findOrFail($customerId)->createToken('LaravelAuthApp');
    }
}
