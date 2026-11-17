<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Address\ApiAddressTestCase;

/**
 * Paying the due amount of an edited order from the app (POST /api/v1/edit-order/due-payment-by-*). Only the order's own customer,
 * signed in with a bearer token, or the guest whose guest_id the order was placed under may change how the due amount is paid,
 * and only a signed-in customer may pay it from a wallet. Every other caller gets the order-not-found answer and nothing is written.
 */
class ApiDuePaymentOwnershipTest extends ApiAddressTestCase
{
    private const COD = '/api/v1/edit-order/due-payment-by-cod';
    private const OFFLINE = '/api/v1/edit-order/due-payment-by-offline-payment';
    private const WALLET = '/api/v1/edit-order/due-payment-by-wallet';
    private const DIGITAL = '/api/v1/edit-order/due-payment-by-digital-payment';

    private const CUSTOMER_ORDER = 100;
    private const GUEST_ORDER = 200;
    private const GUEST_ID = 77;
    private const DUE = 50;
    private const STARTING_BALANCE = 500;

    private User $owner;
    private User $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('edit_due_amount', 16, 2)->default(0);
            $table->decimal('edit_return_amount', 16, 2)->default(0);
            $table->boolean('edited_status')->default(false);
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('u_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->text('order_due_payment_info')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->text('order_due_payment_note')->nullable();
            $table->decimal('order_return_amount', 16, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        DB::table('admin_wallets')->insert(['admin_id' => 1, 'pending_amount' => 0]);
        DB::table('offline_payment_methods')->insert([
            'id' => 1, 'method_name' => 'Bank transfer', 'status' => 1,
            'method_informations' => json_encode([['customer_input' => 'transaction_number', 'customer_placeholder' => 'Number', 'is_required' => 1]]),
        ]);
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        Cache::flush();
        Cache::forever('company_web_logo', ['path' => 'logo.png']);

        $this->owner = $this->makeCustomer(['wallet_balance' => self::STARTING_BALANCE]);
        $this->otherCustomer = $this->makeCustomer(['f_name' => 'Noura', 'wallet_balance' => self::STARTING_BALANCE]);

        $this->placeEditedOrder(self::CUSTOMER_ORDER, customerId: $this->owner->id, isGuest: false);
        $this->placeEditedOrder(self::GUEST_ORDER, customerId: self::GUEST_ID, isGuest: true);
    }

    private function placeEditedOrder(int $orderId, int $customerId, bool $isGuest): void
    {
        $this->insertOrder($orderId, [
            'customer_id' => $customerId, 'is_guest' => $isGuest, 'order_amount' => 200, 'edit_due_amount' => self::DUE, 'edited_status' => 1,
            'payment_method' => 'cash_on_delivery', 'shipping_address_data' => json_encode(['contact_person_name' => 'Guest', 'phone' => '0550000000']),
        ]);
        DB::table('order_edit_histories')->insert([
            'u_id' => (string) Str::uuid(), 'order_id' => $orderId, 'order_amount' => 250, 'order_due_amount' => self::DUE,
            'order_due_payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function codRequest(int $orderId, array $extra = []): array
    {
        return $extra + ['order_id' => $orderId, 'payment_method' => 'cash_on_delivery', 'bring_change_amount' => '20'];
    }

    private function offlineRequest(int $orderId, array $extra = []): array
    {
        return $extra + [
            'order_id' => $orderId, 'payment_method' => 'offline_payment', 'method_id' => 1, 'order_due_payment_note' => 'paid by transfer',
            'method_informations' => base64_encode(json_encode(['transaction_number' => 'TX-1'])),
        ];
    }

    private function walletRequest(int $orderId, array $extra = []): array
    {
        return $extra + ['order_id' => $orderId, 'payment_method' => 'wallet'];
    }

    private function digitalRequest(int $orderId, array $extra = []): array
    {
        return $extra + [
            'order_id' => $orderId, 'payment_method' => 'my_fatoorah', 'payment_platform' => 'app', 'payment_request_from' => 'app',
            'current_currency_code' => 'SAR', 'order_due_payment_note' => '',
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function allEndpoints(): array
    {
        return [
            'cod' => [self::COD, 'codRequest'],
            'offline' => [self::OFFLINE, 'offlineRequest'],
            'wallet' => [self::WALLET, 'walletRequest'],
            'digital' => [self::DIGITAL, 'digitalRequest'],
        ];
    }

    private function assertRefused(TestResponse $response, string $label): void
    {
        $this->assertSame(401, $response->status(), "{$label}: expected the order-not-found refusal, got " . $response->getContent());
        $this->assertSame(['message' => translate('Order_not_found')], $response->json(), $label);
    }

    private function assertOrderUntouched(int $orderId, string $label): void
    {
        $history = DB::table('order_edit_histories')->where('order_id', $orderId)->first();
        $this->assertNull($history->order_due_payment_method, "{$label}: due-payment method was rewritten");
        $this->assertNull($history->order_due_payment_info, "{$label}: due-payment info was rewritten");
        $this->assertSame('unpaid', $history->order_due_payment_status, "{$label}: due payment was marked paid");

        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNull($order->bring_change_amount, "{$label}: order change amount was written");
        $this->assertSame('unpaid', $order->payment_status, "{$label}: order was marked paid");
        $this->assertEquals(self::DUE, $order->edit_due_amount, "{$label}: due amount was cleared");
    }

    private function assertNoMoneyMoved(string $label): void
    {
        $this->assertSame(0, DB::table('wallet_transactions')->count(), "{$label}: a wallet transaction was written");
        foreach ([$this->owner, $this->otherCustomer] as $customer) {
            $this->assertEquals(self::STARTING_BALANCE, DB::table('users')->where('id', $customer->id)->value('wallet_balance'), "{$label}: a wallet balance changed");
        }
        $this->assertEquals(0, DB::table('admin_wallets')->where('admin_id', 1)->value('pending_amount'), "{$label}: admin pending amount changed");
        $this->assertSame(0, DB::table('payment_requests')->count(), "{$label}: a payment request was created");
    }

    private function assertRandomGuestIsRefused(string $uri, string $builder, int $orderId, string $label): void
    {
        $this->assertRefused($this->postJson($uri, $this->{$builder}($orderId, ['guest_id' => '987654'])), $label);
        $this->assertOrderUntouched($orderId, $label);
    }

    public function test_h1_cod_a_random_guest_id_cannot_rewrite_a_customer_order(): void
    {
        $this->assertRandomGuestIsRefused(self::COD, 'codRequest', self::CUSTOMER_ORDER, 'h1 cod customer order');
    }

    public function test_h1_offline_a_random_guest_id_cannot_rewrite_a_customer_order(): void
    {
        $this->assertRandomGuestIsRefused(self::OFFLINE, 'offlineRequest', self::CUSTOMER_ORDER, 'h1 offline customer order');
    }

    public function test_h1_cod_a_random_guest_id_cannot_rewrite_another_guest_order(): void
    {
        $this->assertRandomGuestIsRefused(self::COD, 'codRequest', self::GUEST_ORDER, 'h1 cod guest order');
    }

    public function test_h1_offline_a_random_guest_id_cannot_rewrite_another_guest_order(): void
    {
        $this->assertRandomGuestIsRefused(self::OFFLINE, 'offlineRequest', self::GUEST_ORDER, 'h1 offline guest order');
    }

    public function test_h2_a_posted_user_field_does_not_spend_that_customer_wallet(): void
    {
        $postedIdentity = ['id' => $this->owner->id, 'wallet_balance' => 100000];

        $response = $this->postJson(self::WALLET, $this->walletRequest(self::CUSTOMER_ORDER, ['guest_id' => '987654', 'user' => $postedIdentity]));

        $this->assertRefused($response, 'wallet');
        $this->assertNoMoneyMoved('h2 wallet');
        $this->assertOrderUntouched(self::CUSTOMER_ORDER, 'h2 wallet');
    }

    public function test_h2_a_posted_user_field_does_not_create_a_payment_under_that_identity(): void
    {
        $postedIdentity = ['id' => $this->owner->id, 'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'x@example.test', 'phone' => '0551234567'];

        $response = $this->postJson(self::DIGITAL, $this->digitalRequest(self::CUSTOMER_ORDER, ['guest_id' => '987654', 'user' => $postedIdentity]));

        $this->assertRefused($response, 'digital');
        $this->assertNoMoneyMoved('h2 digital');
    }

    private function assertOtherCustomerIsRefused(string $uri, string $builder, string $label): void
    {
        $response = $this->asCustomer($this->otherCustomer)->postJson($uri, $this->{$builder}(self::CUSTOMER_ORDER));

        $this->assertRefused($response, $label);
        $this->assertOrderUntouched(self::CUSTOMER_ORDER, $label);
        $this->assertNoMoneyMoved($label);
    }

    public function test_h3_cod_a_signed_in_customer_cannot_act_on_another_customer_order(): void
    {
        $this->assertOtherCustomerIsRefused(self::COD, 'codRequest', 'h3 cod');
    }

    public function test_h3_offline_a_signed_in_customer_cannot_act_on_another_customer_order(): void
    {
        $this->assertOtherCustomerIsRefused(self::OFFLINE, 'offlineRequest', 'h3 offline');
    }

    public function test_h3_wallet_a_signed_in_customer_cannot_act_on_another_customer_order(): void
    {
        $this->assertOtherCustomerIsRefused(self::WALLET, 'walletRequest', 'h3 wallet');
    }

    public function test_h3_digital_a_signed_in_customer_cannot_act_on_another_customer_order(): void
    {
        $this->assertOtherCustomerIsRefused(self::DIGITAL, 'digitalRequest', 'h3 digital');
    }

    public function test_a_signed_in_customer_cannot_act_on_a_guest_order_whose_guest_id_equals_their_user_id(): void
    {
        $guestOrderUnderSameNumber = 300;
        $this->placeEditedOrder($guestOrderUnderSameNumber, customerId: $this->otherCustomer->id, isGuest: true);

        foreach ($this->allEndpoints() as $label => [$uri, $builder]) {
            $response = $this->asCustomer($this->otherCustomer)->postJson($uri, $this->{$builder}($guestOrderUnderSameNumber));
            $this->assertRefused($response, "signed-in on guest order {$label}");
        }

        $this->assertOrderUntouched($guestOrderUnderSameNumber, 'signed-in on guest order');
        $this->assertNoMoneyMoved('signed-in on guest order');
    }

    public function test_a_guest_id_equal_to_a_customer_id_does_not_open_that_customer_order(): void
    {
        $guestId = (string) $this->owner->id;

        $this->assertRefused($this->postJson(self::COD, $this->codRequest(self::CUSTOMER_ORDER, ['guest_id' => $guestId])), 'cod');
        $this->assertRefused($this->postJson(self::OFFLINE, $this->offlineRequest(self::CUSTOMER_ORDER, ['guest_id' => $guestId])), 'offline');
        $this->assertRefused($this->postJson(self::DIGITAL, $this->digitalRequest(self::CUSTOMER_ORDER, ['guest_id' => $guestId])), 'digital');

        $this->assertOrderUntouched(self::CUSTOMER_ORDER, 'guest id equal to customer id');
        $this->assertNoMoneyMoved('guest id equal to customer id');
    }

    public function test_the_owner_can_choose_cash_on_delivery(): void
    {
        $response = $this->asCustomer($this->owner)->postJson(self::COD, $this->codRequest(self::CUSTOMER_ORDER));

        $response->assertOk()->assertExactJson(['message' => translate('payment_method_updated')]);
        $this->assertSame('cash_on_delivery', DB::table('order_edit_histories')->where('order_id', self::CUSTOMER_ORDER)->value('order_due_payment_method'));
        $order = DB::table('orders')->where('id', self::CUSTOMER_ORDER)->first();
        $this->assertEquals(20, $order->bring_change_amount);
        $this->assertSame('SAR', $order->bring_change_amount_currency);
    }

    public function test_the_owner_can_choose_an_offline_payment(): void
    {
        $response = $this->asCustomer($this->owner)->postJson(self::OFFLINE, $this->offlineRequest(self::CUSTOMER_ORDER));

        $response->assertOk()->assertExactJson(['message' => translate('Payment_Method_Updated')]);
        $history = DB::table('order_edit_histories')->where('order_id', self::CUSTOMER_ORDER)->first();
        $this->assertSame('offline_payment', $history->order_due_payment_method);
        $this->assertSame('paid by transfer', $history->order_due_payment_note);
        $this->assertSame(['method_id' => 1, 'method_name' => 'Bank transfer', 'transaction_number' => 'TX-1'], json_decode($history->order_due_payment_info, true));
    }

    public function test_the_owner_can_pay_from_their_own_wallet(): void
    {
        $response = $this->asCustomer($this->owner)->postJson(self::WALLET, $this->walletRequest(self::CUSTOMER_ORDER));

        $response->assertOk()->assertExactJson(['message' => translate('Payment_Successful')]);
        $this->assertEquals(self::STARTING_BALANCE - self::DUE, DB::table('users')->where('id', $this->owner->id)->value('wallet_balance'));
        $this->assertEquals(self::STARTING_BALANCE, DB::table('users')->where('id', $this->otherCustomer->id)->value('wallet_balance'));
        $this->assertSame([$this->owner->id], DB::table('wallet_transactions')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $order = DB::table('orders')->where('id', self::CUSTOMER_ORDER)->first();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(0, $order->edit_due_amount);
    }

    public function test_the_owner_gets_a_payment_link_under_their_own_identity(): void
    {
        $response = $this->asCustomer($this->owner)->postJson(self::DIGITAL, $this->digitalRequest(self::CUSTOMER_ORDER, ['guest_id' => 'cash_on_delivery']));

        $response->assertOk();
        $this->assertSame(['redirect_link'], array_keys($response->json()));
        $payment = DB::table('payment_requests')->sole();
        $this->assertSame((string) $this->owner->id, (string) $payment->payer_id);
        $this->assertStringContainsString('payment_id=' . $payment->id, $response->json('redirect_link'));
        $this->assertSame(0, (int) json_decode($payment->additional_data, true)['is_guest']);
    }

    public function test_the_guest_who_placed_the_order_can_choose_cash_on_delivery_or_an_offline_payment(): void
    {
        $this->postJson(self::COD, $this->codRequest(self::GUEST_ORDER, ['guest_id' => (string) self::GUEST_ID]))
            ->assertOk()->assertExactJson(['message' => translate('payment_method_updated')]);
        $this->assertSame('cash_on_delivery', DB::table('order_edit_histories')->where('order_id', self::GUEST_ORDER)->value('order_due_payment_method'));

        $this->postJson(self::OFFLINE, $this->offlineRequest(self::GUEST_ORDER, ['guest_id' => (string) self::GUEST_ID]))
            ->assertOk()->assertExactJson(['message' => translate('Payment_Method_Updated')]);
        $this->assertSame('offline_payment', DB::table('order_edit_histories')->where('order_id', self::GUEST_ORDER)->value('order_due_payment_method'));
    }

    public function test_the_guest_who_placed_the_order_gets_a_guest_payment_link(): void
    {
        $response = $this->postJson(self::DIGITAL, $this->digitalRequest(self::GUEST_ORDER, ['guest_id' => (string) self::GUEST_ID]));

        $response->assertOk();
        $payment = DB::table('payment_requests')->sole();
        $this->assertSame((string) self::GUEST_ID, (string) $payment->payer_id);
        $this->assertSame(1, (int) json_decode($payment->additional_data, true)['is_guest']);
    }

    public function test_a_guest_cannot_pay_from_a_wallet_even_on_their_own_order(): void
    {
        $response = $this->postJson(self::WALLET, $this->walletRequest(self::GUEST_ORDER, [
            'guest_id' => (string) self::GUEST_ID, 'user' => ['id' => $this->owner->id, 'wallet_balance' => 100000],
        ]));

        $this->assertRefused($response, 'guest wallet');
        $this->assertNoMoneyMoved('guest wallet');
        $this->assertOrderUntouched(self::GUEST_ORDER, 'guest wallet');
    }

    public function test_a_wrong_or_malformed_guest_id_is_refused_without_writes(): void
    {
        foreach (['78', '77abc', '-77', '7.7e1', '0x4d'] as $guestId) {
            foreach ($this->allEndpoints() as $label => [$uri, $builder]) {
                $response = $this->postJson($uri, $this->{$builder}(self::GUEST_ORDER, ['guest_id' => $guestId]));
                $this->assertRefused($response, "guest_id '{$guestId}' {$label}");
            }
        }

        $this->assertOrderUntouched(self::GUEST_ORDER, 'wrong guest id');
        $this->assertNoMoneyMoved('wrong guest id');
    }

    public function test_a_request_with_neither_token_nor_guest_id_stays_unauthorised(): void
    {
        foreach ($this->allEndpoints() as $label => [$uri, $builder]) {
            $this->postJson($uri, $this->{$builder}(self::GUEST_ORDER))->assertStatus(401)->assertExactJson(['message' => 'Unauthorized']);
        }

        $this->assertOrderUntouched(self::GUEST_ORDER, 'no identity');
        $this->assertNoMoneyMoved('no identity');
    }
}
