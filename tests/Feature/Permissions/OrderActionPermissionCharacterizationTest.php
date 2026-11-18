<?php

namespace Tests\Feature\Permissions;

use App\Events\OrderStatusEvent;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordApiResponseShape;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Who may change an order's status or decide a refund, on the seller API, the vendor panel and the admin panel.
 *
 * Every request goes through the router and the real permission middleware: seller_api_auth and seller_can on the seller API,
 * seller and seller_staff_access on the vendor panel, admin and module:orders on the admin panel. Only middleware that does not
 * decide who may act is switched off (licence activation, maintenance mode, locale, telemetry, analytics).
 *
 * A test whose name contains "currently" pins a gap: a principal that should be refused is not. It asserts today's result, so a fix
 * flips it. The others pin a gate that holds, with a positive control beside each refusal.
 */
class OrderActionPermissionCharacterizationTest extends TestCase
{
    use RestoresLanguageFiles;

    private const SELLER_ID = 1;
    private const ORDER_ID = 100;
    private const ORDER_DETAILS_ID = 500;
    private const MISSING_ORDER_ID = 999;

    private const OWNER_TOKEN = 'placeholder-owner-token-longer-than-thirty-characters';
    private const MANAGER_TOKEN = 'placeholder-manager-token-longer-than-thirty-characters';
    private const VIEWER_TOKEN = 'placeholder-viewer-token-longer-than-thirty-characters';

    private const MANAGER_STAFF_ID = 11;
    private const VIEWER_STAFF_ID = 12;

    private const ORDERS_ADMIN_ID = 2;
    private const CATALOG_ADMIN_ID = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        // The panels' session cookies are encrypted; a throwaway key stands in for the one a deployment carries.
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        Http::preventStrayRequests();
        Event::fake([OrderStatusEvent::class]);
        $this->withoutMiddleware([
            ActivationCheckMiddleware::class, MaintenanceModeMiddleware::class, Localization::class, DetectMobile::class,
            ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class,
            RecordAnalytics::class, RecordApiResponseShape::class,
        ]);

        $this->createSchema();
        $this->seedPrincipals();
        $this->insertOrderWithRefundRequest();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_seller_api_staff_with_orders_manage_passes_the_gate_to_the_order_status_controller(): void
    {
        $response = $this->withToken(self::MANAGER_TOKEN)
            ->putJson('/api/v3/seller/orders/order-detail-status/' . self::MISSING_ORDER_ID, ['id' => self::MISSING_ORDER_ID, 'order_status' => 'canceled']);

        $response->assertStatus(404);
        $this->assertSame(0, $response->json('success'));
        $this->assertArrayNotHasKey('errors', (array) $response->json());
    }

