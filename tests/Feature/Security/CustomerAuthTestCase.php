<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsCustomerAuthSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Base for the tests of the customer's web sign-in, sign-up and verification pages: the few tables they read, a stand-in storefront
 * shell (the real one needs the installation database), a development-mode OTP (the code is 123456 and no SMS leaves the machine),
 * and no way out to a real provider.
 *
 * translate() writes a phrase it does not know into the tracked language files; they are put back after every test.
 */
abstract class CustomerAuthTestCase extends TestCase
{
    use BuildsCustomerAuthSchema;
    use RestoresLanguageFiles;

    protected const OTP = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }

        Http::preventStrayRequests();
        config(['app.mode' => 'dev']);
        View::getFinder()->prependLocation(base_path('tests/Support/views/storefront'));
        View::addLocation(resource_path('themes/default'));
        View::share('web_config', ['firebase_otp_verification' => null]);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $this->buildCustomerAuthSchema();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    protected function makeCustomer(array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId($attributes + [
            'name' => 'Layla Victim',
            'f_name' => 'Layla',
            'l_name' => 'Victim',
            'email' => 'layla.victim@example.test',
            'phone' => '0500000005',
            'password' => bcrypt('victim-password'),
            'is_active' => 1,
            'is_phone_verified' => 1,
            'is_email_verified' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    /** @param array<string, bool|int> $options the login options and verification switches the admin sets, by their key */
    protected function configureLogin(array $options): void
    {
        DB::table('login_setups')->delete();
        foreach ($options as $key => $value) {
            DB::table('login_setups')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        cache()->flush();
    }
}
