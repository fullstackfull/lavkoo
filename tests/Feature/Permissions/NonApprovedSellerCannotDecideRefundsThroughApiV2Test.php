<?php

namespace Tests\Feature\Permissions;

use App\Events\OrderStatusEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The old seller app (API v2) must hold a seller to the same standing as v3: only an approved seller decides a refund.
 *
 * v2 resolved the bearer token against `sellers.auth_token` alone, so a seller approved at login and rejected or set back to pending afterwards
 * kept approving and rejecting customers' refunds until someone pressed suspend.
 */
class NonApprovedSellerCannotDecideRefundsThroughApiV2Test extends TestCase
{
    use RestoresLanguageFiles;

    private const SELLER_ID = 7;
    private const CUSTOMER_ID = 3;
    private const ORDER_ID = 100100;
    private const ORDER_DETAIL_ID = 11;
    private const REFUND_REQUEST_ID = 21;
    private const PENDING_REFUND = 1;
    private const STATUS_UPDATE_URI = '/api/v2/seller/refund/refund-status-update';
    private const SESSION_REFUSAL = ['auth-001' => 'Your existing session token does not authorize you any more'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildRefundSchema();
        $this->token = Str::random(64);
        $this->seedSellerWithPendingRefund(status: 'approved');
        Event::fake([OrderStatusEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_an_approved_seller_still_approves_a_refund_exactly_as_before(): void
    {
        $response = $this->decideRefund(token: $this->token, decision: 'approved');

        $response->assertStatus(200)->assertExactJson(['message' => 'refund status updated successfully!']);
        $refund = DB::table('refund_requests')->find(self::REFUND_REQUEST_ID);
        $this->assertSame(['approved', 'seller', 'goods damaged'], [$refund->status, $refund->change_by, $refund->approved_note]);
        $this->assertSame(2, (int) DB::table('order_details')->where('id', self::ORDER_DETAIL_ID)->value('refund_request'));
        $this->assertSame(1, DB::table('refund_statuses')->where(['refund_request_id' => self::REFUND_REQUEST_ID, 'status' => 'approved', 'change_by' => 'seller'])->count());
    }

    public function test_an_approved_seller_still_rejects_a_refund_and_the_customer_is_told(): void
    {
        $this->decideRefund(token: $this->token, decision: 'rejected')->assertStatus(200);

        $this->assertSame('rejected', DB::table('refund_requests')->where('id', self::REFUND_REQUEST_ID)->value('status'));
        $this->assertSame(3, (int) DB::table('order_details')->where('id', self::ORDER_DETAIL_ID)->value('refund_request'));
        Event::assertDispatched(OrderStatusEvent::class);
    }

    public function test_a_rejected_seller_is_refused_and_nothing_about_the_refund_changes(): void
    {
        $this->setSellerStatus(status: 'rejected');

        $this->assertRefusedWithoutAnyChange(token: $this->token);
    }

    public function test_a_seller_set_back_to_pending_is_refused_and_nothing_about_the_refund_changes(): void
    {
        $this->setSellerStatus(status: 'pending');

        $this->assertRefusedWithoutAnyChange(token: $this->token);
    }

    public function test_a_suspended_seller_is_refused_whether_or_not_the_token_was_rotated(): void
    {
        $this->setSellerStatus(status: 'suspended');
        $this->assertRefusedWithoutAnyChange(token: $this->token);

        DB::table('sellers')->where('id', self::SELLER_ID)->update(['auth_token' => Str::random(80)]);
        $this->assertRefusedWithoutAnyChange(token: $this->token);
    }

    public function test_an_unknown_or_malformed_token_is_refused(): void
    {
        $this->assertRefusedWithoutAnyChange(token: Str::random(64));
        $this->assertRefusedWithoutAnyChange(token: 'short');
        $this->withoutToken()->postJson(self::STATUS_UPDATE_URI, $this->decisionPayload(decision: 'rejected'))->assertStatus(401)->assertExactJson(self::SESSION_REFUSAL);
    }

    public function test_a_rejected_seller_cannot_read_the_refund_list_or_details_either(): void
    {
        $this->setSellerStatus(status: 'rejected');

        $this->withToken($this->token)->getJson('/api/v2/seller/refund/list')->assertStatus(401)->assertExactJson(self::SESSION_REFUSAL);
        $this->withToken($this->token)->getJson('/api/v2/seller/refund/refund-details?order_details_id=' . self::ORDER_DETAIL_ID)
            ->assertStatus(401)->assertExactJson(self::SESSION_REFUSAL);
    }

    public function test_the_same_rejected_token_is_refused_by_the_v3_refund_route_with_the_same_answer(): void
    {
        $this->setSellerStatus(status: 'rejected');

        $this->withToken($this->token)->postJson('/api/v3/seller/refund/refund-status-update', $this->decisionPayload(decision: 'approved'))
            ->assertStatus(401)->assertExactJson(self::SESSION_REFUSAL);
        $this->assertRefusedWithoutAnyChange(token: $this->token);
    }

    private function assertRefusedWithoutAnyChange(string $token): void
    {
        $before = $this->refundState();

        foreach (['approved', 'rejected', 'pending'] as $decision) {
            $this->decideRefund(token: $token, decision: $decision)->assertStatus(401)->assertExactJson(self::SESSION_REFUSAL);
        }

        $this->assertSame($before, $this->refundState());
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    private function decideRefund(string $token, string $decision): TestResponse
    {
        return $this->withToken($token)->postJson(self::STATUS_UPDATE_URI, $this->decisionPayload(decision: $decision));
    }

    /** @return array<string, mixed> */
    private function decisionPayload(string $decision): array
    {
        return ['refund_request_id' => self::REFUND_REQUEST_ID, 'refund_status' => $decision, 'note' => 'goods damaged'];
    }

    /** @return array<string, mixed> every row a refund decision could write, as stored */
    private function refundState(): array
    {
        $state = [];
        foreach (['refund_requests', 'refund_statuses', 'order_details', 'orders', 'seller_wallets', 'users'] as $table) {
            $state[$table] = DB::table($table)->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $state;
    }

    private function setSellerStatus(string $status): void
    {
        DB::table('sellers')->where('id', self::SELLER_ID)->update(['status' => $status]);
    }

    private function seedSellerWithPendingRefund(string $status): void
    {
        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'Vendor', 'email' => 'vendor@example.test', 'status' => $status, 'auth_token' => $this->token]);
        DB::table('seller_wallets')->insert(['seller_id' => self::SELLER_ID, 'total_earning' => 500, 'withdrawn' => 0]);
        DB::table('users')->insert(['id' => self::CUSTOMER_ID, 'f_name' => 'Customer', 'loyalty_point' => 0]);
        DB::table('orders')->insert(['id' => self::ORDER_ID, 'customer_id' => self::CUSTOMER_ID, 'seller_is' => 'seller', 'seller_id' => self::SELLER_ID, 'order_amount' => 120, 'order_status' => 'delivered']);
        DB::table('order_details')->insert(['id' => self::ORDER_DETAIL_ID, 'order_id' => self::ORDER_ID, 'seller_id' => self::SELLER_ID, 'qty' => 1, 'price' => 120, 'refund_request' => self::PENDING_REFUND]);
        DB::table('refund_requests')->insert([
            'id' => self::REFUND_REQUEST_ID, 'order_details_id' => self::ORDER_DETAIL_ID, 'customer_id' => self::CUSTOMER_ID, 'order_id' => self::ORDER_ID,
            'status' => 'pending', 'amount' => 120, 'refund_reason' => 'broken', 'change_by' => 'customer',
        ]);
    }

    /** The columns the refund decision reads and writes; the real tables come from the installation dump, which is not in the repository. */
    private function buildRefundSchema(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
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
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->string('auth_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 24, 3)->default(0);
            $table->decimal('withdrawn', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->decimal('loyalty_point', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('order_status')->default('pending');
            $table->decimal('order_amount', 40, 20)->default(0);
            $table->decimal('discount_amount', 40, 20)->default(0);
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->decimal('tax', 40, 20)->default(0);
            $table->integer('refund_request')->default(0);
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('status');
            $table->decimal('amount', 24, 3)->default(0);
            $table->text('refund_reason')->nullable();
            $table->text('images')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('change_by')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id');
            $table->string('change_by')->nullable();
            $table->string('change_by_id')->nullable();
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }
}