    public function test_seller_api_staff_with_only_orders_view_is_refused_an_order_status_change(): void
    {
        $response = $this->withToken(self::VIEWER_TOKEN)
            ->putJson('/api/v3/seller/orders/order-detail-status/' . self::ORDER_ID, ['id' => self::ORDER_ID, 'order_status' => 'canceled']);

        $this->assertPermissionRefusal($response);
        $this->assertSame('pending', DB::table('orders')->where('id', self::ORDER_ID)->value('order_status'));
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    public function test_seller_api_staff_with_only_orders_view_is_refused_an_order_detail_info_update(): void
    {
        $response = $this->withToken(self::VIEWER_TOKEN)
            ->postJson('/api/v3/seller/orders/order-detail-info-update', ['order_id' => self::ORDER_ID, 'payment_status' => 'paid']);

        $this->assertPermissionRefusal($response);
        $this->assertSame('unpaid', DB::table('orders')->where('id', self::ORDER_ID)->value('payment_status'));
    }

    public function test_seller_api_staff_with_orders_manage_passes_the_gate_to_the_refund_decision_controller(): void
    {
        $response = $this->withToken(self::MANAGER_TOKEN)
            ->postJson('/api/v3/seller/refund/refund-status-update', ['refund_request_id' => self::MISSING_ORDER_ID, 'refund_status' => 'rejected', 'note' => 'placeholder']);

        $response->assertStatus(404);
        $this->assertSame('refund', $response->json('errors.0.code'));
    }

    public function test_seller_api_staff_with_only_orders_view_is_refused_a_refund_decision(): void
    {
        $response = $this->withToken(self::VIEWER_TOKEN)
            ->postJson('/api/v3/seller/refund/refund-status-update', ['refund_request_id' => $this->refundRequestId(), 'refund_status' => 'rejected', 'note' => 'placeholder']);

        $this->assertPermissionRefusal($response);
        $this->assertSame('pending', DB::table('refund_requests')->where('id', $this->refundRequestId())->value('status'));
        $this->assertSame(0, DB::table('refund_statuses')->count());
    }

    public function test_seller_api_v3_refuses_a_suspended_sellers_token_before_any_permission_check(): void
    {
        DB::table('sellers')->where('id', self::SELLER_ID)->update(['status' => 'suspended']);

        $response = $this->withToken(self::OWNER_TOKEN)
            ->postJson('/api/v3/seller/refund/refund-status-update', ['refund_request_id' => $this->refundRequestId(), 'refund_status' => 'rejected', 'note' => 'placeholder']);

        $response->assertStatus(401);
        $this->assertArrayHasKey('auth-001', (array) $response->json());
        $this->assertSame('pending', DB::table('refund_requests')->where('id', $this->refundRequestId())->value('status'));
    }

    public function test_seller_api_v3_refuses_a_rejected_sellers_token_before_any_permission_check(): void
    {
        DB::table('sellers')->where('id', self::SELLER_ID)->update(['status' => 'rejected']);

        $response = $this->withToken(self::OWNER_TOKEN)
            ->postJson('/api/v3/seller/refund/refund-status-update', ['refund_request_id' => $this->refundRequestId(), 'refund_status' => 'rejected', 'note' => 'placeholder']);

        $response->assertStatus(401);
        $this->assertArrayHasKey('auth-001', (array) $response->json());
        $this->assertSame('pending', DB::table('refund_requests')->where('id', $this->refundRequestId())->value('status'));
    }

    /**
     * Admin suspension rotates the seller's auth_token, so a suspended seller's old token stops working. Rejection does not
     * rotate it, so the token a rejected seller already holds still decides refunds on the v2 seller API.
     */
    public function test_currently_the_v2_seller_api_lets_a_rejected_sellers_token_decide_a_refund(): void
    {
        DB::table('sellers')->where('id', self::SELLER_ID)->update(['status' => 'rejected']);

        $response = $this->withToken(self::OWNER_TOKEN)
            ->postJson('/api/v2/seller/refund/refund-status-update', ['refund_request_id' => $this->refundRequestId(), 'refund_status' => 'rejected', 'note' => 'placeholder']);

        $response->assertStatus(200);
        $this->assertSame('refund status updated successfully!', $response->json('message'));
        $this->assertSame('rejected', DB::table('refund_requests')->where('id', $this->refundRequestId())->value('status'));
        $this->assertSame(1, DB::table('refund_statuses')->where('change_by', 'seller')->count());
    }

    public function test_vendor_panel_staff_with_orders_manage_passes_the_gate_to_the_order_status_controller(): void
    {
        $response = $this->actingAs(Seller::findOrFail(self::SELLER_ID), 'seller')
            ->withSession(['seller_staff_id' => self::MANAGER_STAFF_ID])
            ->postJson('/vendor/orders/status', ['id' => self::MISSING_ORDER_ID, 'order_status' => 'canceled']);

        $response->assertStatus(404);
        $this->assertFalse($response->json('status'));
    }

    public function test_vendor_panel_staff_with_only_orders_view_is_refused_an_order_status_change(): void
    {
        $response = $this->actingAs(Seller::findOrFail(self::SELLER_ID), 'seller')
            ->withSession(['seller_staff_id' => self::VIEWER_STAFF_ID])
            ->postJson('/vendor/orders/status', ['id' => self::ORDER_ID, 'order_status' => 'canceled']);

        $response->assertStatus(403);
        $this->assertSame(translate('you_do_not_have_permission_for_this_action'), $response->json('message'));
        $this->assertSame('pending', DB::table('orders')->where('id', self::ORDER_ID)->value('order_status'));
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    public function test_admin_panel_admin_with_the_orders_module_passes_the_gate_to_the_order_status_controller(): void
    {
        $response = $this->actingAs(Admin::findOrFail(self::ORDERS_ADMIN_ID), 'admin')
            ->postJson('/admin/orders/status', ['id' => self::ORDER_ID, 'order_status' => 'canceled']);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('status'));
        $this->assertSame(translate('account_has_been_deleted_you_can_not_change_the_status'), $response->json('message'));
    }

    public function test_admin_panel_admin_without_the_orders_module_is_redirected_away_from_order_status_and_refund_decisions(): void
    {
        $admin = Admin::findOrFail(self::CATALOG_ADMIN_ID);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/orders/status', ['id' => self::ORDER_ID, 'order_status' => 'canceled'])
            ->assertRedirect(route('admin.dashboard.index'));

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/refund-section/refund/refund-status-update', ['id' => $this->refundRequestId(), 'refund_status' => 'rejected', 'rejected_note' => 'placeholder'])
            ->assertRedirect(route('admin.dashboard.index'));

        $this->assertSame('pending', DB::table('orders')->where('id', self::ORDER_ID)->value('order_status'));
        $this->assertSame('pending', DB::table('refund_requests')->where('id', $this->refundRequestId())->value('status'));
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    private function assertPermissionRefusal(TestResponse $response): void
    {
        $response->assertStatus(403);
        $this->assertSame([['code' => 'permission', 'message' => translate('your_role_does_not_allow_this')]], $response->json('errors'));
    }

    private function refundRequestId(): int
    {
        return (int) DB::table('refund_requests')->where('order_details_id', self::ORDER_DETAILS_ID)->value('id');
    }

    private function seedPrincipals(): void
    {
        DB::table('sellers')->insert([
            'id' => self::SELLER_ID, 'f_name' => 'Seller', 'status' => 'approved', 'auth_token' => self::OWNER_TOKEN, 'pos_status' => 1,
        ]);

        DB::table('seller_roles')->insert([
            ['id' => 21, 'seller_id' => self::SELLER_ID, 'name' => 'Order manager', 'permissions' => json_encode(['orders.view', 'orders.manage']), 'status' => 'active'],
            ['id' => 22, 'seller_id' => self::SELLER_ID, 'name' => 'Order viewer', 'permissions' => json_encode(['orders.view']), 'status' => 'active'],
        ]);
        DB::table('seller_staff')->insert([
            ['id' => self::MANAGER_STAFF_ID, 'seller_id' => self::SELLER_ID, 'seller_role_id' => 21, 'name' => 'Manager', 'auth_token' => self::MANAGER_TOKEN, 'status' => 'active'],
            ['id' => self::VIEWER_STAFF_ID, 'seller_id' => self::SELLER_ID, 'seller_role_id' => 22, 'name' => 'Viewer', 'auth_token' => self::VIEWER_TOKEN, 'status' => 'active'],
        ]);

        DB::table('admin_roles')->insert([
            ['id' => 2, 'name' => 'Orders', 'module_access' => json_encode(['orders']), 'status' => 1],
            ['id' => 3, 'name' => 'Catalog', 'module_access' => json_encode(['catalog']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => self::ORDERS_ADMIN_ID, 'name' => 'Orders admin', 'email' => 'orders@example.test', 'admin_role_id' => 2, 'status' => 1],
            ['id' => self::CATALOG_ADMIN_ID, 'name' => 'Catalog admin', 'email' => 'catalog@example.test', 'admin_role_id' => 3, 'status' => 1],
        ]);
    }

    /** The seller's own order, placed by a customer whose account no longer exists, with one line and a pending refund request on it. */
    private function insertOrderWithRefundRequest(): void
    {
        DB::table('orders')->insert([
            'id' => self::ORDER_ID, 'customer_id' => 7, 'is_guest' => 0, 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller',
            'order_status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery',
            'order_amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_details')->insert([
            'id' => self::ORDER_DETAILS_ID, 'order_id' => self::ORDER_ID, 'product_id' => 1, 'seller_id' => self::SELLER_ID,
            'product_details' => json_encode(['id' => 1, 'name' => 'Item', 'product_type' => 'physical', 'unit_price' => 100]),
            'variation' => '[]', 'qty' => 1, 'price' => 100, 'refund_request' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('refund_requests')->insert([
            'order_details_id' => self::ORDER_DETAILS_ID, 'order_id' => self::ORDER_ID, 'customer_id' => 7,
            'status' => 'pending', 'amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status')->default('pending');
            $table->string('auth_token')->nullable();
            $table->boolean('pos_status')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name');
            $table->text('permissions')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('active');
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->default(1);
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('edit_due_amount', 16, 2)->default(0);
            $table->decimal('edit_return_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->text('variation')->nullable();
            $table->integer('refund_request')->default(0);
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('change_by')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id');
            $table->string('change_by')->nullable();
            $table->unsignedBigInteger('change_by_id')->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }
}
