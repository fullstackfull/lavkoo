<?php

namespace Tests\Feature\Security;

use App\Events\VendorRegistrationEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Requests\Admin\VendorAddRequest;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Registering as a seller is public, on three doors (the website, API v2 and API v3), and the admin has two server-side gates the doors must
 * all honour: the master admin's e-mail is not a seller's, and seller registration can be switched off (or the business is a single store).
 *
 * The review of the seller approval fix found the website's door and the admin's add-vendor action comparing the master admin's e-mail as
 * text, so a case variant of it registered there while the two APIs refused it (MySQL compares e-mails without regard to case), and the
 * switch enforced by the registration page only: the three POST handlers kept creating pending sellers.
 */
class SellerRegistrationGatesTest extends TestCase
{
    use BuildsSellerSchema;
    use RestoresLanguageFiles;

    private const PASSWORD = 'Aa1!aaaaaa';
    private const MASTER_ADMIN_EMAIL = 'master.admin@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Cache::flush();
        Storage::fake('public');
        $this->buildSellerSchema();
        DB::table('admins')->where('admin_role_id', 1)->update(['email' => self::MASTER_ADMIN_EMAIL]);
        $this->withoutMiddleware([
            ThrottleRequests::class, Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class, MaintenanceModeMiddleware::class,
        ]);
        Event::fake([VendorRegistrationEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'f_name' => 'Sara', 'l_name' => 'Hassan', 'phone' => '0500000001', 'email' => 'sara.seller@example.test',
            'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD, 'shop_name' => 'Sara Shop', 'shop_address' => 'Riyadh',
            'image' => UploadedFile::fake()->image('profile.png'), 'logo' => UploadedFile::fake()->image('logo.png'), 'banner' => UploadedFile::fake()->image('banner.png'),
        ], $overrides);
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> the address of each public door and the headers it is called with */
    public static function publicDoors(): array
    {
        return [
            'the website' => ['/vendor/auth/registration/add', ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']],
            'api v2' => ['/api/v2/seller/registration', ['Accept' => 'application/json']],
            'api v3' => ['/api/v3/seller/registration', ['Accept' => 'application/json']],
        ];
    }

    private function register(string $door, array $headers, array $overrides = []): TestResponse
    {
        return $this->post($door, $this->fields($overrides), $headers);
    }

    private function sellerCount(): int
    {
        return DB::table('sellers')->count();
    }

    // ---- the master admin's e-mail ----------------------------------------------------------------------------------------------------

    #[DataProvider('publicDoors')]
    public function test_the_master_admins_email_is_refused_on_every_public_door_in_any_letter_case(string $door, array $headers): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite compares an e-mail with regard to case; the gap this guards is the one MySQL and MariaDB have.');
        }

        foreach ([self::MASTER_ADMIN_EMAIL, 'Master.Admin@Example.Test', 'MASTER.ADMIN@EXAMPLE.TEST'] as $email) {
            $this->register($door, $headers, ['email' => $email]);
        }

        $this->assertSame(0, $this->sellerCount());
    }

    public function test_the_admins_add_vendor_action_refuses_the_master_admins_email_in_any_letter_case_too(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite compares an e-mail with regard to case.');
        }
        $fields = $this->fields(['email' => 'Master.Admin@Example.Test', 'status' => 'approved']);
        $files = array_intersect_key($fields, array_flip(['image', 'logo', 'banner']));
        $request = VendorAddRequest::createFrom(Request::create('/admin/vendors/add', 'POST', array_diff_key($fields, $files), [], $files));

        $response = app(VendorController::class)->add($request);

        $this->assertSame(1, $response->getData(true)['error']);
        $this->assertSame(0, $this->sellerCount());
    }

    public function test_the_helper_that_every_door_asks_compares_the_way_the_database_does(): void
    {
        $this->assertTrue(Admin::masterAdminUsesEmail(self::MASTER_ADMIN_EMAIL));
        $this->assertFalse(Admin::masterAdminUsesEmail('someone.else@example.test'));
        foreach ([null, '', [], [self::MASTER_ADMIN_EMAIL], 5, true] as $notAnEmail) {
            $this->assertFalse(Admin::masterAdminUsesEmail($notAnEmail), json_encode($notAnEmail));
        }
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertTrue(Admin::masterAdminUsesEmail('Master.Admin@Example.Test'));
        }
    }

    #[DataProvider('publicDoors')]
    public function test_an_ordinary_registration_still_creates_a_pending_seller_on_every_public_door(string $door, array $headers): void
    {
        $this->register($door, $headers);

        $this->assertSame(1, $this->sellerCount());
        $this->assertSame('pending', DB::table('sellers')->value('status'));
    }

    // ---- the registration switch ------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> the setting the admin changed, and its value */
    public static function waysTheAdminClosesRegistration(): array
    {
        return [
            'seller registration switched off' => ['seller_registration', '0'],
            'the business is a single store' => ['business_mode', 'single'],
        ];
    }

    #[DataProvider('waysTheAdminClosesRegistration')]
    public function test_no_public_door_creates_a_seller_while_the_admin_has_registration_closed(string $setting, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $setting], ['value' => $value]);

        foreach (self::publicDoors() as [$door, $headers]) {
            $this->register($door, $headers);
        }

        $this->assertSame(0, $this->sellerCount());
        $this->assertSame(0, DB::table('shops')->where('seller_id', '>', 0)->count());
    }

    #[DataProvider('publicDoors')]
    public function test_the_closed_door_says_so_and_does_not_look_like_a_success(string $door, array $headers): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'seller_registration'], ['value' => '0']);

        $response = $this->register($door, $headers);

        $this->assertStringContainsString(translate('access_denied'), (string) $response->getContent());
        $this->assertStringNotContainsString('"status":1', (string) $response->getContent());
    }

    #[DataProvider('publicDoors')]
    public function test_registration_is_open_when_the_admin_never_set_the_switch_and_when_it_is_on(string $door, array $headers): void
    {
        $this->register($door, $headers);
        $this->assertSame(1, $this->sellerCount(), 'no setting at all: open');

        DB::table('business_settings')->updateOrInsert(['type' => 'seller_registration'], ['value' => '1']);
        DB::table('business_settings')->updateOrInsert(['type' => 'business_mode'], ['value' => 'multi']);
        $this->register($door, $headers, ['email' => 'second@example.test', 'phone' => '0500000002']);

        $this->assertSame(2, $this->sellerCount());
    }
}
