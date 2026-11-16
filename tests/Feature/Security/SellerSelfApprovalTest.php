<?php

namespace Tests\Feature\Security;

use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Controllers\Vendor\Auth\RegisterController as PublicVendorRegisterController;
use App\Http\Requests\Admin\VendorAddRequest;
use App\Http\Requests\Vendor\VendorAddRequest as PublicVendorAddRequest;
use App\Models\Seller;
use App\Services\VendorService;
use FilesystemIterator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\BuildsSellerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Registering as a seller is public, so the account it creates must always wait for an admin. A seller app or a web form never sends a
 * status, which makes any status in the request a visitor trying to approve themselves. For the same reason it may not take the master
 * admin's e-mail address, on any of the three public registrations.
 */
class SellerSelfApprovalTest extends TestCase
{
    use BuildsSellerSchema;
    use RestoresLanguageFiles;

    private const PASSWORD = 'Aa1!aaaaaa';

    private const MASTER_ADMIN_EMAIL = 'master.admin@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Storage::fake('public');
        $this->buildSellerSchema();
        DB::table('admins')->where('admin_role_id', 1)->update(['email' => self::MASTER_ADMIN_EMAIL]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function registrationFields(array $overrides = []): array
    {
        return array_merge([
            'f_name' => 'Sara',
            'l_name' => 'Hassan',
            'phone' => '0500000001',
            'email' => 'sara.seller@example.test',
            'password' => self::PASSWORD,
            'confirm_password' => self::PASSWORD,
            'shop_name' => 'Sara Shop',
            'shop_address' => 'Riyadh',
            'image' => UploadedFile::fake()->image('profile.png'),
            'logo' => UploadedFile::fake()->image('logo.png'),
            'banner' => UploadedFile::fake()->image('banner.png'),
        ], $overrides);
    }

    private function vendorRequest(array $input): Request
    {
        return Request::create('/', 'POST', array_merge([
            'f_name' => 'Sara',
            'l_name' => 'Hassan',
            'phone' => '0500000001',
            'email' => 'sara.seller@example.test',
            'password' => self::PASSWORD,
        ], $input));
    }

    private function registrationFormRequest(string $requestClass, string $uri, array $overrides = []): FormRequest
    {
        $fields = $this->registrationFields($overrides);
        $files = array_intersect_key($fields, array_flip(['image', 'logo', 'banner']));

        return $requestClass::createFrom(Request::create($uri, 'POST', array_diff_key($fields, $files), [], $files));
    }

    public function test_a_public_vendor_registration_stays_pending_when_the_request_asks_to_be_approved(): void
    {
        $data = app(VendorService::class)->getAddData(request: $this->vendorRequest(['status' => 'approved']));

        $this->assertSame('pending', $data['status']);
    }

    public function test_a_vendor_added_by_an_admin_is_approved_when_the_admin_form_says_so(): void
    {
        $data = app(VendorService::class)->getAddData(
            request: $this->vendorRequest(['status' => 'approved']),
            createdByAdmin: true,
        );

        $this->assertSame('approved', $data['status']);
    }

    public function test_a_vendor_added_by_an_admin_without_a_status_waits_for_approval(): void
    {
        $data = app(VendorService::class)->getAddData(request: $this->vendorRequest([]), createdByAdmin: true);

        $this->assertSame('pending', $data['status']);
    }

    public function test_the_admin_add_vendor_form_still_creates_an_approved_vendor(): void
    {
        Event::fake([VendorRegistrationEvent::class]);
        $request = $this->registrationFormRequest(VendorAddRequest::class, '/admin/vendors/add', ['status' => 'approved']);

        app(VendorController::class)->add($request);

        $this->assertSame('approved', Seller::query()->where('email', 'sara.seller@example.test')->value('status'));
    }

    public function test_the_v3_seller_registration_ignores_a_requested_approval(): void
    {
        Event::fake([VendorRegistrationEvent::class]);

        $response = $this->post('/api/v3/seller/registration', $this->registrationFields(['status' => 'approved']), ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertSame('pending', Seller::query()->where('email', 'sara.seller@example.test')->value('status'));
    }

    public function test_the_v2_seller_registration_ignores_a_requested_approval(): void
    {
        $this->post('/api/v2/seller/registration', $this->registrationFields(['status' => 'approved']), ['Accept' => 'application/json']);

        $this->assertSame(1, DB::table('sellers')->count());
        $this->assertSame('pending', DB::table('sellers')->where('email', 'sara.seller@example.test')->value('status'));
    }

    public function test_the_public_web_vendor_registration_stays_pending_when_the_request_asks_to_be_approved(): void
    {
        Event::fake([VendorRegistrationEvent::class]);
        $request = $this->registrationFormRequest(PublicVendorAddRequest::class, '/vendor/auth/registration/add', ['status' => 'approved']);

        $response = app(PublicVendorRegisterController::class)->add($request);

        $this->assertSame(1, $response->getData(true)['status'] ?? null, $response->getContent());
        $this->assertSame('pending', Seller::query()->where('email', 'sara.seller@example.test')->value('status'));
    }

    public function test_the_public_web_vendor_registration_creates_a_pending_vendor_with_a_shop_and_a_wallet(): void
    {
        Event::fake([VendorRegistrationEvent::class]);
        $request = $this->registrationFormRequest(PublicVendorAddRequest::class, '/vendor/auth/registration/add');

        $response = app(PublicVendorRegisterController::class)->add($request);

        $sellerId = Seller::query()->where('email', 'sara.seller@example.test')->value('id');
        $this->assertSame(1, $response->getData(true)['status'] ?? null, $response->getContent());
        $this->assertSame('pending', Seller::query()->findOrFail($sellerId)->status);
        $this->assertSame('Sara Shop', DB::table('shops')->where('seller_id', $sellerId)->value('name'));
        $this->assertSame(1, DB::table('seller_wallets')->where('seller_id', $sellerId)->count());
        Event::assertDispatched(VendorRegistrationEvent::class);
    }

    public function test_only_the_admin_add_vendor_form_asks_for_an_approved_vendor(): void
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'createdByAdmin')) {
                $files[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
        sort($files);

        $this->assertSame(['app/Http/Controllers/Admin/Vendor/VendorController.php', 'app/Services/VendorService.php'], $files, 'Only the admin add-vendor form may pass createdByAdmin: true; a public registration must never.');
    }

    public function test_the_v3_seller_registration_refuses_the_master_admin_email(): void
    {
        Event::fake([VendorRegistrationEvent::class]);

        $response = $this->post('/api/v3/seller/registration', $this->registrationFields(['email' => self::MASTER_ADMIN_EMAIL]), ['Accept' => 'application/json']);

        $response->assertForbidden();
        $this->assertSame(0, DB::table('sellers')->count());
    }

    public function test_the_v2_seller_registration_refuses_the_master_admin_email(): void
    {
        $response = $this->post('/api/v2/seller/registration', $this->registrationFields(['email' => self::MASTER_ADMIN_EMAIL]), ['Accept' => 'application/json']);

        $response->assertForbidden();
        $this->assertSame(0, DB::table('sellers')->count());
    }

    public function test_the_v3_seller_registration_accepts_any_other_email_while_a_master_admin_exists(): void
    {
        Event::fake([VendorRegistrationEvent::class]);

        $this->post('/api/v3/seller/registration', $this->registrationFields(), ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, DB::table('sellers')->where('email', 'sara.seller@example.test')->count());
    }

    public function test_the_v2_seller_registration_accepts_any_other_email_while_a_master_admin_exists(): void
    {
        $this->post('/api/v2/seller/registration', $this->registrationFields(), ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, DB::table('sellers')->where('email', 'sara.seller@example.test')->count());
    }

    public function test_the_schema_builder_refuses_a_database_that_is_not_a_test_database(): void
    {
        // Nothing listens on port 1, so if the guard were ever removed this fails on the connection instead of emptying a database that exists.
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.unix_socket' => '',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
            'database.connections.mysql.database' => 'lavkoo_production',
        ]);
        DB::purge();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to drop every table of 'lavkoo_production'");

        $this->buildSellerSchema();
    }
}
