<?php

namespace Tests\Feature\Payment;

use App\Events\OrderEditDuePaymentEvent;
use App\Http\Controllers\Web\UserProfileController;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * POST /customer/customer-order-edit-pay-amount (Customer\PaymentController::customerOrderEditPayDueAmount) settles the amount an
 * order edit left due. A signed-in customer may settle only their own order, from any method including the wallet. A signed-out
 * caller may settle only the order this session opened on the tracking page with its phone, and never from a wallet. Anyone else
 * is refused before anything is written or notified.
 *
 * The store tables are INFERRED (the installation dump is not in the repository): they hold only the columns this endpoint touches.
 * No gateway is reached: the digital branch only writes a local payment_requests row, and outbound HTTP is refused.
 *
 * translate() appends any key it does not know to the tracked language files. Every request here runs under a locale that has no
 * language files, so translate() falls back to the spelled-out key and writes nothing; tearDown() proves the files did not change.
 */
class CustomerOrderEditPayDueAmountTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const ENDPOINT = '/customer/customer-order-edit-pay-amount';
    private const LOCALE_WITHOUT_LANGUAGE_FILES = 'no-language-files';
    private const ORDER_ID = 100001;
    private const OFFLINE_METHOD_ID = 7;
    private const DUE_AMOUNT = 40.0;
    private const OWNER_WALLET = 500.0;
    private const OTHER_WALLET = 300.0;

    /** @var array<string, string> */
    private array $languageFileDigests = [];

    private User $owner;

    private User $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->languageFileDigests = $this->digestsOfLanguageFiles();
        Http::preventStrayRequests();
        Event::fake([OrderEditDuePaymentEvent::class]);
        Exceptions::fake();
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class,
        ]);
        $this->withSession(['local' => self::LOCALE_WITHOUT_LANGUAGE_FILES]);

        $this->buildDuePaymentSchema();
        $this->owner = $this->makeCustomer(email: 'owner@example.test', walletBalance: self::OWNER_WALLET);
        $this->otherCustomer = $this->makeCustomer(email: 'other@example.test', walletBalance: self::OTHER_WALLET);
    }

    protected function tearDown(): void
    {
        try {
            $this->assertSame(
                $this->languageFileDigests,
                $this->digestsOfLanguageFiles(),
                'a file under resources/lang changed during the test: translate() wrote it, or another process edited the same working tree',
            );
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_stranger_cannot_record_an_offline_due_payment_on_someone_elses_order(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $this->payDue(fields: $this->offlinePaymentFields())->assertRedirect();

        $this->assertDuePaymentUntouched();
    }

    public function test_a_stranger_cannot_switch_someone_elses_order_to_cash_on_delivery(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $this->payDue(fields: ['payment_method' => 'cash_on_delivery', 'bring_change_amount_input' => '200'])->assertRedirect();

        $this->assertDuePaymentUntouched();
        $this->assertNull(DB::table('orders')->where('id', self::ORDER_ID)->value('bring_change_amount'));
    }

    public function test_a_stranger_naming_a_customer_id_from_the_app_cannot_spend_that_customers_wallet(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $response = $this->payDue(fields: [
            'payment_method' => 'wallet', 'payment_request_from' => 'app', 'customer_id' => $this->owner->id,
        ]);

        $this->assertWalletBalance(customer: $this->owner, expected: self::OWNER_WALLET);
        $this->assertDuePaymentUntouched();
        $response->assertOk()->assertJsonStructure(['errors' => [['code', 'message']]]);
    }

    public function test_a_stranger_posting_a_user_field_cannot_spend_that_customers_wallet(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $this->payDue(fields: [
            'payment_method' => 'wallet', 'user' => ['id' => $this->owner->id, 'wallet_balance' => self::DUE_AMOUNT],
        ])->assertRedirect();

        $this->assertWalletBalance(customer: $this->owner, expected: self::OWNER_WALLET);
        $this->assertDuePaymentUntouched();
    }

    public function test_a_stranger_from_the_app_cannot_record_an_offline_due_payment(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $response = $this->payDue(fields: $this->offlinePaymentFields() + [
            'payment_request_from' => 'app', 'customer_id' => $this->owner->id,
        ]);

        $this->assertDuePaymentUntouched();
        $response->assertOk()->assertJsonStructure(['errors' => [['code', 'message']]]);
    }

    public function test_a_signed_in_customer_cannot_pay_another_customers_due_from_their_own_wallet(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);
        $this->actingAs($this->otherCustomer, 'customer');

        $this->payDue(fields: ['payment_method' => 'wallet'])->assertRedirect();

        $this->assertWalletBalance(customer: $this->otherCustomer, expected: self::OTHER_WALLET);
        $this->assertWalletBalance(customer: $this->owner, expected: self::OWNER_WALLET);
        $this->assertDuePaymentUntouched();
    }

    public function test_a_signed_in_customer_cannot_record_an_offline_due_payment_on_another_customers_order(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);
        $this->actingAs($this->otherCustomer, 'customer');

        $this->payDue(fields: $this->offlinePaymentFields())->assertRedirect();

        $this->assertDuePaymentUntouched();
    }

    public function test_the_owner_pays_the_due_from_their_wallet(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);
        $this->actingAs($this->owner, 'customer');

        $this->payDue(fields: ['payment_method' => 'wallet'])->assertRedirect();

        $this->assertWalletBalance(customer: $this->owner, expected: self::OWNER_WALLET - self::DUE_AMOUNT);
        $order = DB::table('orders')->where('id', self::ORDER_ID)->first();
        $this->assertEquals(0, $order->edit_due_amount);
        $this->assertSame('paid', $order->payment_status);
        $history = DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->first();
        $this->assertSame('paid', $history->order_due_payment_status);
        $this->assertSame('wallet', $history->order_due_payment_method);
        Event::assertDispatched(OrderEditDuePaymentEvent::class);
    }

    public function test_the_owner_records_an_offline_due_payment(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);
        $this->actingAs($this->owner, 'customer');

        $this->payDue(fields: $this->offlinePaymentFields())->assertRedirect();

        $history = DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->first();
        $this->assertSame('offline_payment', $history->order_due_payment_method);
        $this->assertSame('TRX-OWNER-1', json_decode($history->order_due_payment_info, true)['transaction_reference']);
        $this->assertSame('unpaid', $history->order_due_payment_status);
        Event::assertDispatched(OrderEditDuePaymentEvent::class);
    }

    public function test_the_owner_switches_the_due_to_cash_on_delivery(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);
        $this->actingAs($this->owner, 'customer');

        $this->payDue(fields: ['payment_method' => 'cash_on_delivery', 'bring_change_amount_input' => '200'])->assertRedirect();

        $this->assertSame('cash_on_delivery', DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->value('order_due_payment_method'));
        $order = DB::table('orders')->where('id', self::ORDER_ID)->first();
        $this->assertEquals(200, $order->bring_change_amount);
        $this->assertSame('SAR', $order->bring_change_amount_currency);
        Event::assertDispatched(OrderEditDuePaymentEvent::class);
    }

    public function test_a_guest_order_is_refused_for_every_method_with_or_without_a_session(): void
    {
        $guestId = DB::table('guest_users')->insertGetId(['ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $this->makeEditedOrder(customerId: $guestId, isGuest: true);
        $methods = $this->everyMethod();

        foreach ($methods as $method => $fields) {
            $this->withSession(['guest_id' => $guestId])->payDue(fields: $fields)->assertRedirect();
            $this->assertDuePaymentUntouched(context: "guest session, {$method}");
        }

        $this->actingAs($this->otherCustomer, 'customer');
        foreach ($methods as $method => $fields) {
            $this->payDue(fields: $fields)->assertRedirect();
            $this->assertDuePaymentUntouched(context: "signed-in customer, {$method}");
        }
        $this->assertWalletBalance(customer: $this->otherCustomer, expected: self::OTHER_WALLET);
    }

    public function test_a_verified_tracking_session_records_an_offline_due_payment(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $this->withTrackingVerifiedFor(orderId: self::ORDER_ID)->payDue(fields: $this->offlinePaymentFields())->assertRedirect();

        $history = DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->first();
        $this->assertSame('offline_payment', $history->order_due_payment_method);
        $this->assertSame('TRX-OWNER-1', json_decode($history->order_due_payment_info, true)['transaction_reference']);
        Event::assertDispatched(OrderEditDuePaymentEvent::class);
    }

    public function test_a_verified_tracking_session_switches_the_due_to_cash_on_delivery(): void
    {
        $guestId = DB::table('guest_users')->insertGetId(['ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $this->makeEditedOrder(customerId: $guestId, isGuest: true);

        $this->withTrackingVerifiedFor(orderId: self::ORDER_ID)
            ->payDue(fields: ['payment_method' => 'cash_on_delivery', 'bring_change_amount_input' => '200'])->assertRedirect();

        $this->assertSame('cash_on_delivery', DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->value('order_due_payment_method'));
        $this->assertEquals(200, DB::table('orders')->where('id', self::ORDER_ID)->value('bring_change_amount'));
        Event::assertDispatched(OrderEditDuePaymentEvent::class);
    }

    public function test_a_verified_tracking_session_paying_digitally_only_opens_a_local_payment_request(): void
    {
        $guestId = DB::table('guest_users')->insertGetId(['ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $this->makeEditedOrder(customerId: $guestId, isGuest: true);

        $response = $this->withTrackingVerifiedFor(orderId: self::ORDER_ID)->withSession(['guest_id' => $guestId])
            ->payDue(fields: ['payment_method' => 'stripe']);

        $paymentRequest = DB::table('payment_requests')->sole();
        $response->assertRedirect(url("payment/stripe/pay/?payment_id={$paymentRequest->id}"));
        $this->assertSame('customer_order_edit_pay_due_amount', $paymentRequest->attribute);
        $this->assertSame(self::ORDER_ID, json_decode($paymentRequest->additional_data, true)['order_id']);
        $this->assertEquals(self::DUE_AMOUNT, $paymentRequest->payment_amount);
        $history = DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->first();
        $this->assertSame('unpaid', $history->order_due_payment_status);
        $this->assertNull($history->order_due_payment_method);
        Event::assertNotDispatched(OrderEditDuePaymentEvent::class);
    }

    public function test_a_signed_out_caller_without_the_tracking_marker_is_refused_for_every_method(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        foreach ($this->everyMethod() as $method => $fields) {
            $this->payDue(fields: $fields)->assertRedirect();
            $this->assertDuePaymentUntouched(context: $method);
        }
    }

    public function test_a_tracking_marker_for_another_order_is_refused_for_every_method(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        foreach ($this->everyMethod() as $method => $fields) {
            $this->withTrackingVerifiedFor(orderId: self::ORDER_ID + 1)->payDue(fields: $fields)->assertRedirect();
            $this->assertDuePaymentUntouched(context: $method);
        }
    }

    public function test_a_verified_tracking_session_cannot_pay_from_a_wallet(): void
    {
        $this->makeEditedOrder(customerId: $this->owner->id);

        $this->withTrackingVerifiedFor(orderId: self::ORDER_ID)->payDue(fields: ['payment_method' => 'wallet'])->assertRedirect();

        $response = $this->withTrackingVerifiedFor(orderId: self::ORDER_ID)->payDue(fields: [
            'payment_method' => 'wallet', 'payment_request_from' => 'app', 'customer_id' => $this->owner->id,
        ]);

        $response->assertOk()->assertJsonStructure(['errors' => [['code', 'message']]]);
        $this->assertWalletBalance(customer: $this->owner, expected: self::OWNER_WALLET);
        $this->assertDuePaymentUntouched();
    }

    private function withTrackingVerifiedFor(int $orderId): static
    {
        return $this->withSession([UserProfileController::VERIFIED_TRACKING_ORDER_SESSION_KEY => $orderId]);
    }

    /** @return array<string, array<string, mixed>> */
    private function everyMethod(): array
    {
        return [
            'wallet' => ['payment_method' => 'wallet'],
            'offline_payment' => $this->offlinePaymentFields(),
            'cash_on_delivery' => ['payment_method' => 'cash_on_delivery', 'bring_change_amount_input' => '200'],
            'digital' => ['payment_method' => 'stripe'],
        ];
    }

    /** @param array<string, mixed> $fields */
    private function payDue(array $fields): TestResponse
    {
        return $this->post(self::ENDPOINT, $fields + ['order_id' => self::ORDER_ID, 'payment_platform' => 'web']);
    }

    /** @return array<string, mixed> */
    private function offlinePaymentFields(): array
    {
        return [
            'payment_method' => 'offline_payment',
            'method_id' => self::OFFLINE_METHOD_ID,
            'payment_note' => 'paid by transfer',
            'transaction_reference' => 'TRX-OWNER-1',
        ];
    }

    private function assertDuePaymentUntouched(string $context = ''): void
    {
        $history = DB::table('order_edit_histories')->where('order_id', self::ORDER_ID)->first();
        $this->assertSame('unpaid', $history->order_due_payment_status, $context);
        $this->assertNull($history->order_due_payment_method, $context);
        $this->assertNull($history->order_due_payment_info, $context);

        $order = DB::table('orders')->where('id', self::ORDER_ID)->first();
        $this->assertEquals(self::DUE_AMOUNT, $order->edit_due_amount, $context);
        $this->assertSame('unpaid', $order->payment_status, $context);
        $this->assertNull($order->bring_change_amount, $context);

        $this->assertSame(0, DB::table('wallet_transactions')->count(), $context);
        $this->assertSame(0, DB::table('payment_requests')->count(), $context);
        Event::assertNotDispatched(OrderEditDuePaymentEvent::class);
    }

    private function assertWalletBalance(User $customer, float $expected): void
    {
        $this->assertEquals($expected, DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
    }

    private function makeCustomer(string $email, float $walletBalance): User
    {
        return User::unguarded(static fn () => User::create([
            'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => $email, 'phone' => '0551234567', 'is_active' => 1, 'wallet_balance' => $walletBalance,
        ]));
    }

    private function makeEditedOrder(int $customerId, bool $isGuest = false): void
    {
        DB::table('orders')->insert([
            'id' => self::ORDER_ID, 'customer_id' => $customerId, 'is_guest' => $isGuest, 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery',
            'order_amount' => 100, 'edit_due_amount' => self::DUE_AMOUNT, 'edit_return_amount' => 0, 'edited_status' => 1,
            'seller_is' => 'seller', 'seller_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_edit_histories')->insert([
            'order_id' => self::ORDER_ID, 'edit_by' => 'admin', 'order_amount' => 140, 'order_due_amount' => self::DUE_AMOUNT,
            'order_due_payment_status' => 'unpaid', 'order_return_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildDuePaymentSchema(): void
    {
        $this->buildCoreSchema();

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('edit_due_amount', 40, 20)->default(0);
            $table->decimal('edit_return_amount', 40, 20)->default(0);
            $table->boolean('edited_status')->default(false);
            $table->decimal('bring_change_amount', 40, 20)->nullable();
            $table->string('bring_change_amount_currency')->nullable();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('wallet_balance', 40, 20)->default(0);
            $table->decimal('loyalty_point', 40, 20)->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('u_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('edit_by')->nullable();
            $table->unsignedBigInteger('edited_user_id')->nullable();
            $table->string('edited_user_name')->nullable();
            $table->decimal('order_amount', 40, 20)->default(0);
            $table->decimal('order_due_amount', 40, 20)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->text('order_due_payment_info')->nullable();
            $table->string('order_due_transaction_ref')->nullable();
            $table->text('order_due_payment_note')->nullable();
            $table->decimal('order_return_amount', 40, 20)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->string('order_return_payment_method')->nullable();
            $table->text('order_return_payment_info')->nullable();
            $table->string('order_return_transaction_ref')->nullable();
            $table->text('order_return_payment_note')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id')->nullable();
            $table->decimal('credit', 40, 20)->default(0);
            $table->decimal('debit', 40, 20)->default(0);
            $table->decimal('admin_bonus', 40, 20)->default(0);
            $table->decimal('balance', 40, 20)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->decimal('pending_amount', 40, 20)->default(0);
            $table->timestamps();
        });
        Schema::create('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name');
            $table->text('method_fields')->nullable();
            $table->text('method_informations')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->text('additional_data')->nullable();
            $table->text('payer_information')->nullable();
            $table->text('receiver_information')->nullable();
            $table->text('external_redirect_link')->nullable();
            $table->string('attribute')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('payment_platform')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->string('transaction_id')->nullable();
            $table->timestamps();
        });

        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('sellers')->insert(['id' => 1, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_wallets')->insert(['admin_id' => 1, 'pending_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('offline_payment_methods')->insert([
            'id' => self::OFFLINE_METHOD_ID, 'method_name' => 'Bank transfer', 'status' => 1,
            'method_informations' => json_encode([['customer_input' => 'transaction_reference', 'customer_placeholder' => 'Reference', 'is_required' => 1]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['wallet_status' => 1, 'currency_model' => 'single_currency', 'system_default_currency' => 1, 'digital_payment' => json_encode(['status' => 1]),
            'company_name' => 'Lavkoo', 'company_web_logo' => 'logo.png',
        ] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::flush();
    }

    /** @return array<string, string> */
    private function digestsOfLanguageFiles(): array
    {
        $digests = [];
        foreach (glob(base_path('resources/lang/*/*.php')) ?: [] as $file) {
            $digests[$file] = (string) md5_file($file);
        }

        return $digests;
    }
}
