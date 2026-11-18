<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Web\HomeController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I2-14: the platform-wide maintenance mode, which is not the maintenance of one storefront (StorefrontStopModesTest). An administrator turns it on in the panel for the systems
 * the platform has (here the website and the vendor panel), for a time or until the administrator turns it off. While it is on, the website sends its visitors to the maintenance page, the
 * vendor panel does the same, and the answer of /api/v1/config says so, with the systems, the message and the duration an app needs. A signed-in administrator is exempt, and so are
 * the admin routes, which carry no gate at all. Turned off, every door answers as usual.
 *
 * Every case drives the real routes and turns the maintenance on through the real route of the panel (the business settings it writes, and the cache entry the gate reads). The real
 * home page of the website reads the installation database, so the controller behind / is replaced by one that answers with a marker; its route and its middleware are the real
 * ones, which is what a maintenance is about. The systems of the apps are left out: turning them on sends a push notification, which is not under test here.
 */
class PlatformMaintenanceModeTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const MESSAGE = 'We are back on Sunday';

    private const BODY = 'Thank you for your patience.';

    private const HOME_PAGE = 'The home page of the storefront';

    private const ADMIN_PAGE = 'id="k-settings-search"';

    private const VENDOR_LOGIN_PAGE = 'id="vendor-login-form"';

    private const MAINTENANCE_PAGE = 'maintenance-mode-container';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->buildWhatTheConfigAnswerReads();

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::addLocation(resource_path('themes/default'));
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        View::share('web_config', ['fav_icon' => ['path' => '/favicon.ico']]);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        Http::preventStrayRequests();

        $this->instance(HomeController::class, new class (self::HOME_PAGE) {
            public function __construct(private readonly string $page)
            {
            }

            public function index()
            {
                return response($this->page);
            }
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The settings, the login setup and the few tables that the config answer and the pages of the website read before they get to what is under test. */
    private function buildWhatTheConfigAnswerReads(): void
    {
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2024_07_31_133306_create_login_setups_table.php'))->up();
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('key_name')->nullable();
            $table->text('live_values')->nullable();
            $table->text('test_values')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->default('test');
            $table->integer('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('shipping_type')->nullable();
        });
        Schema::create('system_tax_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('meta_app_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('app_type')->nullable();
        });
        Schema::create('help_topics', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('default');
            $table->boolean('status')->default(true);
        });
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->integer('visit_count')->default(0);
        });
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('status')->default(false);
            $table->string('api_key')->nullable();
        });

        foreach (['login_options' => ['manual_login' => 1, 'otp_login' => 0, 'social_login' => 0], 'social_media_for_login' => ['google' => 0, 'facebook' => 0, 'apple' => 0], 'email_verification' => 0, 'phone_verification' => 0] as $key => $value) {
            DB::table('login_setups')->insert(['key' => $key, 'value' => json_encode($value)]);
        }
        foreach ([
            'social_login' => [], 'apple_login' => [], 'pnc_language' => ['en'], 'offline_payment' => ['status' => 0], 'digital_payment' => ['status' => 0], 'cash_on_delivery' => ['status' => 1],
            'colors' => ['primary' => '#111111', 'secondary' => '#222222'], 'firebase_otp_verification' => ['status' => 0, 'web_api_key' => ''],
            'language' => [['id' => 1, 'name' => 'english', 'code' => 'en', 'status' => 1, 'default' => true]],
        ] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => json_encode($value)]);
        }
        Cache::flush();
    }

    private function signInAsAdmin(): static
    {
        return $this->actingAs(Admin::findOrFail(1), 'admin');
    }

    private function signOut(): static
    {
        auth()->forgetGuards();

        return $this;
    }

    /** What the form of the panel posts: the systems ticked, the duration chosen and the message to show. */
    private function turnMaintenanceOn(array $systems = ['user_website', 'vendor_panel'], string $duration = 'until_change', ?string $from = null, ?string $until = null): void
    {
        $this->signInAsAdmin()
            ->post(route('admin.business-settings.maintenance-mode'), array_merge(array_fill_keys($systems, 1), [
                'maintenance_mode' => 1, 'maintenance_duration' => $duration, 'start_date' => $from, 'end_date' => $until,
                'maintenance_message' => self::MESSAGE, 'message_body' => self::BODY,
            ]))
            ->assertRedirect();
        $this->signOut();
    }

    private function turnMaintenanceOff(): void
    {
        $this->signInAsAdmin()->post(route('admin.business-settings.maintenance-mode'), ['maintenance_mode' => 0, 'maintenance_duration' => 'until_change'])->assertRedirect();
        $this->signOut();
    }

    /** The moment the form of the panel sends: a datetime-local value, in the minute, that many minutes from now. */
    private function minutesFromNow(?int $minutes): ?string
    {
        return $minutes === null ? null : now()->addMinutes($minutes)->format('Y-m-d\TH:i');
    }

    private function assertTheWebsiteIsInMaintenance(): void
    {
        $this->get('/')->assertRedirect(route('maintenance-mode'));
        $this->get(route('maintenance-mode'))->assertOk()->assertSee(self::MAINTENANCE_PAGE, false)->assertSee(self::MESSAGE)->assertSee(self::BODY);
    }

    private function assertTheVendorPanelIsInMaintenance(): void
    {
        $maintenancePage = route('maintenance-mode', ['maintenance_system' => 'vendor']);

        $this->get('/vendor/auth/login')->assertRedirect($maintenancePage);
        $this->get($maintenancePage)->assertOk()->assertSee(self::MAINTENANCE_PAGE, false)->assertSee(self::MESSAGE)->assertSee(self::BODY);
    }

    private function assertTheWebsiteAnswersAsUsual(): void
    {
        $this->get('/')->assertOk()->assertSee(self::HOME_PAGE);
    }

    private function assertTheVendorPanelAnswersAsUsual(): void
    {
        $this->get('/vendor/auth/login')->assertOk()->assertSee(self::VENDOR_LOGIN_PAGE, false);
    }

    private function assertTheConfigSaysMaintenance(int $status): void
    {
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('maintenance_mode.maintenance_status', $status);
    }

    // ---- off ---------------------------------------------------------------------------------------------------------------------------------

    public function test_with_the_maintenance_off_every_door_answers_as_usual(): void
    {
        $this->assertTheWebsiteAnswersAsUsual();
        $this->assertTheVendorPanelAnswersAsUsual();
        $this->assertTheConfigSaysMaintenance(status: 0);
        $this->signInAsAdmin()->get('/admin/settings')->assertOk()->assertSee(self::ADMIN_PAGE, false);
    }

    // ---- on ----------------------------------------------------------------------------------------------------------------------------------

    public function test_the_panel_turns_the_maintenance_on_and_off_by_writing_the_business_setting_and_arming_the_gate(): void
    {
        $this->turnMaintenanceOn();

        $this->assertSame('1', DB::table('business_settings')->where('type', 'maintenance_mode')->value('value'));
        $this->assertSame(1, Cache::get('system_maintenance_mode')['status']);
        $this->assertTheWebsiteIsInMaintenance();

        $this->turnMaintenanceOff();

        $this->assertSame('0', DB::table('business_settings')->where('type', 'maintenance_mode')->value('value'));
        $this->assertNull(Cache::get('system_maintenance_mode'));
        $this->assertTheWebsiteAnswersAsUsual();
        $this->assertTheVendorPanelAnswersAsUsual();
        $this->assertTheConfigSaysMaintenance(status: 0);
    }

    public function test_the_website_answers_the_maintenance_page_with_the_message_of_the_administrator(): void
    {
        $this->turnMaintenanceOn();

        $this->assertTheWebsiteIsInMaintenance();
    }

    public function test_the_vendor_panel_answers_the_maintenance_page_with_the_message_of_the_administrator(): void
    {
        $this->turnMaintenanceOn();

        $this->assertTheVendorPanelIsInMaintenance();
    }

    public function test_the_config_answer_says_the_platform_is_in_maintenance_and_carries_what_an_app_needs_to_show_it(): void
    {
        $this->turnMaintenanceOn();

        $block = $this->getJson('/api/v1/config')->assertOk()->json('maintenance_mode');

        $this->assertEquals([
            'maintenance_status' => 1,
            'selected_maintenance_system' => ['user_app' => 0, 'user_website' => 1, 'vendor_app' => 0, 'deliveryman_app' => 0, 'vendor_panel' => 1],
            'maintenance_messages' => ['business_number' => 0, 'business_email' => 0, 'maintenance_message' => self::MESSAGE, 'message_body' => self::BODY],
            'maintenance_type_and_duration' => ['maintenance_duration' => 'until_change', 'start_date' => null, 'end_date' => null],
        ], $block);
    }

    public function test_the_admin_routes_carry_no_gate_so_a_stranger_gets_what_a_stranger_always_gets(): void
    {
        $this->turnMaintenanceOn();

        $this->get('/admin/settings')->assertNotFound();
    }

    // ---- the administrator ---------------------------------------------------------------------------------------------------------------------

    public function test_a_signed_in_administrator_is_exempt_and_reaches_the_panel(): void
    {
        $this->turnMaintenanceOn();
        $this->signInAsAdmin();

        $this->assertTheWebsiteAnswersAsUsual();
        $this->assertTheVendorPanelAnswersAsUsual();
        $this->assertTheConfigSaysMaintenance(status: 0);
        $this->get('/admin/settings')->assertOk()->assertSee(self::ADMIN_PAGE, false);
    }

    public function test_a_signed_in_customer_or_vendor_is_not_exempt(): void
    {
        $this->turnMaintenanceOn();

        $this->actingAs((new User())->forceFill(['id' => 7, 'f_name' => 'Customer']), 'customer');
        $this->assertTheWebsiteIsInMaintenance();

        $this->signOut()->actingAs((new Seller())->forceFill(['id' => 5, 'f_name' => 'Vendor']), 'seller');
        $this->get('/vendor/dashboard')->assertRedirect(route('maintenance-mode', ['maintenance_system' => 'vendor']));
    }

    // ---- the systems -------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('systemsAndTheDoorsTheyClose')]
    public function test_only_the_systems_that_are_ticked_are_in_maintenance(array $systems, bool $websiteIsClosed, bool $vendorPanelIsClosed): void
    {
        $this->turnMaintenanceOn(systems: $systems);

        $websiteIsClosed ? $this->assertTheWebsiteIsInMaintenance() : $this->assertTheWebsiteAnswersAsUsual();
        $vendorPanelIsClosed ? $this->assertTheVendorPanelIsInMaintenance() : $this->assertTheVendorPanelAnswersAsUsual();
        $this->assertTheConfigSaysMaintenance(status: 1);
    }

    public static function systemsAndTheDoorsTheyClose(): array
    {
        return [
            'the website only' => [['user_website'], true, false],
            'the vendor panel only' => [['vendor_panel'], false, true],
            'both' => [['user_website', 'vendor_panel'], true, true],
        ];
    }

    public function test_the_maintenance_page_of_a_system_that_is_not_in_maintenance_sends_the_visitor_to_the_home_page(): void
    {
        $this->turnMaintenanceOn(systems: ['vendor_panel']);

        $this->get(route('maintenance-mode'))->assertRedirect(route('home'));
    }

    public function test_the_maintenance_is_not_turned_on_without_a_system_to_put_in_it(): void
    {
        $this->signInAsAdmin()->post(route('admin.business-settings.maintenance-mode'), ['maintenance_mode' => 1, 'maintenance_duration' => 'until_change', 'maintenance_message' => self::MESSAGE])->assertRedirect();
        $this->signOut();

        $this->assertNull(DB::table('business_settings')->where('type', 'maintenance_mode')->value('value'));
        $this->assertNull(Cache::get('system_maintenance_mode'));
        $this->assertTheWebsiteAnswersAsUsual();
        $this->assertTheConfigSaysMaintenance(status: 0);
    }

    // ---- the duration ------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('durationsAndWhetherTheyAreOn')]
    public function test_the_maintenance_lasts_until_the_administrator_turns_it_off_or_for_the_time_chosen(string $duration, ?int $startsInMinutes, ?int $endsInMinutes, bool $isOn): void
    {
        $this->turnMaintenanceOn(duration: $duration, from: $this->minutesFromNow(minutes: $startsInMinutes), until: $this->minutesFromNow(minutes: $endsInMinutes));

        $isOn ? $this->assertTheWebsiteIsInMaintenance() : $this->assertTheWebsiteAnswersAsUsual();
        $isOn ? $this->assertTheVendorPanelIsInMaintenance() : $this->assertTheVendorPanelAnswersAsUsual();
        $this->assertTheConfigSaysMaintenance(status: (int) $isOn);
    }

    public static function durationsAndWhetherTheyAreOn(): array
    {
        return [
            'until the administrator changes it' => ['until_change', null, null, true],
            'until the administrator changes it, whatever dates the form still holds' => ['until_change', -120, -60, true],
            'for 24 hours from now' => ['one_day', 0, 24 * 60, true],
            'for a week from now' => ['one_week', 0, 7 * 24 * 60, true],
            'a chosen time that has started and not ended' => ['customize', -60, 60, true],
            'a chosen time that has not started' => ['customize', 60, 120, false],
            'a chosen time that is over' => ['customize', -120, -60, false],
        ];
    }
}
