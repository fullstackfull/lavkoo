<?php

namespace Tests\Feature\Security;

use App\Events\VendorRegistrationEvent;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Requests\Admin\VendorAddRequest;
use App\Models\Seller;
use App\Services\VendorService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Registering as a seller is public, so the account it creates must always wait for an admin. A seller app or a web form never sends a
 * status, which makes any status in the request a visitor trying to approve themselves.
 */
class SellerSelfApprovalTest extends TestCase
{
    private const PASSWORD = 'Aa1!aaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->buildSellerTables();
    }

    private function buildSellerTables(): void
    {
        foreach (['sellers', 'shops', 'seller_wallets', 'admins', 'storages', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shops', function ($table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('address')->nullable();
            $table->string('contact')->nullable();
            $table->string('image')->nullable();
            $table->string('banner')->nullable();
            $table->string('bottom_banner')->nullable();
            $table->string('image_storage_type')->nullable();
            $table->string('banner_storage_type')->nullable();
            $table->string('bottom_banner_storage_type')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->date('tin_expire_date')->nullable();
            $table->string('tin_certificate')->nullable();
            $table->string('tin_certificate_storage_type')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_wallets', function ($table) {
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
        Schema::create('admins', function ($table) {
            $table->id();
            $table->unsignedBigInteger('admin_role_id')->nullable();
            $table->string('email')->nullable();
        });
        Schema::create('storages', function ($table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key');
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
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
        $fields = $this->registrationFields(['status' => 'approved']);
        $files = array_intersect_key($fields, array_flip(['image', 'logo', 'banner']));
        $request = VendorAddRequest::createFrom(Request::create('/admin/vendors/add', 'POST', array_diff_key($fields, $files), [], $files));

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
}
