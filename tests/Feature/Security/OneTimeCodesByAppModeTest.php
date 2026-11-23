<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Services\Web\CustomerAuthService;
use FilesystemIterator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * Every verification and reset code of the apps is random when the application runs in live mode (APP_MODE=live, which is also what a missing
 * APP_MODE means) and a fixed test value in the other modes: `(config('app.mode') == 'live') ? random_int(...) : 123456` (1234 for the four-digit
 * codes). The mode is the `mode` line of config/app.php, which reads APP_MODE. A configuration without that line made every code the
 * constant on a live server, so the person who knew a phone number could ask for a code and type it.
 *
 * Pinned here: the config line; the codes that leave ten of the twelve places that decide a code by the mode, through the real routes of the
 * apps and of the website (nine: the customer, delivery-man and seller apps, the website's resend of a code, the vendor panel's password
 * reset) and the service the website uses (one); and two scans of app/. The first fails when a fixed code (123456 or 1234, quoted or not) is
 * written anywhere that is not one of the twelve known places, or when one of them stops deciding by the mode it decides by now. The second
 * fails when a number of four to eight digits is assigned to a variable called token, otp or code, whatever the number: it is a heuristic
 * and does not see a fixed code under another name or in another form. The two places that no request here drives are covered by the scans
 * only: two actions that no route reaches (RestAPI\v1\auth\ForgotPasswordController::reset_password_request and
 * PhoneVerificationController::check_phone).
 *
 * No SMS gateway is configured, so the routes answer that the message could not be sent; the code is written to its table before a gateway
 * is asked, and it is read from there.
 *
 * A defect of main that this test goes round and does not pin: the website's resend (Customer\Auth\CustomerAuthController::resendOTPCode)
 * for a customer who has no stored code makes `new PhoneOrEmailVerification()` in a file that does not import the class, so the request ends
 * in a 500. The test stores a code first, as the verification step of the website does.
 */
class OneTimeCodesByAppModeTest extends ApiAddressTestCase
{
    private const PHONE = '0551234567';

    private const EMAIL = 'shopper@example.test';

    private const SIX_DIGITS = '/^\d{6}$/';

    private const FOUR_DIGITS = '/^\d{4}$/';

