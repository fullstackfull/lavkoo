<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Web\HomeController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\ReadsResolvedRouteMiddleware;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I2-14: the platform-wide maintenance mode, which is not the maintenance of one storefront (StorefrontStopModesTest). An administrator turns it on in the panel for the systems
 * the platform has (here the website and the vendor panel), for a time or until the administrator turns it off. While it is on, the website sends its visitors to the maintenance page, the
 * vendor panel does the same, and the answer of /api/v1/config says so, with the systems, the message and the duration an app needs. A signed-in administrator is exempt. The admin panel and
 * its sign-in pages carry no maintenance gate, by any spelling of it (the route table is scanned), so a stranger gets the not found of the panel, the sign-in pages stay open to reach the panel
 * and switch the maintenance off, and the trait that reads the maintenance leaves an address under admin/ open even on a route that is given the gate. Turned off, every door answers as usual.
 *
 * Every case drives the real routes and turns the maintenance on through the real route of the panel (the business settings it writes, and the cache entry the gate reads). The real
 * home page of the website reads the installation database, so the controller behind / is replaced by one that answers with a marker. The routes and the maintenance gate are the real ones;
 * these are not: the global request middleware (language, device, SEO redirects, the no-index of a theme preview, and the telemetry, monitoring and analytics recorders) and the activation check
 * of the vendor and admin groups are switched off, the layouts of the admin and vendor panels are stand-ins, and the web configuration the layouts share is fixed. The systems of the apps are
 * left out: turning them on sends a push notification, which is not under test here.
 *
 * The one day and one week choices of the form get their dates from a script of the browser (resources/assets/backend/admin/js/business-settings/maintenance-mode-setting.js). The server reads
 * the dates it is sent and has no 24 hour or week logic of its own, so those cases send the dates the script would, and the script itself is not driven.
 *
 * Known gaps, pinned nowhere: the platform maintenance lives only in the cache entry system_maintenance_mode, so clearing the cache (php artisan optimize:clear) ends it while the business
 * setting still says it is on and the panel still shows it on, which a storefront stop, kept in a business setting, does not suffer; and the flag of the config answer is on for any system that
 * is ticked, so an app reads selected_maintenance_system to know whether the maintenance concerns it.
 */
class PlatformMaintenanceModeTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use ReadsResolvedRouteMiddleware;
    use RestoresLanguageFiles;

    private const MESSAGE = 'We are back on Sunday';

    private const BODY = 'Thank you for your patience.';

    private const HOME_PAGE = 'The home page of the storefront';

    private const ADMIN_PAGE = 'id="k-settings-search"';

    private const SIGN_IN_FORM = 'id="admin-login-form"';

    private const VENDOR_LOGIN_PAGE = 'id="vendor-login-form"';

    private const MAINTENANCE_PAGE = 'maintenance-mode-container';

    private const GATED_PROBE_ADDRESS = 'admin/zz-probe-a-gated-route-under-admin';

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
        // Guarded: more than one world builder creates this table, and a test that builds two of them raised "table already exists" before a single assertion ran.
        if (!Schema::hasTable('system_tax_setups')) {
            Schema::create('system_tax_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            });
        }
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
        DB::table('business_settings')->insert([['type' => 'admin_login_url', 'value' => 'admin'], ['type' => 'employee_login_url', 'value' => 'employee']]);
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

    private function assertTheConfigNamesTheSystems(array $systems): void
    {
        $every = array_fill_keys(['user_app', 'user_website', 'vendor_app', 'deliveryman_app', 'vendor_panel'], 0);

        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('maintenance_mode.selected_maintenance_system', array_merge($every, array_fill_keys($systems, 1)));
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

    public function test_a_stranger_on_an_admin_route_gets_the_not_found_of_the_panel_and_not_the_maintenance_page(): void
    {
        $this->turnMaintenanceOn();

        $this->get('/admin/settings')->assertNotFound();
    }

    // ---- the doors of the administrator ------------------------------------------------------------------------------------------------------

    #[DataProvider('theSignInPages')]
    public function test_the_sign_in_of_the_admin_and_of_an_employee_is_served_to_a_stranger_in_maintenance(string $address): void
    {
        $this->turnMaintenanceOn();

        $this->get($address)->assertOk()->assertSee(self::SIGN_IN_FORM, false);
    }

    public static function theSignInPages(): array
    {
        return ['the admin' => ['/login/admin'], 'an employee' => ['/login/employee']];
    }

    public function test_no_admin_route_and_no_sign_in_route_of_the_admin_carries_the_maintenance_gate_by_any_name(): void
    {
        $carriers = $this->routesDeclaredWith(gate: MaintenanceModeMiddleware::class);
        $doors = collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => $this->isADoorOfTheAdmin(route: $route))->map(fn (RoutingRoute $route) => $route->uri())->all();
        $closed = collect($carriers)->filter(fn (RoutingRoute $route) => $this->isADoorOfTheAdmin(route: $route))->map(fn (RoutingRoute $route) => implode('|', array_diff($route->methods(), ['HEAD'])) . ' /' . $route->uri())->values()->all();

        $this->assertNotEmpty($carriers, 'the scan sees the routes that carry the gate: the website, the vendor panel and the seller center');
        $this->assertContains('login/{loginUrl}', $doors, 'the scan reaches the sign-in of the admin');
        $this->assertContains('admin/settings', $doors, 'and the panel');
        $this->assertSame([], $closed, "A maintenance would close these doors of the administrator, who could then not sign in to turn it off:\n" . implode("\n", $closed));
    }

    public function test_the_scan_sees_the_gate_whether_a_route_names_it_by_alias_by_class_with_a_parameter_or_through_a_group(): void
    {
        Route::get('admin/zz-probe-alias', fn () => 'probe')->middleware('maintenance_mode');
        Route::get('admin/zz-probe-class', fn () => 'probe')->middleware(MaintenanceModeMiddleware::class);
        Route::get('admin/zz-probe-parameter', fn () => 'probe')->middleware('maintenance_mode:vendor');
        Route::middleware('maintenance_mode')->group(fn () => Route::get('admin/zz-probe-group', fn () => 'probe'));
        Route::get('admin/zz-probe-opted-out', fn () => 'probe')->middleware('maintenance_mode')->withoutMiddleware('maintenance_mode');
        Route::get('admin/zz-probe-without', fn () => 'probe');

        $seen = collect($this->routesDeclaredWith(gate: MaintenanceModeMiddleware::class))->map(fn (RoutingRoute $route) => $route->uri())->filter(fn (string $uri) => str_contains($uri, 'zz-probe'))->sort()->values()->all();

        $this->assertSame(['admin/zz-probe-alias', 'admin/zz-probe-class', 'admin/zz-probe-group', 'admin/zz-probe-parameter'], $seen);
    }

    public function test_an_address_under_admin_is_never_closed_by_the_website_maintenance_even_on_a_route_that_is_given_the_gate(): void
    {
        Route::middleware(['web', 'maintenance_mode'])->get(self::GATED_PROBE_ADDRESS, fn () => 'the panel stays open');
        $this->turnMaintenanceOn(systems: ['user_website']);

        $this->get('/' . self::GATED_PROBE_ADDRESS)->assertOk()->assertSee('the panel stays open');
    }

    private function isADoorOfTheAdmin(RoutingRoute $route): bool
    {
        return collect(['admin', 'login'])->contains(fn (string $door) => $route->uri() === $door || str_starts_with($route->uri(), $door . '/'));
    }

    // ---- the administrator ---------------------------------------------------------------------------------------------------------------------

    public function test_a_signed_in_administrator_is_exempt_on_the_website_the_vendor_panel_and_the_config_answer_and_works_in_the_panel(): void
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
        $this->assertTheConfigNamesTheSystems(systems: $systems);
    }

    #[DataProvider('everySetOfSystemsTicked')]
    public function test_the_flag_of_the_config_answer_is_on_whichever_system_is_ticked_and_the_apps_read_the_systems_to_tell_if_it_concerns_them(array $systems): void
    {
        $this->turnMaintenanceOn(systems: $systems);

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

    public static function everySetOfSystemsTicked(): array
    {
        return array_map(fn (array $row) => [$row[0]], self::systemsAndTheDoorsTheyClose());
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
            'one day, with the dates the form fills in: from now to a day later' => ['one_day', 0, 24 * 60, true],
            'one week, with the dates the form fills in: from now to a week later' => ['one_week', 0, 7 * 24 * 60, true],
            'a chosen time that has started and not ended' => ['customize', -60, 60, true],
            'a chosen time that has not started' => ['customize', 60, 120, false],
            'a chosen time that is over' => ['customize', -120, -60, false],
        ];
    }
}