    /**
     * Every place in app/ that picks a fixed code by the mode: the file, how many times, the mode it asks for, the code it picks outside of it and the code it picks inside of it.
     *
     * @var array<string, array{count: int, condition: string, random: string, fixed: string}>
     */
    private const DECIDERS = [
        'app/Services/Web/CustomerAuthService.php' => ['count' => 1, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(100000, 999999)', 'fixed' => '123456'],
        'app/Http/Controllers/Customer/Auth/CustomerAuthController.php' => ['count' => 1, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(100000, 999999)', 'fixed' => '123456'],
        'app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php' => ['count' => 3, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(100000, 999999)', 'fixed' => '123456'],
        'app/Http/Controllers/RestAPI/v1/auth/ForgotPasswordController.php' => ['count' => 1, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(100000, 999999)', 'fixed' => '123456'],
        'app/Http/Controllers/RestAPI/v1/auth/PhoneVerificationController.php' => ['count' => 2, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(1000, 9999)', 'fixed' => '1234'],
        'app/Http/Controllers/RestAPI/v2/delivery_man/auth/LoginController.php' => ['count' => 1, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(1000, 9999)', 'fixed' => '1234'],
        'app/Http/Controllers/RestAPI/v2/seller/auth/ForgotPasswordController.php' => ['count' => 1, 'condition' => "config('app.mode') == 'dev'", 'random' => 'random_int(1000, 9999)', 'fixed' => '1234'],
        'app/Http/Controllers/RestAPI/v3/seller/auth/ForgotPasswordController.php' => ['count' => 1, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(100000, 999999)', 'fixed' => '123456'],
        'app/Http/Controllers/Vendor/Auth/ForgotPasswordController.php' => ['count' => 1, 'condition' => "config('app.mode') == 'live'", 'random' => 'random_int(111111, 999999)', 'fixed' => '123456'],
    ];

    /** @var array<string, int> the places that write 123456 or 1234 without issuing a code: the example value of a code field in the generated API documentation */
    private const EXAMPLE_VALUES = ['app/Services/DeveloperPortal/Support/RuleTranslator.php' => 1];

    /** A fixed number of four to eight digits assigned to a variable that holds a token, an otp or a code: how a code is made constant, whatever the number. */
    private const FIXED_NUMBER_ASSIGNED_TO_A_CODE = '/\$\w*(?:token|otp|code)\w*\s*=\s*[\'"]?\d{4,8}[\'"]?\s*;/i';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('temporary_token')->nullable();
        });
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
        });
        Schema::create('delivery_men', static function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('country_code')->nullable();
            $table->timestamps();
        });
        Schema::create('phone_or_email_verifications', static function (Blueprint $table): void {
            $table->id();
            $table->string('phone_or_email')->nullable();
            $table->text('token')->nullable();
            $table->integer('otp_hit_count')->default(0);
            $table->integer('is_temp_blocked')->default(0);
            $table->dateTime('temp_block_time')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('password_resets', static function (Blueprint $table): void {
            $table->id();
            $table->string('identity')->nullable();
            $table->string('token')->nullable();
            $table->string('user_type')->default('customer');
            $table->integer('otp_hit_count')->default(0);
            $table->integer('is_temp_blocked')->default(0);
            $table->dateTime('temp_block_time')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('login_setups', static function (Blueprint $table): void {
            $table->id();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('zender_settings', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        DB::table('login_setups')->insert(['key' => 'email_verification', 'value' => '1']);
        DB::table('business_settings')->insert([
            ['type' => 'otp_resend_time', 'value' => '0'],
            ['type' => 'vendor_forgot_password_method', 'value' => json_encode('phone')],
        ]);
        Cache::flush();

        $this->makeCustomer(['phone' => self::PHONE, 'temporary_token' => 'temporary-token-of-the-customer']);
        DB::table('sellers')->insert(['f_name' => 'Seller one', 'phone' => self::PHONE, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('delivery_men')->insert(['f_name' => 'Delivery man one', 'phone' => self::PHONE, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        mt_srand();

        parent::tearDown();
    }

    private function codeOf(string $table, string $identityColumn, ?string $userType = null): string
    {
        return (string) DB::table($table)->where($identityColumn, self::PHONE)
            ->when($userType !== null, static fn ($query) => $query->where('user_type', $userType))
            ->latest('id')
            ->value('token');
    }

    private function codeAskedForTheCustomerPhone(): string
    {
        $this->postJson('/api/v1/auth/check-phone', ['phone' => self::PHONE]);

        return $this->codeOf('phone_or_email_verifications', 'phone_or_email');
    }

    private function codeAskedForTheCustomerEmail(): string
    {
        $this->postJson('/api/v1/auth/check-email', ['email' => self::EMAIL]);

        return (string) DB::table('phone_or_email_verifications')->where('phone_or_email', self::EMAIL)->value('token');
    }

    private function codeAskedToResetACustomerPassword(): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email_or_phone' => self::PHONE, 'type' => 'phone']);

        return $this->codeOf('password_resets', 'identity');
    }

    private function codeAskedAgainForTheCustomerPhone(): string
    {
        $this->postJson('/api/v1/auth/resend-otp-check-phone', ['temporary_token' => 'temporary-token-of-the-customer', 'phone' => self::PHONE]);

        return $this->codeOf('phone_or_email_verifications', 'phone_or_email');
    }

    private function codeAskedToResetADeliveryManPassword(): string
    {
        $this->postJson('/api/v2/delivery-man/auth/forgot-password', ['identity' => self::PHONE]);

        return $this->codeOf('password_resets', 'identity', userType: 'delivery_man');
    }

    private function codeAskedToResetASellerPassword(): string
    {
        $this->postJson('/api/v3/seller/auth/forgot-password', ['identity' => self::PHONE]);

        return $this->codeOf('password_resets', 'identity', userType: 'seller');
    }

    private function codeAskedToResetASellerPasswordByTheOlderApp(): string
    {
        $this->postJson('/api/v2/seller/auth/forgot-password', ['identity' => self::PHONE]);

        return $this->codeOf('password_resets', 'identity', userType: 'seller');
    }

    private function codeTheWebsiteGetsFromTheService(): string
    {
        return (string) (new CustomerAuthService())->getCustomerVerificationToken();
    }

    /** The customer is on the verification step of the website, so a code of theirs is already stored: with none, the action fails on a class it never imports (a 500 on main, left as it is). */
    private function codeAskedAgainForTheCustomerOnTheWebsite(): string
    {
        foreach (['mail_config', 'mail_config_sendgrid'] as $mailService) {
            DB::table('business_settings')->updateOrInsert(['type' => $mailService], ['value' => json_encode(['status' => 0])]);
        }
        DB::table('phone_or_email_verifications')->updateOrInsert(['phone_or_email' => self::PHONE], ['token' => 'no-code-yet', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

        $this->post('/customer/auth/login/resend-otp-code', ['identity' => base64_encode(self::PHONE)]);

        return $this->codeOf('phone_or_email_verifications', 'phone_or_email');
    }

    private function codeAskedToResetAVendorPasswordOnThePanel(): string
    {
        $this->postJson('/vendor/auth/forgot-password/index', ['identity' => self::PHONE]);

        return $this->codeOf('password_resets', 'identity', userType: 'seller');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> the way a code is asked for, the shape of a random code, the fixed code and the mode that keeps it
     */
    public static function issuers(): array
    {
        return [
            'POST /api/v1/auth/check-phone' => ['codeAskedForTheCustomerPhone', self::SIX_DIGITS, '123456', 'demo'],
            'POST /api/v1/auth/check-email' => ['codeAskedForTheCustomerEmail', self::SIX_DIGITS, '123456', 'demo'],
            'POST /api/v1/auth/forgot-password' => ['codeAskedToResetACustomerPassword', self::SIX_DIGITS, '123456', 'demo'],
            'POST /api/v1/auth/resend-otp-check-phone' => ['codeAskedAgainForTheCustomerPhone', self::FOUR_DIGITS, '1234', 'demo'],
            'POST /api/v2/delivery-man/auth/forgot-password' => ['codeAskedToResetADeliveryManPassword', self::FOUR_DIGITS, '1234', 'demo'],
            'POST /api/v3/seller/auth/forgot-password' => ['codeAskedToResetASellerPassword', self::SIX_DIGITS, '123456', 'demo'],
            'POST /api/v2/seller/auth/forgot-password (fixed in dev mode only)' => ['codeAskedToResetASellerPasswordByTheOlderApp', self::FOUR_DIGITS, '1234', 'dev'],
            'CustomerAuthService::getCustomerVerificationToken() (the website)' => ['codeTheWebsiteGetsFromTheService', self::SIX_DIGITS, '123456', 'demo'],
            'POST /customer/auth/login/resend-otp-code (the website)' => ['codeAskedAgainForTheCustomerOnTheWebsite', self::SIX_DIGITS, '123456', 'demo'],
            'POST /vendor/auth/forgot-password/index (the vendor panel)' => ['codeAskedToResetAVendorPasswordOnThePanel', self::SIX_DIGITS, '123456', 'demo'],
        ];
    }

    /** @return array<string, array{0: ?string, 1: string}> the APP_MODE line of the environment (null: no line) and the mode of the application it gives */
    public static function environmentLines(): array
    {
        return [
            'APP_MODE=live' => ['live', 'live'],
            'APP_MODE=demo' => ['demo', 'demo'],
            'APP_MODE=dev' => ['dev', 'dev'],
            'no APP_MODE line' => [null, 'live'],
        ];
    }

    #[DataProvider('environmentLines')]
    public function test_the_application_mode_is_the_app_mode_line_of_the_environment_and_live_when_there_is_none(?string $line, string $expectedMode): void
    {
        $before = [$_ENV['APP_MODE'] ?? null, $_SERVER['APP_MODE'] ?? null, getenv('APP_MODE')];

        try {
            $this->writeAppModeLine(env: $line, server: $line, process: $line ?? false);

            $mode = (require base_path('config/app.php'))['mode'] ?? null;
        } finally {
            $this->writeAppModeLine(env: $before[0], server: $before[1], process: $before[2]);
        }

        $this->assertSame($expectedMode, $mode);
    }

    /** The three places env() reads from: null (false for the process environment) removes the line there. */
    private function writeAppModeLine(?string $env, ?string $server, string|false $process): void
    {
        if ($env === null) {
            unset($_ENV['APP_MODE']);
        } else {
            $_ENV['APP_MODE'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['APP_MODE']);
        } else {
            $_SERVER['APP_MODE'] = $server;
        }
        putenv($process === false ? 'APP_MODE' : 'APP_MODE=' . $process);
    }

    #[DataProvider('issuers')]
    public function test_with_app_mode_live_the_codes_of_repeated_requests_differ_and_are_not_the_fixed_code(string $issuer, string $randomShape, string $fixedCode): void
    {
        config(['app.mode' => 'live']);
        mt_srand(4242);

        $codes = [$this->{$issuer}(), $this->{$issuer}(), $this->{$issuer}()];

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression($randomShape, $code);
            $this->assertNotSame($fixedCode, $code, 'a live server issued the fixed test code');
        }
        $this->assertGreaterThan(1, count(array_unique($codes)), 'three requests were given the same code: ' . implode(', ', $codes));
    }

    #[DataProvider('issuers')]
    public function test_in_the_mode_that_keeps_the_fixed_code_every_request_gets_it(string $issuer, string $randomShape, string $fixedCode, string $fixedInMode): void
    {
        config(['app.mode' => $fixedInMode]);

        $codes = [$this->{$issuer}(), $this->{$issuer}(), $this->{$issuer}()];

        $this->assertSame([$fixedCode], array_values(array_unique($codes)));
    }

    public function test_the_older_seller_app_issues_a_random_code_in_demo_mode_and_keeps_the_fixed_one_in_dev_mode_only(): void
    {
        config(['app.mode' => 'demo']);
        mt_srand(4242);

        $codes = [$this->codeAskedToResetASellerPasswordByTheOlderApp(), $this->codeAskedToResetASellerPasswordByTheOlderApp(), $this->codeAskedToResetASellerPasswordByTheOlderApp()];

        $this->assertNotContains('1234', $codes);
        $this->assertGreaterThan(1, count(array_unique($codes)));
    }

    public function test_a_fixed_code_is_written_in_the_code_only_where_the_mode_decides_it(): void
    {
        $found = [];
        foreach ($this->sourcesOfApp() as $file => $source) {
            $fixedCodes = preg_match_all('/(?<![\w.$])(?:123456|1234)(?![\w.])/', $source) - (self::EXAMPLE_VALUES[$file] ?? 0);
            $deciders = preg_match_all('/config\(\s*\'app\.mode\'\s*\)\s*==\s*\'(?<mode>\w+)\'\s*\)?\s*\?\s*(?<first>[^:;?]+?)\s*:\s*(?<second>[^;]+?);/', $source, $matches, PREG_SET_ORDER);
            if ($fixedCodes === 0 && $deciders === 0) {
                continue;
            }
            $this->assertSame($deciders, $fixedCodes, "{$file} writes a fixed code that no mode decides (or a mode decides one this list does not know)");
            foreach ($matches as $decider) {
                $found[$file][] = [
                    "config('app.mode') == '{$decider['mode']}'",
                    $decider['mode'] === 'dev' ? trim($decider['second']) : trim($decider['first']),
                    $decider['mode'] === 'dev' ? trim($decider['first']) : trim($decider['second']),
                ];
            }
        }
        ksort($found);

        $expected = [];
        foreach (self::DECIDERS as $file => $decider) {
            $expected[$file] = array_fill(0, $decider['count'], [$decider['condition'], $decider['random'], $decider['fixed']]);
        }
        ksort($expected);

        $this->assertSame($expected, $found, 'a fixed code is decided by something else than the known mode checks: a live server must never issue it');
    }

    public function test_no_number_is_assigned_to_a_token_an_otp_or_a_code_whatever_the_number(): void
    {
        $assignments = [];
        foreach ($this->sourcesOfApp() as $file => $source) {
            if (preg_match_all(self::FIXED_NUMBER_ASSIGNED_TO_A_CODE, $source, $matches) > 0) {
                $assignments[$file] = $matches[0];
            }
        }

        $this->assertSame([], $assignments, 'a token, an otp or a code is given a fixed number: a live server must never issue it');
    }

    /** @return array<string, string> the source of every PHP file of app/, by path relative to the project */
    private function sourcesOfApp(): array
    {
        $sources = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        $this->assertGreaterThan(500, count($sources), 'the scan read the code: a scan of nothing passes');

        return $sources;
    }
}
